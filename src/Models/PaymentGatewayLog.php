<?php

namespace Hansajith18\LaravelPaycorp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentGatewayLog extends Model
{
    protected $fillable = [
        'payment_id',
        'operation',
        'direction',
        'status_code',
        'payload',
        'raw_response',
        'duration_ms',
    ];

    protected $casts = [
        'payload' => 'array',
        'raw_response' => 'array',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
