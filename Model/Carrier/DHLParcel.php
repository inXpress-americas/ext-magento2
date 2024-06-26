<?php

namespace InXpress\InXpressRating\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Config;

class DHLParcel extends \Magento\Shipping\Model\Carrier\AbstractCarrier implements
    \Magento\Shipping\Model\Carrier\CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = 'dhlparcel';

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param \Magento\Framework\HTTP\Client\Curl $curl
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        array $data = []
    ) {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->_curl = $curl;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * @return array
     */
    public function getAllowedMethods()
    {
        return ['dhlparcel' => $this->getConfigData('name')];
    }

    /**
     * @param RateRequest $request
     * @return bool|Result
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $shippingPrice = 0;

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->_rateResultFactory->create();

        $gateway = $this->getConfigData('gateway');
        $account = $this->getConfigData('account');

        if (!$account) {
            return false;
        }

        if ($request->getAllItems()) {
            $products = $this->buildProducts($request);

            $destination = array(
                "name" => "",
                "address1" => "",
                "address2" => "",
                "city" => $request->getDestCity(),
                "province" => $request->getDestRegionCode(),
                "phone" => "",
                "country" => $request->getDestCountryId(),
                "postal_code" => $request->getDestPostcode()
            );


            $rates = $this->calcRate($account, $gateway, $products, $destination);

            $this->_logger->critical("InXpress price", ['price' => $rates]);
            if (!$rates) {
                return false;
            }

            foreach ($rates as $ixpRate) {
                $this->_logger->critical("InXpress rate", ['rate' => $ixpRate]);
                if ($ixpRate) {
                    $shippingPrice = $ixpRate['price'];
                } else {
                    return false;
                }

                if ($shippingPrice != 0) {
                    /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
                    $method = $this->_rateMethodFactory->create();

                    $method->setCarrier('dhlparcel');
                    $method->setCarrierTitle($this->getConfigData('title'));

                    $method->setMethod($ixpRate['service_code']);
                    $method->setMethodTitle($ixpRate['service']);

                    $method->setPrice($shippingPrice);
                    $method->setCost($shippingPrice);

                    $result->append($method);
                }
            }
        }

        return $result;
    }

    public function buildProducts($request)
    {
        $products = array();
        foreach ($request->getAllItems() as $item) {
            if ($item->getProduct()->isVirtual() || $item->getParentItem()) {
                continue;
            }

            if ($item->getHasChildren() && $item->isShipSeparately()) {
                foreach ($item->getChildren() as $child) {
                    if (!$child->getFreeShipping() && !$child->getProduct()->isVirtual()) {
                        array_push($products, $child->toArray());
                    }
                }
            } else {
                array_push($products, array(
                    "id" => $item->getProductId(),
                    "sku" => $item->getSku(),
                    "name" => $item->getName(),
                    "price" => $item->getPrice() * 100,
                    "weight" => $this->itemWeight($item),
                    "quantity" => $item->getQty()
                ));
            }
        }
        return $products;
    }

    public function itemWeight($item)
    {
        $weight_in_uom = floatval($item->getWeight());
        $weight_unit = $this->getWeightUnit();

        switch ($weight_unit) {
            case 'lbs':
                $weight = $weight_in_uom * 453.5920;
                break;
            case 'kgs':
                $weight = $weight_in_uom * 1000;
                break;
            default:
                $weight = $weight_in_uom;
        }

        return $weight;
    }

    public function getWeightUnit()
    {
        return $this->_scopeConfig->getValue('general/locale/weight_unit', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    public function addHandling($price)
    {
        $handling_type = $this->getConfigData('handling_type');
        $handling_fee = $this->getConfigData('handling_fee');

        if ($handling_type === "F" && isset($handling_fee)) {
            $price += $handling_fee;
        }

        if ($handling_type === "P" && isset($handling_fee)) {
            $multiplier = $handling_fee / 100 + 1.00;
            $price *= $multiplier;
        }

        return $price;
    }

    public function calcRate($account, $gateway, $products, $destination)
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORES;
        $store_id = $this->_scopeConfig->getValue("system/carriers/dhlexpress/store_id", $storeScope, \Magento\Store\Model\Store::DEFAULT_STORE_ID);

        if (empty($store_id)) {
            $this->_logger->critical("InXpress store id not found, please register on the portal", ['store_id' => $store_id]);
            return false;
        }

        $address = $this->getConfigData('address') ?? "";
        $origin = array(
            "name" => "",
            "address1" => $address,
            "address2" => "",
            "city" => $this->_scopeConfig->getValue(
                Config::XML_PATH_ORIGIN_CITY,
                $storeScope,
                \Magento\Store\Model\Store::DEFAULT_STORE_ID
            ),
            "province" => "",
            "phone" => "",
            "country" => $this->_scopeConfig->getValue(
                Config::XML_PATH_ORIGIN_COUNTRY_ID,
                $storeScope,
                \Magento\Store\Model\Store::DEFAULT_STORE_ID
            ),
            "postal_code" => $this->_scopeConfig->getValue(
                Config::XML_PATH_ORIGIN_POSTCODE,
                $storeScope,
                \Magento\Store\Model\Store::DEFAULT_STORE_ID
            )
        );

        $url = "https://api.inxpressapps.com/carrier/v1/stores/" . $store_id . "/rates";

        $payload = [
            "account" => $account,
            "gateway" => $gateway,
            "services" => array(array(
                "carrier" => "DHL Parcel"
            )),
            "origin" => $origin,
            "destination" => $destination,
            "items" => $products
        ];

        $this->_logger->critical("InXpress requesting rates", ['url' => $url, 'request' => $payload]);

        try {
            $this->_curl->addHeader("Content-Type", "application/json");
            $this->_curl->post($url, json_encode($payload));
            $response = $this->_curl->getBody();
            $responseArray = json_decode($response, true);
            $this->_logger->critical("InXpress response array", $responseArray);

            if (isset($responseArray["rates"][0]["total_price"])) {
                $responses = array();
                foreach ($responseArray["rates"] as $rate) {
                    $response = array();

                    $before_handling_price = $rate["total_price"] / 100;
                    $response['price'] = $this->addHandling($before_handling_price);
                    $response['days'] = $rate["display_sub_text"];
                    $response['service'] = $rate["display_text"];
                    $response['service_code'] = $rate["service_code"];
                    array_push($responses, $response);
                }
                return $responses;
            } else {
                $this->_logger->critical("InXpress error requesting rates", ['response' => $response]);
                return false;
            }
        } catch (\Exception $e) {
            $this->_logger->critical("InXpress error requesting rates", ['response' => $e->getMessage()]);
            return false;
        }
    }
}
