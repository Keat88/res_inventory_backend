<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PaymentMethod: string
{
    use HasValues;

    case CASH = 'CASH';
    case KHQR = 'KHQR';
    case BANK_CARD = 'BANK_CARD';
}
