<?php

namespace Hansajith18\LaravelPaycorp\Enums;

enum GatewayPaymentStatusEnum: string
{
    case PENDING = 'pending';
    case AWAITING_CAPTURE = 'awaiting_capture';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
    /** Payment completed then immediately voided/reversed (e.g. card-registration verification charge). */
    case VOIDED = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::AWAITING_CAPTURE => 'Awaiting Capture',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
            self::EXPIRED => 'Expired',
            self::VOIDED => 'Voided',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::EXPIRED, self::VOIDED]);
    }
}
