<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PurchaseOrderStatus: string
{
    use HasValues;

    case DRAFT = 'DRAFT';
    case ORDERED = 'ORDERED';
    case PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';
    case RECEIVED = 'RECEIVED';
    case CANCELLED = 'CANCELLED';
}
