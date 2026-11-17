<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum CashMovementType: string
{
    use HasValues;

    case CASH_IN = 'CASH_IN';
    case CASH_OUT = 'CASH_OUT';
}
