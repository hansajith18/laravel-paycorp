<?php

namespace Hansajith18\LaravelPaycorp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedPayment extends Model
{
    protected $fillable = [
        'user_id',
        'gateway',
        'token',
        'card_type',
        'masked_card_number',
        'card_last4',
        'card_expiry',
        'card_holder_name',
        'token_reference',
    ];

    public function user(): BelongsTo
    {
        $userModel = config('paycorp.user_model', 'App\\Models\\User');

        return $this->belongsTo($userModel);
    }
}
