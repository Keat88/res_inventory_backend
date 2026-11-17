<?php

namespace App\Enums\Concerns;

trait HasValues
{
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
