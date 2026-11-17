<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PaymentStatus: string
{
    use HasValues;

    case UNPAID = 'UNPAID';
    case PENDING = 'PENDING';
    case AUTHORIZED = 'AUTHORIZED';
    case PARTIAL = 'PARTIAL';
    case PAID = 'PAID';
    case FAILED = 'FAILED';
    case REFUNDED = 'REFUNDED';
    case PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';
}
