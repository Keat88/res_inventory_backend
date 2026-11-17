<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum StockMovementType: string
{
    use HasValues;

    case PURCHASE_IN = 'PURCHASE_IN';
    case SALE_OUT = 'SALE_OUT';
    case TRANSFER_IN = 'TRANSFER_IN';
    case TRANSFER_OUT = 'TRANSFER_OUT';
    case ADJUSTMENT = 'ADJUSTMENT';
    case WASTE = 'WASTE';

    public function isInbound(): bool
    {
        return in_array($this, [self::PURCHASE_IN, self::TRANSFER_IN], true);
    }
}
