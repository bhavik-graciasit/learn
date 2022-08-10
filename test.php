<?php
namespace Graciasit\MobileRecharge\Observer;

use Magento\Framework\Event\ObserverInterface;
use \Psr\Log\LoggerInterface;
use Magento\Sales\Api\InvoiceManagementInterface;

class ReceiptNumber implements ObserverInterface{

    protected $logger;
    protected $_helper;
    protected $_scopeConfig;
    private $orderRepository;
    protected $productRepository;
    private $invoiceRepository;
    private $invoiceModel;
    private $invoiceService;

    public function __construct(
        LoggerInterface $logger,
        \Graciasit\MobileRecharge\Helper\Data $helper,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Catalog\Model\ProductRepository $productRepository,
        \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository,
        \Magento\Sales\Model\Order\Invoice $invoiceModel,
        InvoiceManagementInterface $invoiceService
    )
    {
        $this->logger = $logger;
        $this->_helper = $helper;
        $this->_scopeConfig = $scopeConfig;
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->invoiceModel = $invoiceModel;
        $this->invoiceService = $invoiceService;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $domain = $this->_helper->getConfigValue('graciasit_mobile_recharge/graciasit_mobile_recharge_settings/domain');
        $port = $this->_helper->getConfigValue('graciasit_mobile_recharge/graciasit_mobile_recharge_settings/port');
        $agentKey = $this->_helper->getConfigValue('graciasit_mobile_recharge/graciasit_mobile_recharge_settings/agent_key');
        $utilityBillDomain = $this->_helper->getConfigValue('graciasit_mobile_recharge/utility_bill_settings/utility_bill_domain');
        $utilityBillToken = $this->_helper->getConfigValue('graciasit_mobile_recharge/utility_bill_settings/utility_bill_token');
        $sourceSystemCode = $this->_helper->getConfigValue('graciasit_mobile_recharge/utility_bill_settings/source_system_code');
        $order = $observer->getEvent()->getOrder();
        $orderData = $order->getData();
        $orderId = $orderData['entity_id'];

        $_order = $this->orderRepository->get($orderId);

        $customerName = $_order->getCustomerFirstname().' '.$_order->getCustomerLastname();
        $phoneNo = $_order->getBillingAddress()->getTelephone();
        $countryId = $_order->getBillingAddress()->getCountryId();

        $orderStatusProgress = 'Success';
        $utilityOrderStatusProgress = 'Success';
        $mobileRechargeProductFlag = '';
        $utilityBillProductFlag = '';
        $orderAirtimeComment = '';
        foreach ($_order->getAllVisibleItems() as $_item)
        {
            $productType = $_item->getProductType();
            $productId = $_item->getProductId();
            $qty = $_item->getQtyOrdered();
            //$productSku = $_item->getSku();
            $_product = $this->productRepository->getById($productId);
            $productSku = $_product->getSku();
            $attribute_code_mobile_recharge = 'mobile_recharge_product';
            $mobileRechargeProduct = $_product->getResource()->getAttribute($attribute_code_mobile_recharge)->getFrontend()->getValue($_product);
            $attribute_code_utility_bill = 'utility_bill_product';
            $utilityBillProduct = $_product->getResource()->getAttribute($attribute_code_utility_bill)->getFrontend()->getValue($_product);
            $_options = $this->getSelectedOptions($_item);
            $rechargeData = $this->getRechargeProductCustomOption($productId, $_options);
            $utilityData = $this->getUtilityBillProductCustomOption($productId, $_options);

            if($productType == 'virtual')
            {
                $agentReference = $this->_helper->getAgentReference();
                if($mobileRechargeProduct == 'Yes')
                {
                    $mobileRechargeProductFlag = 1;
                    //$agentReference = $this->getAgentReference();
                    if(!empty($rechargeData['category']) && !empty($rechargeData['name']))
                    {
                        $this->logger->info('Bundle Recharge Executed');
                        $rechargeResponse = $this->executeBundleRecharge($domain,$port,$agentKey,$agentReference,$productSku,$rechargeData,$qty);

                        // added below line as they are not sending any response in success so transaction lookup is not possible we have declared order status success
                        $orderStatusProgress .= '_Success';
                    }
                    else
                    {
                        $this->logger->info('Custom Recharge Executed');
                        $rechargeResponse = $this->executeCustomRecharge($domain,$port,$agentKey,$agentReference,$rechargeData,$qty);
                    }

                    $this->logger->info('Recharge Response', $rechargeResponse);

                    if(isset($rechargeResponse['agentReference']) && !empty($rechargeResponse['agentReference']))
                    {
                        $this->logger->info('Transection Lookup Executed');
                        $transectionLookUp = $this->executeTransectionLookUp($domain,$port,$agentKey,$rechargeResponse['agentReference']);
                        $this->logger->info('Transection Lookup Response',$transectionLookUp);

                        $this->logger->info('Airtime Lookup Executed');
                        $airtimeLookUp = $this->executeAirtimeLookUp($domain,$port,$rechargeResponse['agentReference']);
                        $this->logger->info('Airtime Lookup Response',$airtimeLookUp);

                        if(isset($airtimeLookUp['statusMessage']) && !empty($airtimeLookUp['statusMessage']))
                        {
                            $this->logger->info('New Order comment generated');
                            $orderAirtimeComment .= $airtimeLookUp['statusMessage'].' Reference ID # '.$airtimeLookUp['agentReference'].' | ';
                        }

                        if($transectionLookUp['funding'] == 1 && $transectionLookUp['responseCode'] == 0)
                        {
                            $orderStatusProgress .= '_Success';
                        }
                        else
                        {
                            $orderStatusProgress .= '_Cancel';
                        }
                    }
                    else
                    {
                        $orderStatusProgress .= '_Cancel';
                    }
                }
                if($utilityBillProduct == 'Yes')
                {
                    $utilityBillProductFlag = 1;
                    $receiptNumber = $_item->getReceiptNo();
                    if(isset($receiptNumber) && !empty($receiptNumber))
                    {
                        $utilityOrderStatusProgress .= '_Success';
                    }
                    else
                    {
                        $utilityOrderStatusProgress .= '_Cancel';
                    }
                }
            }
        }

        if($orderStatusProgress != '' && $mobileRechargeProductFlag == '1')
        {
            $this->logger->info($orderStatusProgress);
            $orderStatusArr = explode('_',$orderStatusProgress);
            if(isset($orderStatusArr) && count($orderStatusArr)>0)
            {
                if(in_array('Cancel',$orderStatusArr))
                {
                    $this->logger->info('Order Status Change');
                    $_order->setState('complete');
                    $_order->setStatus('recharge_failed');
                    $_order->save();
                }
            }

            $this->logger->info($orderAirtimeComment);

            $_order->addStatusHistoryComment(
                __($orderAirtimeComment)
            )
                ->setIsCustomerNotified(true)
                ->save();
        }

        if($utilityOrderStatusProgress != '' && $utilityBillProductFlag == '1')
        {
            //$this->logger->info($utilityOrderStatusProgress);
            $utilityOrderStatusArr = explode('_',$utilityOrderStatusProgress);
            if(isset($utilityOrderStatusArr) && count($utilityOrderStatusArr)>0)
            {
                if(in_array('Cancel',$utilityOrderStatusArr))
                {
                    //$this->logger->info('Order Status Change to fail');
                    $_order->setState('new');
                    $_order->setStatus('receipt_not_generated');
                    $_order->save();
                }
                else
                {
                    //$this->logger->info('Order Status Change to complete');
                    $_order->setState('complete');
                    $_order->setStatus('complete');
                    $_order->save();
                }
            }
        }

    }

    public function getSelectedOptions($item){
        $result = [];
        $options = $item->getProductOptions();
        if ($options) {
            if (isset($options['options'])) {
                $result = array_merge($result, $options['options']);
            }
            if (isset($options['additional_options'])) {
                $result = array_merge($result, $options['additional_options']);
            }
            if (isset($options['attributes_info'])) {
                $result = array_merge($result, $options['attributes_info']);
            }
        }
        return $result;
    }

    public function executeCustomRecharge($domain,$port,$agentKey,$agentReference,$rechargeData,$qty)
    {
        $postAPIData = array(
            'phoneNumber' => $rechargeData['account'],
            'amount' => $rechargeData['amount']*$qty,
            'agentKey' => $agentKey,
            'agentRefence' => $agentReference
        );

        //$this->logger->info('Custom Recharge',$postAPIData);

        $APIURL = $domain.':'.$port.'/clicknpay/agents/v1/agent-airtime/purchase/load';
        $ch = curl_init($APIURL);
        $payload = json_encode($postAPIData);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        $resultData = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($resultData, true);

        // added below line as they are not sending any response in success
        $result['agentReference'] = $agentReference;
        return $result;
    }

    public function executeBundleRecharge($domain,$port,$agentKey,$agentReference,$productSku,$rechargeData,$qty)
    {
        $bundleCategoryArr = explode('#',$rechargeData['category']);
        $bundleArr = explode('#',$rechargeData['name']);
        $postAPIData = array(
            'agentKey' => $agentKey,
            'agentRefence' => $agentReference,
            'airtimeType' => strtoupper($productSku),
            'amount' => $rechargeData['amount']*$qty,
            //'amount' => 1,
            'bundleCategory' => $bundleCategoryArr[1],
            'ecocashNumber' => '',
            'phoneNumber' => $rechargeData['account'],
            'specifiedBundle' => $bundleArr[1]
        );

        $APIURL = $domain.':'.$port.'/clicknpay/agents/v1/agent/purchase/load';
        $ch = curl_init($APIURL);
        $payload = json_encode($postAPIData);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        $resultData = curl_exec($ch);
        $curl_info = curl_getinfo($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);
        $result = json_decode($resultData, true);

        // added below line as they are not sending any response in success
        $result['agentReference'] = $agentReference;
        return $result;
    }

    public function executeTransectionLookUp($domain,$port,$agentKey,$agentReference)
    {
        $postAPIData = array(
            'agentKey' => $agentKey,
            'agentReference' => $agentReference
        );

        $APIURL = $domain.':'.$port.'/clicknpay/agents/v1/agent-look-up/enquiry';
        $ch = curl_init($APIURL);
        $payload = json_encode($postAPIData);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        $resultData = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($resultData, true);
        return $result;
    }

    public function executeAirtimeLookUp($domain,$port,$agentReference)
    {
        $APIURL = 'https://ticketing.clicknpay.africa:2031/clicknpay/v1/mobile/get/airtime-lookup/'.$agentReference;
        $ch = curl_init($APIURL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        $resultData = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($resultData, true);
        return $result;
    }

    public function executeUtilityBill($utilityBillDomain,$utilityBillToken,$sourceSystemCode,$agentReference,$customerName,$phoneNo,$productSku,$utilityData,$qty)
    {
        $postAPIData = array(
            'accountNo' => $utilityData['account'],
            'billerId' => $productSku,
            'corelationId' => $agentReference,
            'customerCellNo' => $phoneNo,
            'customerName' => $customerName,
            'productId' => '',
            'sourceSystemCode' => $sourceSystemCode,
            'zwdPrice' => $utilityData['amount']*$qty,
        );

        $APIURL = $utilityBillDomain.'/crowdfundingservice/billers/postPayment';
        $ch = curl_init($APIURL);
        $payload = json_encode($postAPIData);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type:application/json",
            "Authorization: Bearer $utilityBillToken"
        ));
        $resultData = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($resultData, true);
        return $result;
    }

    public function getRechargeProductCustomOption($productId,$_options)
    {
        $product = $this->productRepository->getById($productId);
        $productOption = $product->getOptions();

        $account = '';
        $accountOptionID = '';
        $amount = '';
        $amountOptionID = '';
        $category = '';
        $categoryOptionID = '';
        $name = '';
        $nameOptionID = '';

        foreach($productOption as $op)
        {
            $customOptionData = $op->getData();
            if($customOptionData['sku'] == 'account')
            {
                $accountOptionID = $customOptionData['option_id'];
            }
            if($customOptionData['sku'] == 'amount')
            {
                $amountOptionID = $customOptionData['option_id'];
            }
            if($customOptionData['sku'] == 'category')
            {
                $categoryOptionID = $customOptionData['option_id'];
            }
            if($customOptionData['sku'] == 'name')
            {
                $nameOptionID = $customOptionData['option_id'];
            }
        }

        if(isset($_options) && count($_options)>0)
        {
            foreach ($_options as $key=>$val)
            {
                if($val['option_id'] == $accountOptionID)
                {
                    $account = $val['value'];
                }
                if($val['option_id'] == $amountOptionID)
                {
                    $amount = $val['value'];
                }
                if($val['option_id'] == $categoryOptionID)
                {
                    $category = $val['value'];
                }
                if($val['option_id'] == $nameOptionID)
                {
                    $name = $val['value'];
                }
            }
        }

        $tempArr = array(
            'account' => $account,
            'amount' => $amount,
            'category' => $category,
            'name' => $name
        );

        return $tempArr;
    }

    public function getUtilityBillProductCustomOption($productId,$_options)
    {
        $product = $this->productRepository->getById($productId);
        $productOption = $product->getOptions();

        $amount = '';
        $account = '';
        $amountOptionID = '';
        $accountOptionID = '';
        foreach($productOption as $op)
        {
            $customOptionData = $op->getData();
            if($customOptionData['sku'] == 'amount')
            {
                $amountOptionID = $customOptionData['option_id'];
            }
            if($customOptionData['sku'] == 'account')
            {
                $accountOptionID = $customOptionData['option_id'];
            }
        }

        if(isset($_options) && count($_options)>0)
        {
            foreach ($_options as $key=>$val)
            {
                if($val['option_id'] == $amountOptionID)
                {
                    $amount = $val['value'];
                }
                if($val['option_id'] == $accountOptionID)
                {
                    $account = $val['value'];
                }
            }
        }

        $tempArr = array(
            'amount' => $amount,
            'account' => $account
        );

        return $tempArr;
    }
}
?>