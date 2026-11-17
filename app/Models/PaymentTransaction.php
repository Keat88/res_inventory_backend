<?php

namespace App\Models;

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => PaymentTransactionType::class,
            'status' => PaymentTransactionStatus::class,
            'amount' => 'decimal:2',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
