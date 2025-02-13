<?php

namespace StripeModule;


use Address;
use Cart;
use Configuration;
use Context;
use Country;
use PrestaShopException;
use ReflectionClass;
use SmartyException;
use Stripe\Exception\ApiErrorException;
use Tools;
use Translate;
use Validate;

abstract class PaymentMethod
{
    const ALL = [];

    /**
     * @var StripeApi
     */
    private StripeApi $stripeApi;

    /**
     * @param StripeApi $stripeApi
     */
    public function __construct(StripeApi $stripeApi)
    {
        $this->stripeApi = $stripeApi;
    }

    /**
     * @return string
     */
    public abstract function getMethodId(): string;


    /**
     * @return string
     */
    public abstract function getName(): string;


    /**
     * Validates if this method can be used with cart $cart. Returns list of errors,
     * or empty array on success
     *
     * @param Cart $cart
     *
     * @return string[]
     * @throws PrestaShopException
     */
    public function validateMethod(Cart $cart): array
    {
        $errors = [];

        if (! Configuration::get($this->getConfigurationKey())) {
            $errors[] = sprintf(
                Tools::displayError('Payment method %s is not enabled'),
                $this->getName()
            );
        }

        if( ! $this->checkStripeSideMethodAvailability()){
            $errors[] = sprintf(
                Tools::displayError('Payment method %s is unavailable on stripe-side. Please check if not activated or ineligible.'),
                $this->getName()
            );
        }

        $allowedCurrencies = $this->getAllowedCurrencies();
        if ($allowedCurrencies && !Utils::checkAllowedCurrency($cart, $allowedCurrencies)) {
            $errors[] = sprintf(
                Tools::displayError("%s payment method can only be used with following currencies: %s"),
                $this->getName(),
                implode(", ", $allowedCurrencies)
            );
        }

        $allowedAccountCountries = array_map('strtoupper', $this->getAllowedAccountCountries());
        if ($allowedAccountCountries && !in_array(strtoupper(Utils::getStripeCountry()), $allowedAccountCountries)) {
            $errors[] = sprintf(
                Tools::displayError("%s payment method can only be used for stripe accounts with following countries: %s"),
                $this->getName(),
                implode(", ", $allowedAccountCountries)
            );
        }

        $allowedCustomerCountries = array_map('strtoupper', $this->getAllowedCustomerCountries());
        if ($allowedCustomerCountries) {
            $invoiceAddress = new Address((int) $cart->id_address_invoice);
            $country = new Country($invoiceAddress->id_country);
            if (!in_array(strtoupper((string)$country->iso_code), $allowedCustomerCountries)) {
                $errors[] = sprintf(
                    Tools::displayError("%s payment method can only be used by customers from following countries: %s"),
                    $this->getName(),
                    implode(", ", $allowedCustomerCountries)
                );
            }
        }

        $restrictions = $this->getCurrencyCountryRestrictions();
        if ($restrictions) {
            $currency = strtoupper(Utils::getCurrencyCode($cart));
            if (isset($restrictions[$currency])) {
                $restrictedCountries = $restrictions[$currency];
                $accountCountry = Utils::getStripeCountry();
                if (! in_array($accountCountry, $restrictedCountries)) {
                    $errors[] = sprintf(
                        Tools::displayError("%s payment method can't be used with currency %s"),
                        $this->getName(),
                        $currency
                    );
                }
            }
        }

        return $errors;
    }

    public function checkStripeSideMethodAvailability(): bool
    {
        $stripe = new \Stripe\StripeClient(Configuration::get(\Stripe::GO_LIVE)
            ? Configuration::get(\Stripe::SECRET_KEY_LIVE)
            : Configuration::get(\Stripe::SECRET_KEY_TEST));

        $pmcs = $stripe->paymentMethodConfigurations->all([]);
        foreach($pmcs->data as $pmc){
            if($pmc->active = 1 && $pmc->is_default = 1){
                foreach ($pmc->keys() as $key) {
                    if(is_object($pmc->$key) && get_class($pmc->$key) == "Stripe\StripeObject" && $pmc->$key->available == 1) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @return string[]
     */
    protected abstract function getAllowedCurrencies(): array;

    /**
     * @return string[]
     */
    protected abstract function getAllowedAccountCountries(): array;

    /**
     * @return array
     */
    protected abstract function getAllowedCustomerCountries(): array;

    /**
     * @return array
     */
    protected function getCurrencyCountryRestrictions(): array
    {
        return [];
    }

    /**
     * @param Cart $cart
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function isAvailable(Cart $cart): bool
    {
        $errors = $this->validateMethod($cart);
        return !$errors;
    }
    /**
     * Executes api
     *
     * @param Cart $cart
     *
     * @return void
     */
    public abstract function executeMethod(Cart $cart): ExecutionResult;

    /**
     * @return string
     */
    public function getImageFile(): string
    {
        return $this->getMethodId() . '.png';
    }

    /**
     * @return string
     */
    public function getPaymentTemplateName(): string
    {
        return $this->getMethodId() . '.tpl';
    }

    /**
     * @return void
     */
    public function getJavascriptUris(): array
    {
        return [];
    }

    /**
     * @return array
     */
    public function getCssUris(): array
    {
        return [];
    }

    /**
     * @param string $string
     * @param string|null $source
     *
     * @return string
     */
    protected function l($string, $source = null): string
    {
        if (is_null($source)) {
            $source = (new ReflectionClass($this))->getShortName();
        }
        return Translate::getModuleTranslation('stripe', $string, $source);
    }

    /**
     * @return string
     * @throws PrestaShopException
     */
    public function getLink(): string
    {
        $link = Context::getContext()->link;
        return $link ->getModuleLink('stripe', 'payment', [ 'method' => $this->getMethodId() ], true);
    }

    /**
     * @return string
     * @throws PrestaShopException
     */
    public function getImageLink(): string
    {
        $link = Context::getContext()->link;
        $uri = _MODULE_DIR_ . '/stripe/views/img/' . $this->getImageFile();
        return $link->getMediaLink($uri);
    }

    /**
     * @param Cart $cart
     * @param string $stripeMethod
     * @param array $paymentMethodData
     *
     * @return ExecutionResult
     * @throws PrestaShopException
     */
    protected function startRedirectPaymentFlow(
        Cart $cart,
        string $stripeMethod,
        array $paymentMethodData
    ): ExecutionResult
    {
        try {
            $paymentMethodData['type'] = $stripeMethod;
            $paymentIntent = $this->getStripeApi()->createPaymentIntent(
                $cart,
                $stripeMethod,
                $paymentMethodData,
                Utils::getValidationUrl($this->getMethodId())
            );

            $redirectUrl = Utils::extractRedirectUrl($paymentIntent);
            if ($redirectUrl) {
                $metadata = PaymentMetadata::createForPaymentIntent($this->getMethodId(), $cart, $paymentIntent);
                return ExecutionResult::redirect($metadata, $redirectUrl);
            } else {
                return ExecutionResult::error("Stripe response does not contain redirect url");
            }
        } catch (ApiErrorException $e) {
            return $this->handleApiException($e);
        }
    }

    /**
     * This method is used to render payment option from hookDisplayPayment hoook
     *
     * By default, it will look for file '{payment_method}.tpl inside theme and module directory
     * If none is found, fallback to display-payment-method.tpl generic impelementation
     *
     * @param Cart $cart
     *
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function renderPaymentMethod(Cart $cart): string
    {

        $template = $this->getPaymentTemplateName();
        $candidates = [
            'views/templates/hook/'.$template,
            'views/templates/hook/display-payment-method.tpl',
        ];

        foreach ($candidates as $candidate) {
            $template = $this->findTemplate($candidate);
            if ($template) {
                $template->assign($this->getPaymentTemplateParameters($cart));
                return $template->fetch();
            }
        }

        throw new PrestaShopException("No template found for payment method");
    }

    /**
     * @param string $relativePath
     *
     * @return \Smarty_Internal_Template|null
     *
     * @throws SmartyException
     */
    protected function findTemplate(string $relativePath)
    {
        $candidates = [
            _PS_THEME_DIR_.'modules/stripe/'. ltrim($relativePath, '/\\'),
            _PS_MODULE_DIR_.'stripe/' . ltrim($relativePath, '/\\'),
        ];
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $smarty = Context::getContext()->smarty;
                return $smarty->createTemplate($candidate);
            }
        }
        return null;
    }

    /**
     * @param string $relativePath
     *
     * @return \Smarty_Internal_Template
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    protected function getTemplate(string $relativePath)
    {
        $template = $this->findTemplate($relativePath);
        if (! $template) {
            throw new PrestaShopException("Template $relativePath not found");
        }
        return $template;
    }

    /**
     * @param Cart $cart
     *
     * @return array
     * @throws PrestaShopException
     */
    protected function getPaymentTemplateParameters(Cart $cart): array
    {
       return [
           'id' => $this->getMethodId(),
           'name' => $this->getName(),
           'cta' => $this->getCTA(),
           'paymentLink' => $this->getLink(),
           'img' => $this->getImageLink()
       ];
    }

    /**
     * @return string
     */
    public function getCTA(): string
    {
        return sprintf($this->l('Pay with %s', 'PaymentMethod'), $this->getName());
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return sprintf($this->l('Accept payments through %s', 'PaymentMethod'), $this->getName());
    }


    /**
     * @return bool
     */
    public function requiresWebhook(): bool
    {
        return false;
    }

    /**
     * @return StripeApi
     */
    public function getStripeApi(): StripeApi
    {
        return $this->stripeApi;
    }


    /**
     * @return string
     */
    public function getShortName(): string
    {
        return $this->getName();
    }

    /**
     * @return bool
     * @throws PrestaShopException
     */
    public function isEnabled(): bool
    {
        return (bool)Configuration::get($this->getConfigurationKey());
    }

    /**
     * @param bool $enabled
     *
     * @return $this
     * @throws PrestaShopException
     */
    public function setEnabled(bool $enabled)
    {
        Configuration::updateValue($this->getConfigurationKey(), (int)$enabled);
        return $this;
    }

    /**
     * @return void
     * @throws PrestaShopException
     */
    public function cleanConfiguration()
    {
        foreach ($this->getAllConfigurationKeys() as $key) {
            Configuration::deleteByName($key);
        }
    }

    /**
     * @return string
     */
    protected function getConfigurationKey(): string
    {
        return 'STRIPE_' . strtoupper($this->getMethodId());
    }

    /**
     * @return string[]
     */
    protected function getAllConfigurationKeys()
    {
        return [
            $this->getConfigurationKey()
        ];
    }

    /**
     * @return string
     */
    public function getDocLink(): string
    {
        return "https://docs.stripe.com/payments/" . str_replace('_', '-', $this->getMethodId());
    }

    /**
     * @param $e
     *
     * @return ExecutionResult
     */
    protected function handleApiException($e): ExecutionResult
    {
        $error = $e->getError();
        if ($error && $error->message) {
            return ExecutionResult::error($error->message);
        } else {
            return ExecutionResult::error("Stripe responsed with error message");
        }
    }




}