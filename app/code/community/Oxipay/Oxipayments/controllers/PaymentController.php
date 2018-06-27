<?php
require_once dirname(__FILE__).'/../Helper/Crypto.php';

class Oxipay_Oxipayments_PaymentController extends Mage_Core_Controller_Front_Action
{
    const LOG_FILE = 'oxipay.log';
    const OXIPAY_AU_CURRENCY_CODE = 'AUD';
    const OXIPAY_AU_COUNTRY_CODE = 'AU';
    const OXIPAY_NZ_CURRENCY_CODE = 'NZD';
    const OXIPAY_NZ_COUNTRY_CODE = 'NZ';

    /**
     * GET: /oxipayments/payment/start
     *
     * Begin processing payment via oxipay
     */
    public function startAction()
    {
        if($this->validateQuote()) {
            try {
                $order = $this->getLastRealOrder();

                $this->restoreCart($order, true);
                $quote = Mage::getModel('checkout/session')->getQuote();
                $quoteId = $quote->getId();

                $payload = $this->getPayload($order, $quoteId);
                $quote->setCheckoutMethod(null);
                $quote->save();

                // delete order
                Mage::getResourceSingleton('sales/order')->delete($order);

                $this->postToCheckout(Oxipay_Oxipayments_Helper_Data::getCheckoutUrl(), $payload);
            } catch (Exception $ex) {
                Mage::logException($ex);
                Mage::log('An exception was encountered in oxipayments/paymentcontroller: ' . $ex->getMessage(), Zend_Log::ERR, self::LOG_FILE);
                Mage::log($ex->getTraceAsString(), Zend_Log::ERR, self::LOG_FILE);
                $this->getCheckoutSession()->addError($this->__('Unable to start Oxipay Checkout.'));
            }
        } else {
            $this->restoreCart($this->getLastRealOrder());
            $this->_redirect('checkout/cart');
        }
    }

    /**
     * GET: /oxipayments/payment/cancel
     * Cancel an order given an order id
     */
    public function cancelAction()
    {
        $this->_redirect('checkout/cart');
    }

    /**
     * GET: oxipayments/payment/complete
     *
     * callback - oxipay calls this once the payment process has been completed.
     */
    public function completeAction() {
        $isValid = Oxipay_Oxipayments_Helper_Crypto::isValidSignature($this->getRequest()->getParams(), $this->getApiKey());
        $result = $this->getRequest()->get("x_result");
        $quoteId = $this->getRequest()->get("x_reference");
        $transactionId = $this->getRequest()->get("x_gateway_reference");

        if(!$isValid) {
            Mage::log('Possible site forgery detected: invalid response signature.', Zend_Log::ALERT, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
            return;
        }

        if(!$quoteId) {
            Mage::log("Oxipay returned a null quote id. This may indicate an issue with the Oxipay payment gateway.", Zend_Log::ERR, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
            return;
        }

        $quote = Mage::getModel('sales/quote')->load($quoteId);
        if(!$quote) {
            Mage::log("Oxipay returned an id for an quote that could not be retrieved: $quoteId", Zend_Log::ERR, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
            return;
        }

        if($result == "completed") {
            // if already completed by oxipay (e.g. by async-callback)
            if($quote->getData("checkout_method") == "oxipay"){
                Mage::getSingleton('checkout/cart')->truncate()->save();
                $this->_redirect('checkout/onepage/success', array('_secure'=> false));
                return;
            }
            // else
            $quote->setCheckoutMethod('oxipay');
            $quote->save();
            $quote->collectTotals();
            $quote->save();

            $service = Mage::getModel('sales/service_quote', $quote);
            $service->submitAll(); 
            $order = $service->getOrder();
            $emailCustomer = Mage::getStoreConfig('payment/oxipayments/email_customer');

            if($order) {
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, 'Oxipay processed.', $emailCustomer);
                // $order->setStatus(Oxipay_Oxipayments_Helper_OrderStatus::STATUS_PROCESSING);
                $order->save();

                // send email
                if ($emailCustomer) {
                    $order->sendNewOrderEmail();
                }
                
                // generate invoice
                $invoiceAutomatically = Mage::getStoreConfig('payment/oxipayments/automatic_invoice');
                if ($invoiceAutomatically) {
                    $this->invoiceOrder($order);
                }

                // clear shopping cart
                Mage::getSingleton('checkout/cart')->truncate()->save();

                $this->_redirect('checkout/onepage/success', array('_secure'=> false));
            } else {
                $this->_redirect('checkout/onepage/failure', array('_secure'=> false));
            }
            return;
        } 
        elseif($result == "failed"){
            // restore cart
            if ($quote->getId()) {
                $quote->setIsActive(1);    
                $quote->setReservedOrderId(null);
                $quote->save();
                $this->getCheckoutSession()->replaceQuote($quote);
            }
            $this->_redirect('checkout/onepage/failure', array('_secure'=> false));
            return;
        }
        else {
            $this->_redirect('checkout/onepage/failure', array('_secure'=> false));
            return;
        }
    }

    private function statusExists($orderStatus) {
        try {
            $orderStatusModel = Mage::getModel('sales/order_status');
            if ($orderStatusModel) {
                $statusesResCol = $orderStatusModel->getResourceCollection();
                if ($statusesResCol) {
                    $statuses = $statusesResCol->getData();
                    foreach ($statuses as $status) {
                        if ($orderStatus === $status["status"]) return true;
                    }
                }
            }
        } catch(Exception $e) {
            Mage::log("Exception searching statuses: ".($e->getMessage()), Zend_Log::ERR, self::LOG_FILE);
        }
        return false;
    }

    private function invoiceOrder(Mage_Sales_Model_Order $order) {

        if(!$order->canInvoice()){
            Mage::throwException(Mage::helper('core')->__('Cannot create an invoice.'));
        }

        $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

        if (!$invoice->getTotalQty()) {
            Mage::throwException(Mage::helper('core')->__('Cannot create an invoice without products.'));
        }

        $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
        $invoice->register();
        $transactionSave = Mage::getModel('core/resource_transaction')
        ->addObject($invoice)
        ->addObject($invoice->getOrder());

        $transactionSave->save();
    }

    /**
     * Constructs a request payload to send to oxipay
     * @return array
     */
    private function getPayload($order, $quoteId) {
        if($order == null)
        {
            Mage::log('Unable to get order from last lodged order id. Possibly related to a failed database call.', Zend_Log::ALERT, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
        }

        $shippingAddress = $order->getShippingAddress();
        $billingAddress = $order->getBillingAddress();

        $billingAddressParts = explode(PHP_EOL, $billingAddress->getData('street'));
        $billingAddress0 = $billingAddressParts[0];
        $billingAddress1 = (count($billingAddressParts)>1)? $billingAddressParts[1]:'';

        if (!empty($shippingAddress)){
            $shippingAddressParts = explode(PHP_EOL, $shippingAddress->getData('street'));
            $shippingAddress0 = $shippingAddressParts[0];
            $shippingAddress1 = (count($shippingAddressParts)>1)? $shippingAddressParts[1]:'';
            $shippingAddress_city = $shippingAddress->getData('city');
            $shippingAddress_region = $shippingAddress->getData('region');
            $shippingAddress_postcode = $shippingAddress->getData('postcode');
        } else {
            $shippingAddress0 = "";
            $shippingAddress1 = "";
            $shippingAddress_city = "";
            $shippingAddress_region = "";
            $shippingAddress_postcode = "";
        }

        $orderId = (int)$order->getRealOrderId();
        $cancel_signature_query = ["orderId"=>$orderId, "amount"=>$order->getTotalDue(), "email"=>$order->getData('customer_email'), "firstname"=>$order->getCustomerFirstname(), "lastname"=>$order->getCustomerLastname()];
        $cancel_signature = Oxipay_Oxipayments_Helper_Crypto::generateSignature($cancel_signature_query, $this->getApiKey());
        $data = array(
            'x_currency'            => str_replace(PHP_EOL, ' ', $order->getOrderCurrencyCode()),
            'x_url_callback'        => str_replace(PHP_EOL, ' ', Oxipay_Oxipayments_Helper_Data::getCompleteUrl()),
            'x_url_complete'        => str_replace(PHP_EOL, ' ', Oxipay_Oxipayments_Helper_Data::getCompleteUrl()),
            'x_url_cancel'          => str_replace(PHP_EOL, ' ', Oxipay_Oxipayments_Helper_Data::getCancelledUrl($orderId) . "&signature=" . $cancel_signature),
            'x_shop_name'           => str_replace(PHP_EOL, ' ', Mage::app()->getStore()->getCode()),
            'x_account_id'          => str_replace(PHP_EOL, ' ', Mage::getStoreConfig('payment/oxipayments/merchant_number')),
            'x_reference'           => str_replace(PHP_EOL, ' ', $quoteId),
            'x_invoice'             => str_replace(PHP_EOL, ' ', $quoteId),
            'x_amount'              => str_replace(PHP_EOL, ' ', $order->getTotalDue()),
            'x_customer_first_name' => str_replace(PHP_EOL, ' ', $order->getCustomerFirstname()),
            'x_customer_last_name'  => str_replace(PHP_EOL, ' ', $order->getCustomerLastname()),
            'x_customer_email'      => str_replace(PHP_EOL, ' ', $order->getData('customer_email')),
            'x_customer_phone'      => str_replace(PHP_EOL, ' ', $billingAddress->getData('telephone')),
            'x_customer_billing_address1'  => $billingAddress0,
            'x_customer_billing_address2'  => $billingAddress1,
            'x_customer_billing_city'      => str_replace(PHP_EOL, ' ', $billingAddress->getData('city')),
            'x_customer_billing_state'     => str_replace(PHP_EOL, ' ', $billingAddress->getData('region')),
            'x_customer_billing_zip'       => str_replace(PHP_EOL, ' ', $billingAddress->getData('postcode')),
            'x_customer_shipping_address1' => $shippingAddress0,
            'x_customer_shipping_address2' => $shippingAddress1,
            'x_customer_shipping_city'     => str_replace(PHP_EOL, ' ', $shippingAddress_city),
            'x_customer_shipping_state'    => str_replace(PHP_EOL, ' ', $shippingAddress_region),
            'x_customer_shipping_zip'      => str_replace(PHP_EOL, ' ', $shippingAddress_postcode),
            'x_test'                       => 'false'
        );
        $apiKey    = $this->getApiKey();
        $signature = Oxipay_Oxipayments_Helper_Crypto::generateSignature($data, $apiKey);
        $data['x_signature'] = $signature;

        return $data;
    }

    /**
     * checks the quote for validity
     */
    private function validateQuote()
    {
        $specificCurrency = null;

        if ($this->getSpecificCountry() == self::OXIPAY_AU_COUNTRY_CODE) {
            $specificCurrency = self::OXIPAY_AU_CURRENCY_CODE;
        }
        else if ($this->getSpecificCountry() == self::OXIPAY_NZ_COUNTRY_CODE) {
            $specificCurrency = self::OXIPAY_NZ_CURRENCY_CODE;
        }

        $order = $this->getLastRealOrder();

        if($order->getTotalDue() < 20) {
            Mage::getSingleton('checkout/session')->addError("Oxipay doesn't support purchases less than $20.");
            return false;
        }

        if($order->getBillingAddress()->getCountry() != $this->getSpecificCountry() || $order->getOrderCurrencyCode() != $specificCurrency ) {
            Mage::getSingleton('checkout/session')->addError("Orders from this country are not supported by Oxipay. Please select a different payment option.");
            return false;
        }

        if( !$order->isVirtual && $order->getShippingAddress()->getCountry() != $this->getSpecificCountry()) {
            Mage::getSingleton('checkout/session')->addError("Orders shipped to this country are not supported by Oxipay. Please select a different payment option.");
            return false;
        }

        return true;
    }

    /**
     * Get current checkout session
     * @return Mage_Core_Model_Abstract
     */
    private function getCheckoutSession() {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Injects a self posting form to the page in order to kickoff oxipay checkout process
     * @param $checkoutUrl
     * @param $payload
     */
    private function postToCheckout($checkoutUrl, $payload)
    {
        echo
        "<html>
            <body>
            <form id='form' action='$checkoutUrl' method='post'>";
            foreach ($payload as $key => $value) {
                echo "<input type='hidden' id='$key' name='$key' value='".htmlspecialchars($value, ENT_QUOTES)."'/>";
            }
        echo
        '</form>
            </body>';
        echo
        '<script>
                var form = document.getElementById("form");
                form.submit();
            </script>
        </html>';
    }

    /**
     * returns an Order object based on magento's internal order id
     * @param $orderId
     * @return Mage_Sales_Model_Order
     */
    private function getOrderById($orderId)
    {
        return Mage::getModel('sales/order')->loadByIncrementId($orderId);
    }

    /**
     * retrieve the merchants oxipay api key
     * @return mixed
     */
    private function getApiKey()
    {
        return Mage::getStoreConfig('payment/oxipayments/api_key');
    }

    /**
    * Get specific country
    *
    * @return string
    */
    public function getSpecificCountry()
    {
      return Mage::getStoreConfig('payment/oxipayments/specificcountry');
    }

    /**
     * retrieve the last order created by this session
     * @return null
     */
    private function getLastRealOrder()
    {
        $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();

        $order =
            ($orderId)
                ? $this->getOrderById($orderId)
                : null;
        return $order;
    }

    /**
     * Method is called when an order is cancelled by a customer. As an Oxipay reference is only passed back to
     * Magento upon a success or decline outcome, the method will return a message with a Magento reference only.
     *
     * @param Mage_Sales_Model_Order $order
     * @return $this
     * @throws Exception
     */
    private function cancelOrder(Mage_Sales_Model_Order $order)
    {
        if (!$order->isCanceled()) {
            $order
                ->cancel()
                ->setStatus(Oxipay_Oxipayments_Helper_OrderStatus::STATUS_CANCELED)
                ->addStatusHistoryComment($this->__("Order #".($order->getId())." was canceled by customer."));
        }
        return $this;
    }

    /**
     * Loads the cart with items from the order
     * @param Mage_Sales_Model_Order $order
     * @return $this
     */
    private function restoreCart(Mage_Sales_Model_Order $order, $refillStock = false)
    {
        // return all products to shopping cart
        $quoteId = $order->getQuoteId();
        $quote   = Mage::getModel('sales/quote')->load($quoteId);

        if ($quote->getId()) {
            $quote->setIsActive(1);
            if ($refillStock) {
                $items = $this->_getProductsQty($quote->getAllItems());
                if ($items != null ) {
                    Mage::getSingleton('cataloginventory/stock')->revertProductsSale($items);
                }
            }

            $quote->setReservedOrderId(null);
            $quote->save();
            $this->getCheckoutSession()->replaceQuote($quote);
        }
        return $this;
    }
    

    /**
     * Prepare array with information about used product qty and product stock item
     * result is:
     * array(
     *  $productId  => array(
     *      'qty'   => $qty,
     *      'item'  => $stockItems|null
     *  )
     * )
     * @param array $relatedItems
     * @return array
     */
    protected function _getProductsQty($relatedItems)
    {
        $items = array();
        foreach ($relatedItems as $item) {
            $productId  = $item->getProductId();
            if (!$productId) {
                continue;
            }
            $children = $item->getChildrenItems();
            if ($children) {
                foreach ($children as $childItem) {
                    $this->_addItemToQtyArray($childItem, $items);
                }
            } else {
                $this->_addItemToQtyArray($item, $items);
            }
        }
        return $items;
    }


    /**
     * Adds stock item qty to $items (creates new entry or increments existing one)
     * $items is array with following structure:
     * array(
     *  $productId  => array(
     *      'qty'   => $qty,
     *      'item'  => $stockItems|null
     *  )
     * )
     *
     * @param Mage_Sales_Model_Quote_Item $quoteItem
     * @param array &$items
     */
    protected function _addItemToQtyArray($quoteItem, &$items)
    {
        $productId = $quoteItem->getProductId();
        if (!$productId)
            return;
        if (isset($items[$productId])) {
            $items[$productId]['qty'] += $quoteItem->getTotalQty();
        } else {
            $stockItem = null;
            if ($quoteItem->getProduct()) {
                $stockItem = $quoteItem->getProduct()->getStockItem();
            }
            $items[$productId] = array(
                'item' => $stockItem,
                'qty'  => $quoteItem->getTotalQty()
            );
        }
    }
}
