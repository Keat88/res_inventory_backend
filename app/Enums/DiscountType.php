<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum DiscountType: string
{
    use HasValues;

    case PERCENTAGE = 'PERCENTAGE';
    case FIXED = 'FIXED';
}
