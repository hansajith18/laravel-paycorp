<?php

namespace Hansajith18\LaravelPaycorp\Contracts;

/**
 * Implement this interface on your own string-backed enum to use custom
 * payment purposes with PaycenterPaymentService.
 *
 * Example:
 *
 *   enum MyPurpose: string implements PaymentPurposeContract
 *   {
 *       case ORDER_PAYMENT = 'order_payment';
 *       case DEPOSIT       = 'deposit';
 *
 *       public function label(): string
 *       {
 *           return match($this) {
 *               self::ORDER_PAYMENT => 'Order Payment',
 *               self::DEPOSIT       => 'Deposit',
 *           };
 *       }
 *   }
 *
 * Then set in config/paycorp.php:
 *   'purpose_class' => \App\Enums\MyPurpose::class,
 */
interface PaymentPurposeContract
{
    /**
     * Human-readable label used as the payment comment sent to the gateway.
     */
    public function label(): string;
}
