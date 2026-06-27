<?php

namespace Hansajith18\LaravelPaycorp\Enums;

use Hansajith18\LaravelPaycorp\Contracts\PaymentPurposeContract;

/**
 * Built-in payment purposes shipped with the package.
 *
 * You can replace this enum entirely with your own by implementing
 * PaymentPurposeContract and setting 'purpose_class' in config/paycorp.php.
 */
enum PaymentPurposeEnum: string implements PaymentPurposeContract
{
    case PURCHASE = 'purchase';
    case SUBSCRIPTION = 'subscription';
    case CARD_REGISTRATION = 'card_registration';
    case WALLET_TOPUP = 'wallet_topup';
    case REFUND = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE => 'Purchase',
            self::SUBSCRIPTION => 'Subscription',
            self::CARD_REGISTRATION => 'Card Registration',
            self::WALLET_TOPUP => 'Wallet Top-up',
            self::REFUND => 'Refund',
        };
    }
}
