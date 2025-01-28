<?php

namespace StripeModule\PaymentMethod;

use Address;
use Cart;
use Country;
use Context;
use PrestaShopException;
use StripeModule\ExecutionResult;
use StripeModule\PaymentMethod;
use StripeModule\Utils;

class TwintMethod extends PaymentMethod
{
    const METHOD_ID = 'twint';

    /**
     * @return string
     */
    public function getMethodId(): string
    {
        return static::METHOD_ID;
    }

    /**
     * @return string[]
     */
    protected function getAllowedCurrencies(): array
    {
        return ['CHF'];
    }

    /**
     * @return array|string[]
     */
    protected function getAllowedAccountCountries(): array
    {
        return static::ALL;
    }

    /**
     * @return string[]
     */
    protected function getAllowedCustomerCountries(): array
    {
        return ['CH'];
    }

    /**
     * @param Cart $cart
     *
     * @return ExecutionResult
     * @throws PrestaShopException
     */
    public function executeMethod(Cart $cart): ExecutionResult
    {
        $invoiceAddress = new Address((int) $cart->id_address_invoice);
        $country = new Country($invoiceAddress->id_country);

        return $this->startRedirectPaymentFlow($cart, 'twint',
            [
                'billing_details' => [
                    'name' => Utils::getCustomerName($cart),
                ]
            ]
        );
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->l('Twint');
    }

    /**
     * @return bool
     */
    public function requiresWebhook(): bool
    {
        return false;
    }
	


}