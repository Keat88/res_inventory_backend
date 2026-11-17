<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum RoleEnum: string
{
    use HasValues;

    case ADMIN = 'ADMIN';
    case MANAGER = 'MANAGER';
    case INVENTORY_STAFF = 'INVENTORY_STAFF';
    case CASHIER = 'CASHIER';
}
