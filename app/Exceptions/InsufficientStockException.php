<?php

namespace App\Exceptions;

class InsufficientStockException extends BusinessException
{
    public function __construct(string $ingredient, float $required, float $available)
    {
        parent::__construct(
            'Insufficient stock for '.$ingredient,
            'INSUFFICIENT_STOCK',
            [
                'ingredient' => $ingredient,
                'required' => $required,
                'available' => $available,
            ],
            409
        );
    }
}
