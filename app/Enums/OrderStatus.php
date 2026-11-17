<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum OrderStatus: string
{
    use HasValues;

    case DRAFT = 'DRAFT';
    case HELD = 'HELD';
    case SENT_TO_KITCHEN = 'SENT_TO_KITCHEN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::DRAFT => in_array($to, [self::HELD, self::SENT_TO_KITCHEN, self::CANCELLED], true),
            self::HELD => in_array($to, [self::DRAFT, self::SENT_TO_KITCHEN, self::CANCELLED], true),
            self::SENT_TO_KITCHEN => in_array($to, [self::IN_PROGRESS, self::CANCELLED], true),
            self::IN_PROGRESS => in_array($to, [self::COMPLETED, self::CANCELLED], true),
            self::COMPLETED, self::CANCELLED => false,
        };
    }
}
