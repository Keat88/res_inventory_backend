<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum Currency: string
{
    use HasValues;

    case USD = 'USD';
    case KHR = 'KHR';
}
