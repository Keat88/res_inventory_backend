<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum KitchenStatus: string
{
    use HasValues;

    case NEW = 'NEW';
    case ACCEPTED = 'ACCEPTED';
    case PREPARING = 'PREPARING';
    case READY = 'READY';
    case COMPLETED = 'COMPLETED';
}
