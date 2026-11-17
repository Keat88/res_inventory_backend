<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum TransferStatus: string
{
    use HasValues;

    case DRAFT = 'DRAFT';
    case IN_TRANSIT = 'IN_TRANSIT';
    case RECEIVED = 'RECEIVED';
    case CANCELLED = 'CANCELLED';
}
