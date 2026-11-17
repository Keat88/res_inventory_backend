<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum OrderType: string
{
    use HasValues;

    case DINE_IN = 'DINE_IN';
    case TAKEAWAY = 'TAKEAWAY';
    case DELIVERY = 'DELIVERY';
}
