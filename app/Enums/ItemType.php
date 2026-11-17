<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum ItemType: string
{
    use HasValues;

    case FOOD = 'FOOD';
    case DRINK = 'DRINK';
    case COMBO = 'COMBO';
    case OTHER = 'OTHER';
}
