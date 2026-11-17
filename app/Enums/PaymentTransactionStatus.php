<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PaymentTransactionStatus: string
{
    use HasValues;

    case PENDING = 'PENDING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
}
