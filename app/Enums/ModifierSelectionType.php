<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum ModifierSelectionType: string
{
    use HasValues;

    case SINGLE = 'SINGLE';
    case MULTIPLE = 'MULTIPLE';
}
