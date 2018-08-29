<?php
/**
 * Checkout.com Magento 2 Payment module (https://www.checkout.com)
 *
 * Copyright (c) 2017 Checkout.com (https://www.checkout.com)
 * Author: David Fiaty | integration@checkout.com
 *
 * License GNU/GPL V3 https://www.gnu.org/licenses/gpl-3.0.en.html
 */

namespace CheckoutCom\Magento2\Model\Service;

use DomainException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use Magento\Customer\Model\CustomerFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Api\RefundInvoiceInterface;
use CheckoutCom\Magento2\Model\Adapter\CallbackEventAdapter;
use CheckoutCom\Magento2\Model\Adapter\ChargeAmountAdapter;
use CheckoutCom\Magento2\Gateway\Config\Config;
use CheckoutCom\Magento2\Model\Service\StoreCardService;
use CheckoutCom\Magento2\Model\Service\OrderHandlerService;
use CheckoutCom\Magento2\Model\Service\TransactionHandlerService;
use CheckoutCom\Magento2\Model\Service\InvoiceHandlerService;

class WebhookCallbackService {

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var Config
     */
    protected $Config;

    /**
     * @var StoreCardService
     */
    protected $storeCardService;

    /**
     * @var CustomerFactory
     */
    protected $customerFactory;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var OrderSender
     */
    protected $orderSender;

    /**
     * @var OrderHandlerService
     */
    protected $orderService;

    /**
     * @var TransactionHandlerService
     */
    protected $transactionService;

    /**
     * @var InvoiceHandlerService
     */
    protected $invoiceService;

    /**
     * @var CollectionFactory
     */
    protected $quoteCollectionFactory;

    /**
     * @var RefundInvoiceInterface
     */
    protected $invoiceRefunder;

    /**
     * CallbackService constructor.
     */
    public function __construct(
        OrderFactory $orderFactory,
        OrderRepositoryInterface $orderRepository,
        Config $config,
        StoreCardService $storeCardService,
        CustomerFactory $customerFactory,
        StoreManagerInterface $storeManager,
        OrderSender $orderSender,
        OrderHandlerService $orderService,
        TransactionHandlerService $transactionService,    
        InvoiceHandlerService $invoiceService,
        CollectionFactory $quoteCollectionFactory,
        RefundInvoiceInterface $invoiceRefunder
    ) {
        $this->orderFactory            = $orderFactory;
        $this->orderRepository         = $orderRepository;
        $this->config                  = $config;
        $this->storeCardService        = $storeCardService;
        $this->customerFactory         = $customerFactory;
        $this->storeManager            = $storeManager;
        $this->orderSender             = $orderSender;
        $this->orderService            = $orderService;
        $this->transactionService      = $transactionService;
        $this->invoiceService          = $invoiceService;
        $this->quoteCollectionFactory  = $quoteCollectionFactory;
        $this->invoiceRefunder         = $invoiceRefunder;
    }

    /**
     * Runs the service.
     *
     * @throws DomainException
     * @throws LocalizedException
     */
    public function run($response) {
        // Set the gateway response
        $this->gatewayResponse = $response;

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/blacklist.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $logger->info(print_r($this->gatewayResponse,1));

        // Extract the response info
        $eventName = $this->getEventName();
        $amount = $this->getAmount();

        // Get the order
        $order = $this->getAssociatedOrder();
        if ($order) {
            // Get the payment information
            $payment = $order->getPayment();

            // Get override comments setting from config
            $overrideComments = $this->config->overrideOrderComments();

            // Process the payment
            if ($payment instanceof Payment) {
                // Perform refund complementary actions
                if ($eventName == 'charge.refunded') {
                    // Get the invoices
                    foreach ($order->getInvoiceCollection() as $invoice) {
                        // Refund from invoice
                        $this->invoiceRefunder->execute($invoice->getId(), [], false);                        

                        // Create the refund transaction
                        $order = $this->transactionService->createTransaction(
                            $order,
                            array('transactionReference' => $this->gatewayResponse['message']['id']),
                            Transaction::TYPE_REFUND,
                            $this->gatewayResponse['message']['originalId']
                        );
                    }

                    // Close the order
                    $order->setState(Order::STATE_CLOSED)->setStatus(Order::STATE_CLOSED);
                }

                // Perform void complementary actions
                elseif ($eventName == 'charge.voided') {
                    // Create the void transaction
                    $order = $this->transactionService->createTransaction(
                        $order,
                        array('transactionReference' => $this->gatewayResponse['message']['id']),
                        Transaction::TYPE_VOID,
                        $this->gatewayResponse['message']['originalId']
                    );

                    // Close the order
                    $order->setState(Order::STATE_CLOSED)->setStatus(Order::STATE_CLOSED);
                }

                // Perform authorize complementary actions
                elseif ($eventName == 'charge.succeeded') {
                    // Update order status
                    $order->setStatus($this->config->getOrderStatusAuthorized());

                    // Send the email
                    $this->orderSender->send($order);
                    $order->setEmailSent(1);

                    // Comments override
                    if ($overrideComments) {
                        // Delete comments history
                        foreach ($order->getAllStatusHistory() as $orderComment) {
                            $orderComment->delete();
                        }
                    }

                    // Add authorization comment
                    $order = $this->addAuthorizationComment($order);

                    // Create the authorization transaction
                    $order = $this->transactionService->createTransaction(
                        $order,
                        array('transactionReference' => $this->gatewayResponse['message']['id']),
                        Transaction::TYPE_AUTH
                    );
                }

                // Perform capture complementary actions
                elseif ($eventName == 'charge.captured') {
                    // Update order status
                    $order->setStatus($this->config->getOrderStatusCaptured());

                    // Add capture comment
                    $order = $this->addCaptureComment($order);

                    // Create the capture transaction
                    $order = $this->transactionService->createTransaction(
                        $order,
                        array('transactionReference' => $this->gatewayResponse['message']['id']),
                        Transaction::TYPE_CAPTURE
                    );

                    // Generate invoice if needed
                    if ($this->config->getAutoGenerateInvoice() === true) {
                        // Prepare the amount
                        $amount = ChargeAmountAdapter::getStoreAmountOfCurrency(
                            $this->gatewayResponse['message']['value'],
                            $this->gatewayResponse['message']['currency']
                        );

                        // Create the invoice
                        $invoice = $this->invoiceService->processInvoice($order, $amount);
                    }
                }

                // Save the order
                $this->orderRepository->save($order);
            }

            return $eventName;
        }
    }

    private function addAuthorizationComment($order) {
        // Create new comment
        $newComment  = '';
        $newComment .= __('Authorized amount of') . ' ';
        $newComment .= ChargeAmountAdapter::getStoreAmountOfCurrency(
            $this->gatewayResponse['message']['value'], 
            $this->gatewayResponse['message']['currency']
        );
        $newComment .= ' ' . $this->gatewayResponse['message']['currency'];
        $newComment .= ' ' . __('Transaction ID') . ':' . ' ';
        $newComment .= $this->gatewayResponse['message']['id'];

        // Add the new comment
        $order->addStatusToHistory($order->getStatus(), $newComment, $notify = true);

        return $order;
    }

    private function addCaptureComment($order) {
        // Create new comment
        $newComment  = '';
        $newComment .= __('Captured amount of') . ' ';
        $newComment .= ChargeAmountAdapter::getStoreAmountOfCurrency(
            $this->gatewayResponse['message']['value'], 
            $this->gatewayResponse['message']['currency']
        );
        $newComment .= ' ' . $this->gatewayResponse['message']['currency'];
        $newComment .= ' ' . __('Transaction ID') . ':' . ' ';
        $newComment .= $this->gatewayResponse['message']['id'];

        // Add the new comment
        $order->addStatusToHistory($order->getStatus(), $newComment, $notify = true);

        return $order;
    }

    /**
     * Returns the order instance.
     *
     * @return \Magento\Sales\Model\Order
     * @throws DomainException
     */
    private function getAssociatedOrder() {
        // Prepare variables
        if (isset($this->gatewayResponse['message']['trackId'])) {
            $trackId    = $this->gatewayResponse['message']['trackId'];
            $order      = $this->orderFactory->create()->loadByIncrementId($trackId);

            return !$order->isEmpty() ? $order : null;
        }

        return null;

        // If the order doesn't exist yet, create from quote
        // todo - test this use case
        /*if ($order->isEmpty()) {
        
            // Get the quote from track id
            $quoteCollection = $this->quoteCollectionFactory->create()
            ->addFieldToFilter('reserved_order_id', $trackId);
            
            // Create the new order from quote
            if (count($quoteCollection) == 1) {
                $orderId = $this->orderService->createNewOrder($quoteCollection[0]);
                $order   = $this->orderFactory->create()->loadByAttribute('order_id', $orderId);
            }
        }*/
    }

    /**
     * Returns the command name.
     *
     * @return null|string
     */
    private function getEventName() {
        return $this->gatewayResponse['eventType'];
    }

    /**
     * Returns the amount for the store.
     *
     * @return float
     */
    private function getAmount() {
        return ChargeAmountAdapter::getStoreAmountOfCurrency(
            $this->gatewayResponse['message']['value'],
            $this->gatewayResponse['message']['currency']
        );
    }
}