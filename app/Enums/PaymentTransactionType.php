<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PaymentTransactionType: string
{
    use HasValues;

    case CHARGE = 'CHARGE';
    case CONFIRM = 'CONFIRM';
    case REFUND = 'REFUND';
    case VOID = 'VOID';
}
