<?php

namespace CheckoutCom\Magento2\Controller\Apm;

class Display extends \Magento\Framework\App\Action\Action {

	/**
     * @var Context
     */
    protected $context; 

    /**
     * @var PageFactory
     */
    protected $pageFactory;  
   
    /**
     * @var JsonFactory
     */
    protected $jsonFactory;

    /**
     * @var Config
     */
    protected $config;

    /**
     * Display constructor
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $pageFactory,
        \Magento\Framework\Controller\Result\JsonFactory $jsonFactory,
        \CheckoutCom\Magento2\Gateway\Config\Config $config
    ) {
        parent::__construct($context);

        $this->pageFactory = $pageFactory;
        $this->jsonFactory = $jsonFactory;
        $this->config = $config;
    }

    /**
     * Handles the controller method.
     */
    public function execute() {
        $html = '';
        if ($this->getRequest()->isAjax()) {
            // Get the list of APM
            $apmEnabled = explode(',', 
                $this->config->getValue('apm_enabled', 'checkoutcom_apm')
            );

            // Load block data for each APM
            if (count($apmEnabled) > 0) {
                foreach ($apmEnabled as $index => $apmId) {
                    $html .= $this->loadBlock($index, $apmId);
                }
            }
        }

        return $this->jsonFactory->create()->setData(
            ['html' => $html]
        );
    }

    private function loadBlock($tabIndex, $apmId)
    {
        return $this->pageFactory->create()->getLayout()
        ->createBlock('CheckoutCom\Magento2\Block\Apm\Form')
        ->setTemplate('CheckoutCom_Magento2::payment/apm/' . $apmId . '.phtml')
        ->setData('tabIndex', $tabIndex)
        ->setData('apm_id', $apmId)
        ->toHtml();
    }
}