<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum TableStatus: string
{
    use HasValues;

    case AVAILABLE = 'AVAILABLE';
    case OCCUPIED = 'OCCUPIED';
    case RESERVED = 'RESERVED';
}
