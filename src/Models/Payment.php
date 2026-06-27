<?php

namespace Hansajith18\LaravelPaycorp\Models;

use Hansajith18\LaravelPaycorp\Enums\GatewayPaymentStatusEnum;
use Hansajith18\LaravelPaycorp\Enums\PaymentPurposeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'purpose',
        'status',
        'gateway',
        'currency',
        'amount_cents',
        'client_ref',
        'gateway_req_id',
        'gateway_txn_reference',
        'gateway_response_code',
        'gateway_response_text',
        'gateway_auth_code',
        'card_type',
        'masked_card_number',
        'card_last4',
        'payment_page_url',
        'expire_at',
        'completed_at',
        'payable_type',
        'payable_id',
        'meta',
    ];

    protected $casts = [
        'status' => GatewayPaymentStatusEnum::class,
        'amount_cents' => 'integer',
        'meta' => 'array',
        'completed_at' => 'datetime',
        'expire_at' => 'datetime',
    ];

    /**
     * Merge the config-driven purpose enum cast so users can swap in their
     * own enum class via config('paycorp.purpose_class').
     */
    public function getCasts(): array
    {
        $casts = parent::getCasts();

        $purposeClass = config('paycorp.purpose_class', PaymentPurposeEnum::class);

        if (class_exists($purposeClass)) {
            $casts['purpose'] = $purposeClass;
        }

        return $casts;
    }

    public function user(): BelongsTo
    {
        $userModel = config('paycorp.user_model', 'App\\Models\\User');

        return $this->belongsTo($userModel);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function gatewayLogs(): HasMany
    {
        return $this->hasMany(PaymentGatewayLog::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', GatewayPaymentStatusEnum::PENDING);
    }

    public function scopeAwaitingCapture($query)
    {
        return $query->where('status', GatewayPaymentStatusEnum::AWAITING_CAPTURE);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', GatewayPaymentStatusEnum::COMPLETED);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isPending(): bool
    {
        return $this->status === GatewayPaymentStatusEnum::PENDING;
    }

    public function isAwaitingCapture(): bool
    {
        return $this->status === GatewayPaymentStatusEnum::AWAITING_CAPTURE;
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [
            GatewayPaymentStatusEnum::COMPLETED,
            GatewayPaymentStatusEnum::VOIDED,
        ], true);
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function getAmountDecimalAttribute(): float
    {
        return $this->amount_cents / 100;
    }
}
