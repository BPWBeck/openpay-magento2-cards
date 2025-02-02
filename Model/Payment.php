<?php

/**
 * Openpay_Cards payment method model
 *
 * @category    Openpay
 * @package     Openpay_Cards
 * @author      Federico Balderas
 * @copyright   Openpay (http://openpay.mx)
 * @license     http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0
 */

namespace Openpay\Cards\Model;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Payment\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Directory\Model\CountryFactory;
use Magento\Store\Model\StoreManagerInterface;

use Magento\Customer\Model\Customer;
use Magento\Customer\Model\Session as CustomerSession;

class Payment extends \Magento\Payment\Model\Method\Cc
{

    const CODE = 'openpay_cards';

    protected $_code = self::CODE;
    protected $_isGateway = true;
    protected $_canOrder = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canAuthorize = true;
    protected $_canVoid = true;
    protected $openpay = false;
    protected $is_sandbox;    
    protected $country;
    protected $use_card_points;
    protected $merchant_id = null;
    protected $pk = null;
    protected $sk = null;
    protected $sandbox_merchant_id;
    protected $sandbox_sk;
    protected $sandbox_pk;
    protected $live_merchant_id;
    protected $live_sk;
    protected $live_pk;
    protected $country_factory;
    protected $scopeConfig;
    protected $supported_currency_codes = array('USD', 'MXN');    
    protected $months_interest_free;
    protected $charge_type;
    protected $logger;
    protected $_storeManager;
    protected $save_cc;
    protected $installments;
    protected $iva = 0;
    protected $minimum_amounts = 0;
    protected $config_months;
    protected $merchant_classification = '';

    /**
     * @var Customer
     */
    protected $customerModel;
    /**
     * @var CustomerSession
     */
    protected $customerSession;    
    
    protected $openpayCustomerFactory;

    /**
    *  @var \Magento\Framework\App\Config\Storage\WriterInterface
    */
    protected $configWriter;

    /**
     * 
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Magento\Framework\Module\ModuleListInterface $moduleList
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate
     * @param \Magento\Directory\Model\CountryFactory $countryFactory
     * @param \Openpay $openpay
     * @param array $data
     * @param \Magento\Store\Model\StoreManagerInterface $data
     * @param WriterInterface $configWriter
     */
    public function __construct(
            StoreManagerInterface $storeManager,
            Context $context, 
            Registry $registry, 
            ExtensionAttributesFactory $extensionFactory, 
            AttributeValueFactory $customAttributeFactory, 
            Data $paymentData, 
            ScopeConfigInterface $scopeConfig,
            WriterInterface $configWriter,
            Logger $logger,
            ModuleListInterface $moduleList, 
            TimezoneInterface $localeDate, 
            CountryFactory $countryFactory, 
            \Openpay $openpay,             
            \Psr\Log\LoggerInterface $logger_interface,            
            Customer $customerModel,
            CustomerSession $customerSession,            
            \Openpay\Cards\Model\OpenpayCustomerFactory $openpayCustomerFactory,
            array $data = array()            
    ) {
        
        parent::__construct(
                $context, $registry, $extensionFactory, $customAttributeFactory, $paymentData, $scopeConfig, $logger, $moduleList, $localeDate, null, null, $data
        );
                    
        $this->customerModel = $customerModel;
        $this->customerSession = $customerSession;
        $this->openpayCustomerFactory = $openpayCustomerFactory;
        
        $this->_storeManager = $storeManager;
        $this->logger = $logger_interface;

        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->country_factory = $countryFactory;

        $this->is_active = $this->getConfigData('active');
        $this->is_sandbox = $this->getConfigData('is_sandbox');
        $this->country = $this->getConfigData('country');
        $this->merchant_classification = $this->getConfigData('merchant_classification');

        $this->_canRefund = $this->country === 'MX' ? true : false;
        $this->_canRefundInvoicePartial = $this->country === 'MX' ? true : false;
        
        $this->sandbox_merchant_id = $this->getConfigData('sandbox_merchant_id');
        $this->sandbox_sk = $this->getConfigData('sandbox_sk');
        $this->sandbox_pk = $this->getConfigData('sandbox_pk');
        $this->live_merchant_id = $this->getConfigData('live_merchant_id');
        $this->live_sk = $this->getConfigData('live_sk');
        $this->live_pk = $this->getConfigData('live_pk');

        $this->merchant_id = $this->is_sandbox ? $this->sandbox_merchant_id : $this->live_merchant_id;
        $this->sk = $this->is_sandbox ? $this->sandbox_sk : $this->live_sk;
        $this->pk = $this->is_sandbox ? $this->sandbox_pk : $this->live_pk;
        $this->months_interest_free = $this->country === 'MX' ? $this->getConfigData('interest_free') : '1';        
        $this->use_card_points = $this->country === 'MX' ? $this->getConfigData('use_card_points') : '0';
        $this->iva = $this->country === 'CO' ? $this->getConfigData('iva') : '0';
        $this->save_cc = $this->getConfigData('save_cc');
        $this->installments = $this->country === 'CO' ? $this->getConfigData('installments') : '1';
        $this->minimum_amounts = $this->getConfigData('minimum_amounts');
        $this->config_months = $this->minimum_amounts ? array(
                                        "3" => $this->getConfigData('three_months'),
                                        "6" => $this->getConfigData('six_months'),
                                        "9" => $this->getConfigData('nine_months'),
                                        "12" => $this->getConfigData('twelve_months'),
                                        "18" => $this->getConfigData('eighteen_months')
        ) : null;
        $this->charge_type = $this->country === 'MX' ? $this->getConfigData('charge_type') : 'direct';
        $this->affiliation_bbva = $this->getConfigData('affiliation_bbva');
        
        $this->merchant_classification = $this->getMerchantInfo();
        $classification = ($this->merchant_classification === 'eglobal' ? 'BBVA' : 'Openpay');
        $this->configWriter->save('payment/openpay_cards/merchant_classification',  $classification);


        $this->configWriter->save('payment/openpay_cards/title',  $classification." (Tarjetas de crédito/débito)");
        

        $this->openpay = $openpay;

        if($this->merchant_classification === 'eglobal'){
            if(empty($this->getConfigData('affiliation_bbva'))){
                $this->charge_type = '3d';
                $this->configWriter->save('payment/openpay_cards/charge_type', '3d');
                throw new \Magento\Framework\Validator\Exception(__("The bbva affiliation field is required."));
            }
        }else{
            if(!empty($this->getConfigData('affiliation_bbva'))){
                $this->charge_type = 'direct';
                $this->affiliation_bbva = '';
                $this->configWriter->save('payment/openpay_cards/affiliation_bbva', '');
                $this->configWriter->save('payment/openpay_cards/charge_type', 'direct');
            }
        }
    }
    
    /**
     * Validate payment method information object
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException     
     */
    public function validate() {                                     
        $info = $this->getInfoInstance();
        $openpay_cc = $info->getAdditionalInformation('openpay_cc');
        $availableTypes = explode(',', $this->getConfigData('cctypes'));
        $errorMsg = false;

        $this->logger->debug('#validate', array('$openpay_cc' => $openpay_cc, 'getCcType' => $info->getCcType()));                  
        
        // Custom Validation
        
        /** CC_number validation is not done because it should not get into the server * */
        if ($info->getCcType() != null && !in_array($info->getCcType(), $availableTypes)) {
            $errorMsg = 'Credit card type is not allowed for this payment method.';
        }

        if ($errorMsg) {
            $this->logger->error('captureOpenpayTransaction', array('#ERROR validate() => ' => $errorMsg));
            throw new \Magento\Framework\Exception\LocalizedException(__($errorMsg));
        }

        return $this;
    }

    /**
     * Assign corresponding data
     *
     * @param \Magento\Framework\DataObject|mixed $data
     * @return $this
     * @throws LocalizedException
     */
    public function assignData(\Magento\Framework\DataObject $data) {
        parent::assignData($data);
                
        $infoInstance = $this->getInfoInstance();
        $additionalData = ($data->getData('additional_data') != null) ? $data->getData('additional_data') : $data->getData();
        
        $infoInstance->setAdditionalInformation('device_session_id', 
            isset($additionalData['device_session_id']) ? $additionalData['device_session_id'] :  null
        );
        $infoInstance->setAdditionalInformation('openpay_token',     
            isset($additionalData['openpay_token']) ? $additionalData['openpay_token'] : null
        );
        $infoInstance->setAdditionalInformation('interest_free',
            isset($additionalData['interest_free']) ? $additionalData['interest_free'] : null
        );        
        $infoInstance->setAdditionalInformation('use_card_points',
            isset($additionalData['use_card_points']) ? $additionalData['use_card_points'] : null
        );        
        $infoInstance->setAdditionalInformation('save_cc',
            isset($additionalData['save_cc']) ? $additionalData['save_cc'] : null
        );        
        $infoInstance->setAdditionalInformation('openpay_cc',
            isset($additionalData['openpay_cc']) ? $additionalData['openpay_cc'] : null
        );
        $infoInstance->setAdditionalInformation('cc_cid',
            isset($additionalData['cc_cid']) ? $additionalData['cc_cid'] : null
        );
        $infoInstance->setAdditionalInformation('installments',
            isset($additionalData['installments']) ? $additionalData['installments'] : null
        );
        return $this;
    }
    
    /**
     * Refund capture
     *
     * @param \Magento\Framework\DataObject|\Magento\Payment\Model\InfoInterface|Payment $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount){
        $order = $payment->getOrder();
        $trx_id = $order->getExtOrderId();
        $customer_id = $order->getExtCustomerId();
        
        $this->logger->debug('#refund', array('$trx_id' => $trx_id, '$customer_id' => $customer_id, '$order_id' => $order->getIncrementId(), '$status' => $order->getStatus(), '$amount' => $amount));                    
        
        if ($amount <= 0) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Invalid amount for refund.'));
        }
        
        try {
            $refundData = array(
                'description' => 'Reembolso',
                'amount' => $amount                
            );

//            $openpay = $this->getOpenpayInstance();
//            $charge = $openpay->charges->get($trx_id);
            $charge = $this->getOpenpayCharge($trx_id, $customer_id);            
            $charge->refund($refundData);            
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(__($e->getMessage()));
        }        
        
        return $this;
    }
    
    
    /**
     * Send authorize request to gateway
     *
     * @param \Magento\Framework\DataObject|\Magento\Payment\Model\InfoInterface $payment
     * @param  float $amount
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount) {         
        $order = $payment->getOrder();
        $this->logger->debug('#authorize', array('$order_id' => $order->getIncrementId(), '$status' => $order->getStatus(), '$amount' => $amount));                    
        $payment->setAdditionalInformation('payment_type', $this->getConfigData('payment_action'));
        $payment->setIsTransactionClosed(false);
        $payment->setSkipOrderProcessing(true);
        $this->processCapture($payment, $amount);
        return $this;
    }
    
    /**
     * Send capture request to gateway
     *
     * @param \Magento\Framework\DataObject|\Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount) {
        $order = $payment->getOrder();                
        $this->logger->debug('#capture', array('$order_id' => $order->getIncrementId(), '$trx_id' => $payment->getLastTransId(), '$status' => $order->getStatus(), '$amount' => $amount));                    
        
        if ($amount <= 0) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Invalid amount for capture.'));
        }               
        
        $payment->setAmount($amount);
        if(!$payment->getLastTransId()){            
            $this->processCapture($payment, $amount);
        } else {
            $this->captureOpenpayTransaction($payment, $amount);
        }        
        
        return $this;
    }
    
    protected function captureOpenpayTransaction(\Magento\Payment\Model\InfoInterface $payment, $amount){
        $order = $payment->getOrder();                
        $customer_id = $order->getExtCustomerId();
        
        $this->logger->debug('#captureOpenpayTransaction', array('$trx_id' => $payment->getLastTransId(), '$customer_id' => $customer_id, '$order_id' => $order->getIncrementId(), '$status' => $order->getStatus(), '$amount' => $amount));                    
                
        try {
            $order->addStatusHistoryComment("Pago recibido exitosamente")->setIsCustomerNotified(true);            
            $charge = $this->getOpenpayCharge($payment->getLastTransId(), $customer_id);
            $captureData = array('amount' => $amount);
            $charge->capture($captureData);

            return $charge;
        } catch (\Exception $e) {
            $this->logger->error('captureOpenpayTransaction', array('message' => $e->getMessage(), 'code' => $e->getCode()));
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }        
    }
    
    
    /**     
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Validator\Exception
     */
    public function processCapture(\Magento\Payment\Model\InfoInterface $payment, $amount) {
        unset($_SESSION['pdf_url']);
        unset($_SESSION['show_map']);
        unset($_SESSION['openpay_3d_secure_url']);
        unset($_SESSION['openpay_pse_redirect_url']);
        
        $base_url = $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);  // URL de la tienda        
        
        /** @var \Magento\Sales\Model\Order $order */
        $order = $payment->getOrder();
        
        $this->logger->debug('#processCapture', array('charge_type' => $this->charge_type,'$order_id' => $order->getIncrementId(), '$status' => $order->getStatus(), '$amount' => $amount));        

        /** @var \Magento\Sales\Model\Order\Address $billing */
        $billing = $order->getBillingAddress();

        $capture = $this->getConfigData('payment_action') == 'authorize_capture' ? true : false;
        $token = $this->getInfoInstance()->getAdditionalInformation('openpay_token');
        $device_session_id = $this->getInfoInstance()->getAdditionalInformation('device_session_id');
        $use_card_points = $this->getInfoInstance()->getAdditionalInformation('use_card_points');
        $save_cc = $this->getInfoInstance()->getAdditionalInformation('save_cc');
        $openpay_cc = $this->getInfoInstance()->getAdditionalInformation('openpay_cc');
        $cvv2 = $this->getInfoInstance()->getAdditionalInformation('cc_cid');
        
        if (!$token && (!$openpay_cc || $openpay_cc == 'new')) {
            $msg = 'ERROR 100 Please specify card info';
            throw new \Magento\Framework\Validator\Exception(__($msg));
        }
        
        $this->logger->debug('#processCapture', array('$openpay_cc' => $openpay_cc, '$save_cc' => $save_cc, '$device_session_id' => $device_session_id));        
                                
        $customer_data = array(
            'requires_account' => false,
            'name' => $billing->getFirstname(),
            'last_name' => $billing->getLastname(),
            'phone_number' => $billing->getTelephone(),
            'email' => $order->getCustomerEmail()
        );

        if ($this->validateAddress($billing)) {
            $customer_data = $this->formatAddress($customer_data, $billing);
        }
        
        $charge_request = array(
            'method' => 'card',
            'currency' => strtolower($order->getBaseCurrencyCode()),
            'amount' => $amount,
            'description' => sprintf('#%s, %s', $order->getIncrementId(), $order->getCustomerEmail()),                
            'order_id' => $order->getIncrementId(),
            'source_id' => $token,
            'device_session_id' => $device_session_id,
            'customer' => $customer_data,
            'use_card_points' => $use_card_points,  
            'capture' => $capture
        );
        
        if($this->country === 'MX' && $this->merchant_classification == 'eglobal'){
            $charge_request['affiliation_bbva'] = $this->affiliation_bbva;
        }
        
        if ($this->country === 'CO') {
            $charge_request['iva'] = $this->iva;
        }

        // Meses sin intereses (solo para MX)
        $interest_free = $this->getInfoInstance()->getAdditionalInformation('interest_free');
        if($interest_free > 1 && $this->country === 'MX'){
            $charge_request['payment_plan'] = array('payments' => (int)$interest_free);
        }  
        
        // Pago en cuotas (solo para CO)
        $installments = $this->getInfoInstance()->getAdditionalInformation('installments');
        $this->logger->debug('#installments', array('$installments' => $installments));        
        if($installments > 1 && $this->country === 'CO'){
            $charge_request['payment_plan'] = array('payments' => (int)$installments);
        }  

        // 3D Secure
        if ($this->charge_type == '3d') {
            $charge_request['use_3d_secure'] = true;
            $charge_request['redirect_url'] = $base_url.'openpay/payment/success';
        }

        // cvv2
        if ($this->country === 'CO' && $save_cc == '0' && $openpay_cc != 'new') {
            $charge_request['cvv2'] = $cvv2;                    
        }
                
        try {                           
            // Realiza la transacción en Openpay
            $charge = $this->makeOpenpayCharge($customer_data, $charge_request, $token, $device_session_id, $save_cc, $openpay_cc);                                                
            
            $payment->setTransactionId($charge->id);  
            $payment->setCcLast4(substr($charge->card->card_number, -4));
            $payment->setCcType($this->getCCBrandCode($charge->card->brand));
            $payment->setCcExpMonth($charge->card->expiration_month);
            $payment->setCcExpYear($charge->card->expiration_year);
                                                                                    
            if ($this->charge_type == '3d') {            
                $payment->setIsTransactionPending(true);                
                $_SESSION['openpay_3d_secure_url'] = $charge->payment_method->url;
                $this->logger->debug('3d_direct', array('redirect_url' => $charge->payment_method->url, 'openpay_id' => $charge->id, 'openpay_status' => $charge->status));
            }
            
            $openpayCustomerFactory = $this->customerSession->isLoggedIn() ? $this->hasOpenpayAccount($this->customerSession->getCustomer()->getId()) : null;
            $openpay_customer_id = $openpayCustomerFactory ? $openpayCustomerFactory->openpay_id : null;
            
            // Registra el ID de la transacción de Openpay
            $order->setExtOrderId($charge->id);   
            
            // Registra (si existe), el ID de Customer de Openpay
            $order->setExtCustomerId($openpay_customer_id);
            $order->save();                       
            
            $this->logger->debug('#saveOrder');        
            
        } catch (\OpenpayApiTransactionError $e) {                        
            $this->logger->error('OpenpayApiTransactionError', array('message' => $e->getMessage(), 'code' => $e->getErrorCode(), '$status' => $order->getStatus()));
            
            // Si hubo riesgo de fraude y el usuario definió autenticación selectiva, se envía por 3D secure
            if ($this->charge_type == 'auth' && $e->getErrorCode() == '3005') {                                                
                $charge_request['use_3d_secure'] = true;
                $charge_request['redirect_url'] = $base_url.'openpay/payment/success';
                                
                $charge = $this->makeOpenpayCharge($customer_data, $charge_request, $token, $device_session_id, $save_cc, $openpay_cc);
                $openpayCustomerFactory = $this->customerSession->isLoggedIn() ? $this->hasOpenpayAccount($this->customerSession->getCustomer()->getId()) : null;
                                
                $order->setExtOrderId($charge->id);
                $order->setExtCustomerId($openpayCustomerFactory->openpay_id);
                $order->save();
                
                $payment->setTransactionId($charge->id);      
                $payment->setCcLast4(substr($charge->card->card_number, -4));
                $payment->setCcType($this->getCCBrandCode($charge->card->brand));
                $payment->setCcExpMonth($charge->card->expiration_month);
                $payment->setCcExpYear($charge->card->expiration_year);                
                $payment->setAdditionalInformation('openpay_3d_secure_url', $charge->payment_method->url); 
                $payment->setSkipOrderProcessing(true);                      
                $payment->setIsTransactionPending(true);
                
                $_SESSION['openpay_3d_secure_url'] = $charge->payment_method->url;
                
                $this->logger->debug('3d_auth', array('redirect_url' => $charge->payment_method->url, 'openpay_id' => $charge->id, 'openpay_status' => $charge->status, '$status' => $order->getStatus()));                
            } else {
                throw new \Magento\Framework\Validator\Exception(__($this->error($e)));
            }
        } catch (\Exception $e) {
            $this->_logger->error(__('Payment capturing error.'));            
            $this->logger->error('ERROR', array('message' => $e->getMessage(), 'code' => $e->getCode()));
            throw new \Magento\Framework\Validator\Exception(__($this->error($e)));
        }
        
        return $this;
    }
    
    private function formatAddress($customer_data, $billing) {
        if ($this->country === 'MX') {
            $customer_data['address'] = array(
                'line1' => $billing->getStreetLine(1),
                'line2' => $billing->getStreetLine(2),
                'postal_code' => $billing->getPostcode(),
                'city' => $billing->getCity(),
                'state' => $billing->getRegion(),
                'country_code' => $billing->getCountryId()
            );
        } else if ($this->country === 'CO') {
            $customer_data['customer_address'] = array(
                'department' => $billing->getRegion(),
                'city' => $billing->getCity(),
                'additional' => $billing->getStreetLine(1).' '.$billing->getStreetLine(2)
            );
        }
        
        return $customer_data;
    }
    
    private function getCCBrandCode($brand) {
        $code = null;
        switch ($brand) {
            case "mastercard":
                $code = "MC";
                break;
            
            case "visa":
                $code = "VI";
                break;
            
            case "american_express":
                $code = "AE";
                break;
            case "carnet":
                $code = "CN";
                break;
        }
        return $code;
    }
    
    private function makeOpenpayCharge($customer_data, $charge_request, $token, $device_session_id, $save_cc, $openpay_cc) {        
        $openpay = $this->getOpenpayInstance();

        if (!$this->customerSession->isLoggedIn()) {
            // Cargo para usuarios "invitados"
            return $openpay->charges->create($charge_request);
        }

        // Se remueve el atributo de "customer" porque ya esta relacionado con una cuenta en Openpay
        unset($charge_request['customer']); 

        $openpay_customer = $this->retrieveOpenpayCustomerAccount($customer_data);

        if ($save_cc == '1' && $openpay_cc == 'new') {
            $card_data = array(            
                'token_id' => $token,            
                'device_session_id' => $device_session_id
            );
            $card = $this->createCreditCard($openpay_customer, $card_data);

            // Se reemplaza el "source_id" por el ID de la tarjeta
            $charge_request['source_id'] = $card->id;                                                            
        } else if ($save_cc == '0' && $openpay_cc != 'new') {
            $charge_request['source_id'] = $openpay_cc;                    
        }

        // Cargo para usuarios con cuenta
        return $openpay_customer->charges->create($charge_request);            
    }
    
    public function getOpenpayCharge($charge_id, $customer_id = null) {
        try {                        
            if ($customer_id === null) {                
                $openpay = $this->getOpenpayInstance();
                return $openpay->charges->get($charge_id);
            }            
            
            $openpay_customer = $this->getOpenpayCustomer($customer_id);
            if($openpay_customer === false){
                $openpay = $this->getOpenpayInstance();
                return $openpay->charges->get($charge_id);
            }

            return $openpay_customer->charges->get($charge_id);            
        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }
    }
    
    private function hasOpenpayAccount($customer_id) {        
        try {
            $openpay_customer_local = $this->openpayCustomerFactory->create();
            $response = $openpay_customer_local->fetchOneBy('customer_id', $customer_id);
            return $response;
        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }  
    }
    
    private function retrieveOpenpayCustomerAccount($customer_data) {
        try {
            $customerId = $this->customerSession->getCustomer()->getId();                
            //$customer = $this->customerModel->load($customerId);                
            //$this->logger->debug('getFirstname => '.$customer->getFirstname()); 
            $has_openpay_account = $this->hasOpenpayAccount($customerId);
            if ($has_openpay_account === false) {
                $openpay_customer = $this->createOpenpayCustomer($customer_data);
                $this->logger->debug('$openpay_customer => '.$openpay_customer->id);

                $data = [
                    'customer_id' => $customerId,
                    'openpay_id' => $openpay_customer->id
                ];

                // Se guarda en BD la relación
                $openpay_customer_local = $this->openpayCustomerFactory->create();
                $openpay_customer_local->addData($data)->save();                    
            } else {
                $openpay_customer = $this->getOpenpayCustomer($has_openpay_account->openpay_id);
                if($openpay_customer === false){
                    $openpay_customer = $this->createOpenpayCustomer($customer_data);

                    $this->logger->debug('#update openpay_customer', array('$openpay_customer_old' => $has_openpay_account->openpay_id, '$openpay_customer_old_new' => $openpay_customer->id));

                    // Se actualiza en BD la relación
                    $openpay_customer_local = $this->openpayCustomerFactory->create();
                    $openpay_customer_local_update = $openpay_customer_local->load($has_openpay_account->openpay_customer_id);
                    $openpay_customer_local_update->setOpenpayId($openpay_customer->id);
                    $openpay_customer_local_update->save();
                }
            }
            
            return $openpay_customer;
        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }
    }
    
    private function createOpenpayCustomer($data) {
        try {
            $openpay = $this->getOpenpayInstance();
            return $openpay->customers->add($data);            
        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }        
    }
    
    public function getOpenpayCustomer($openpay_customer_id) {
        try {
            $openpay = $this->getOpenpayInstance();
            $customer = $openpay->customers->get($openpay_customer_id);
            if(isset($customer->balance)){
                return false;
            }
            return $customer;            
        } catch (\Exception $e) {
            return false;
        }        
    }
    
    private function createCreditCard($customer, $data) {
        try {
            return $customer->cards->add($data);            
        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }        
    }
    
    private function getCreditCards($customer, $customer_created_at) {
        $from_date = date('Y-m-d', strtotime($customer_created_at));
        $to_date = date('Y-m-d');
        try {
            return $customer->cards->getList(array(
                'creation[gte]' => $from_date,
                'creation[lte]' => $to_date,
                'offset' => 0,
                'limit' => 10
            ));            
        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }        
    }
    
    public function getCreditCardList() {
        if (!$this->customerSession->isLoggedIn()) {
            return array(array('value' => 'new', 'name' => 'Nueva tarjeta'));
        }
        
        $customerId = $this->customerSession->getCustomer()->getId();
        $has_openpay_account = $this->hasOpenpayAccount($customerId);
        if ($has_openpay_account === false) {
            return array(array('value' => 'new', 'name' => 'Nueva tarjeta'));
        }

        $customer = $this->getOpenpayCustomer($has_openpay_account->openpay_id);
        if($customer == false){
            return array(array('value' => 'new', 'name' => 'Nueva tarjeta'));
        }

        try {
            $list = array(array('value' => 'new', 'name' => 'Nueva tarjeta'));
            $cards = $this->getCreditCards($customer, $has_openpay_account->created_at);
            
            foreach ($cards as $card) {                
                array_push($list, array('value' => $card->id, 'name' => strtoupper($card->brand).' '.$card->card_number));
            }
            
            return $list;            
        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }        
    }
    
    public function getBaseUrlStore(){
        $base_url = $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_LINK);
        return $base_url;
    }
    
    public function isLoggedIn() {
        return $this->customerSession->isLoggedIn();
    }

    /**
     * Determine method availability based on quote amount and config data
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null) {
        return parent::isAvailable($quote);
    }

    /**
     * Availability for currency
     *
     * @param string $currencyCode
     * @return bool
     */
    public function canUseForCurrency($currencyCode) {
        if ($this->country === 'MX') {
            return in_array($currencyCode, $this->supported_currency_codes);
        } else if ($this->country === 'CO') {
            return $currencyCode == 'COP';
        }
        return false;
    }

    /**
     * @return string
     */
    public function getMerchantId() {
        return $this->merchant_id;
    }

    /**
     * @return string
     */
    public function getPublicKey() {
        return $this->pk;
    }
    
    public function getPrivateKey() {
        return $this->sk;
    }

    /**
     * @return boolean
     */
    public function isSandbox() {
        return $this->is_sandbox;
    }
    
    public function getCountry() {
        return $this->country;
    }

    public function getMerchantInfo() {
        $openpay = \Openpay::getInstance($this->merchant_id, $this->sk, $this->country);
        \Openpay::setSandboxMode($this->is_sandbox);

        try {
            $merchantInfo = $openpay->getMerchantInfo(); 
            $this->logger->debug('#order', array('$merchantInfo' => $merchantInfo));
            $classification = ($merchantInfo->classification === 'eglobal' ? 'BBVA' : 'Openpay');
            $this->configWriter->save('payment/openpay_cards/merchant_classification', $classification);
            return $merchantInfo->classification;
        } catch (Exception $e) {
            return $this->error($e);
        }
    }


//    public function getMinimumAmount() {
//        return $this->minimum_amount;
//    }
    
    public function getCode() {
        return $this->_code;
    }
    
    public function getOpenpayInstance() {
        $openpay = \Openpay::getInstance($this->merchant_id, $this->sk, $this->country);
        \Openpay::setSandboxMode($this->is_sandbox);
        
        if($this->merchant_classification === 'eglobal'){
            \Openpay::setClassificationMerchant($this->merchant_classification);
            $userAgent = "BBVA-MTO2".$this->country."/v1";
        }else{
            $userAgent = "Openpay-MTO2".$this->country."/v2";
        }   

        \Openpay::setUserAgent($userAgent);
        
        return $openpay;
    }
    
    public function getMonthsInterestFree() {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $cart = $objectManager->get('\Magento\Checkout\Model\Cart'); 
        $grandTotal = (int) $cart->getQuote()->getGrandTotal();    
        $months = explode(',', $this->months_interest_free);
        
        if($this->minimum_amounts && $this->country == 'MX'){
            foreach($months as $key => $value){
                $msi_minimum_amount = (int) $this->config_months[$value];
                if($grandTotal < $msi_minimum_amount){
                    unset($months[$key]);
                }
            }
        }
        
        if(!in_array('1', $months)) {            
            array_unshift($months, '1');
        }        
        return $months;
    }
    
    public function getInstallments() {        
        $installments = explode(',', $this->installments);                  
        if(!in_array('1', $installments)) {            
            array_unshift($installments, '1');
        }        
        
        return $installments;
    }
    
    public function useCardPoints() {
        return $this->use_card_points;
    }
    
    /**
     * 
     * Valida que los clientes pueda guardar sus TC
     * 
     * @return boolean
     */
    public function canSaveCC() {
        return $this->save_cc;
    }
    
    /**
     * @param Exception $e
     * @return string
     */
    public function error($e) {
        /* 6001 el webhook ya existe */
        switch ($e->getCode()) {
            case '1000':
            case '1004':
            case '1005':
                $msg = 'Servicio no disponible.';
                break;
            /* ERRORES TARJETA */
            case '3001':
            case '3004':
            case '3005':
            case '3007':
                $msg = 'La tarjeta fue rechazada.';
                break;
            case '3002':
                $msg = 'La tarjeta ha expirado.';
                break;
            case '3003':
                $msg = 'La tarjeta no tiene fondos suficientes.';
                break;
            case '3006':
                $msg = 'La operación no esta permitida para este cliente o esta transacción.';
                break;
            case '3008':
                $msg = 'La tarjeta no es soportada en transacciones en línea.';
                break;
            case '3009':
                $msg = 'La tarjeta fue reportada como perdida.';
                break;
            case '3010':
                $msg = 'El banco ha restringido la tarjeta.';
                break;
            case '3011':
                $msg = 'El banco ha solicitado que la tarjeta sea retenida. Contacte al banco.';
                break;
            case '3012':
                $msg = 'Se requiere solicitar al banco autorización para realizar este pago.';
                break;
            default: /* Demás errores 400 */
                $msg = 'La petición no pudo ser procesada.';
                break;
        }

        return 'ERROR '.$e->getCode().'. '.$msg;
    }

    /**
     * @param Address $billing
     * @return boolean
     */
    public function validateAddress($billing) {
        if ($billing->getCountryId() === 'MX' && $billing->getStreetLine(1) && $billing->getCity() && $billing->getPostcode() && $billing->getRegion()) {
            return true;
        } else if ($billing->getCountryId() === 'CO' && $billing->getStreetLine(1) && $billing->getCity() && $billing->getRegion()) {
            return true;
        }
        return false;
    }

}
