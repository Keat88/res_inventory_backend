<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum ShiftStatus: string
{
    use HasValues;

    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
}
