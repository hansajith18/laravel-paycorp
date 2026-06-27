<?php

namespace Hansajith18\LaravelPaycorp\Enums;

enum PaycorpResponseCode: string
{
    case APPROVED = '00';
    case TOKEN_EXISTS_EXPIRY_UPDATED = '01';
    case PREAUTH_FAILED_TOKEN_GENERATED = '02';

    // Unsuccessful codes
    case INSUFFICIENT_PARAMETERS = '20';
    case INTERNAL_ERROR = '21';
    case INVALID_CLIENT_ID = '22';
    case TOKEN_NOT_FOUND_DELETE = '23';
    case INVALID_EXPIRY_OR_PREAUTH = '24';
    case TOKEN_NOT_FOUND_UPDATE = '25';
    case TOKEN_NOT_FOUND = '26';
    case PREAUTH_FAILED_NO_TOKEN = '27';
    case ACTION_NOT_PERMITTED = '28';
    case TOO_MANY_ATTEMPTS = '29';
    case INVALID_CARD_DATA = '30';

    public function isSuccessful(): bool
    {
        return in_array($this, [
            self::APPROVED,
            self::TOKEN_EXISTS_EXPIRY_UPDATED,
            self::PREAUTH_FAILED_TOKEN_GENERATED,
        ]);
    }

    public function isApproved(): bool
    {
        return $this === self::APPROVED;
    }

    public function label(): string
    {
        return match ($this) {
            self::APPROVED => 'Transaction Approved',
            self::TOKEN_EXISTS_EXPIRY_UPDATED => 'Token exists, expiry updated',
            self::PREAUTH_FAILED_TOKEN_GENERATED => 'Pre-auth failed, token generated',
            self::INSUFFICIENT_PARAMETERS => 'Insufficient parameters',
            self::INTERNAL_ERROR => 'Internal error',
            self::INVALID_CLIENT_ID => 'Invalid client ID',
            self::TOKEN_NOT_FOUND_DELETE => 'Token not found for deletion',
            self::INVALID_EXPIRY_OR_PREAUTH => 'Invalid expiry or failed pre-auth',
            self::TOKEN_NOT_FOUND_UPDATE => 'Token not found for update',
            self::TOKEN_NOT_FOUND => 'Token not found',
            self::PREAUTH_FAILED_NO_TOKEN => 'Pre-auth failed, no token generated',
            self::ACTION_NOT_PERMITTED => 'Action not permitted for client',
            self::TOO_MANY_ATTEMPTS => 'Too many retrieval attempts',
            self::INVALID_CARD_DATA => 'Invalid card data',
        };
    }
}
