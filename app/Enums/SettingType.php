<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum SettingType: string
{
    use HasValues;

    case STRING = 'string';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case JSON = 'json';
}
