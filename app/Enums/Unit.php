<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum Unit: string
{
    use HasValues;

    case KG = 'KG';
    case GRAM = 'GRAM';
    case LITER = 'LITER';
    case ML = 'ML';
    case PIECE = 'PIECE';
}
