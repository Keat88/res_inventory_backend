<?php

namespace Database\Seeders;

use App\Enums\DiscountType;
use App\Enums\ItemType;
use App\Enums\ModifierSelectionType;
use App\Enums\SettingType;
use App\Enums\Unit;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $main = Branch::query()->updateOrCreate(
            ['code' => 'PNH-01'],
            [
                'name' => 'POSMaster Phnom Penh',
                'phone' => '012345678',
                'email' => 'phnompenh@posmaster.test',
                'address' => 'Street 271, Phnom Penh',
                'city' => 'Phnom Penh',
                'country' => 'Cambodia',
                'timezone' => 'Asia/Phnom_Penh',
                'currency' => 'USD',
                'tax_rate' => 10,
                'service_charge_rate' => 0,
                'status' => 'ACTIVE',
                'is_default' => true,
            ]
        );

        $second = Branch::query()->updateOrCreate(
            ['code' => 'SR-01'],
            [
                'name' => 'POSMaster Siem Reap',
                'phone' => '098765432',
                'address' => 'Pub Street, Siem Reap',
                'city' => 'Siem Reap',
                'country' => 'Cambodia',
                'timezone' => 'Asia/Phnom_Penh',
                'currency' => 'USD',
                'tax_rate' => 10,
                'service_charge_rate' => 5,
                'status' => 'ACTIVE',
                'is_default' => false,
            ]
        );

        $this->user('Admin', 'admin@posmaster.test', 'ADMIN', [$main->id, $second->id]);
        $this->user('Manager', 'manager@posmaster.test', 'MANAGER', [$main->id, $second->id]);
        $this->user('Cashier', 'cashier@posmaster.test', 'CASHIER', [$main->id]);
        $this->user('Inventory Staff', 'inventory@posmaster.test', 'INVENTORY_STAFF', [$main->id, $second->id]);

        $food = Category::query()->updateOrCreate(['slug' => 'food'], ['name' => 'Food', 'sort_order' => 1, 'status' => 'ACTIVE']);
        $drinks = Category::query()->updateOrCreate(['slug' => 'drinks'], ['name' => 'Drinks', 'sort_order' => 2, 'status' => 'ACTIVE']);

        $friedRice = MenuItem::query()->updateOrCreate(
            ['slug' => 'fried-rice'],
            [
                'category_id' => $food->id,
                'name' => 'Fried Rice',
                'description' => 'Wok-fried rice with egg and vegetables',
                'base_price' => 3.50,
                'item_type' => ItemType::FOOD->value,
                'prep_time_minutes' => 12,
                'is_available' => true,
                'status' => 'ACTIVE',
            ]
        );

        $coffee = MenuItem::query()->updateOrCreate(
            ['slug' => 'iced-coffee'],
            [
                'category_id' => $drinks->id,
                'name' => 'Iced Coffee',
                'description' => 'Cambodian style iced coffee',
                'base_price' => 2.00,
                'item_type' => ItemType::DRINK->value,
                'prep_time_minutes' => 4,
                'is_available' => true,
                'status' => 'ACTIVE',
            ]
        );

        $coffee->variants()->updateOrCreate(['code' => 'S'], ['name' => 'Small', 'price' => 1.75, 'is_default' => true, 'sort_order' => 1]);
        $coffee->variants()->updateOrCreate(['code' => 'L'], ['name' => 'Large', 'price' => 2.25, 'is_default' => false, 'sort_order' => 2]);

        $sugar = ModifierGroup::query()->updateOrCreate(
            ['name' => 'Sugar Level'],
            [
                'selection_type' => ModifierSelectionType::SINGLE->value,
                'min_selection' => 1,
                'max_selection' => 1,
                'is_required' => true,
                'sort_order' => 1,
                'status' => 'ACTIVE',
            ]
        );

        foreach (['0%' => 0, '50%' => 0, '100%' => 0] as $index => $price) {
            $sugar->options()->updateOrCreate(
                ['name' => $index],
                ['price' => $price, 'is_default' => $index === '50%', 'sort_order' => $price + 1]
            );
        }

        $coffee->modifierGroups()->syncWithoutDetaching([$sugar->id => ['sort_order' => 1]]);

        $rice = Ingredient::query()->updateOrCreate(['name' => 'Jasmine Rice'], ['sku' => 'ING-RICE', 'unit' => Unit::KG->value, 'cost_per_unit' => 1.20, 'status' => 'ACTIVE']);
        $egg = Ingredient::query()->updateOrCreate(['name' => 'Egg'], ['sku' => 'ING-EGG', 'unit' => Unit::PIECE->value, 'cost_per_unit' => 0.15, 'status' => 'ACTIVE']);
        $bean = Ingredient::query()->updateOrCreate(['name' => 'Coffee Bean'], ['sku' => 'ING-BEAN', 'unit' => Unit::KG->value, 'cost_per_unit' => 8.50, 'status' => 'ACTIVE']);
        $milk = Ingredient::query()->updateOrCreate(['name' => 'Condensed Milk'], ['sku' => 'ING-MILK', 'unit' => Unit::LITER->value, 'cost_per_unit' => 3.20, 'status' => 'ACTIVE']);
        $ice = Ingredient::query()->updateOrCreate(['name' => 'Ice'], ['sku' => 'ING-ICE', 'unit' => Unit::KG->value, 'cost_per_unit' => 0.30, 'status' => 'ACTIVE']);

        foreach ([$main, $second] as $branch) {
            foreach ([
                [$rice, 25, 5],
                [$egg, 120, 24],
                [$bean, 10, 2],
                [$milk, 15, 3],
                [$ice, 30, 5],
            ] as [$ingredient, $quantity, $min]) {
                $branch->inventories()->updateOrCreate(
                    ['ingredient_id' => $ingredient->id],
                    ['quantity' => $quantity, 'min_stock' => $min]
                );
            }
        }

        if (! $friedRice->recipes()->exists()) {
            $recipe = $friedRice->recipes()->create(['version' => 1, 'yield_quantity' => 1, 'is_active' => true]);
            $recipe->ingredients()->createMany([
                ['ingredient_id' => $rice->id, 'quantity' => 0.200, 'unit' => Unit::KG->value, 'wastage_percent' => 2],
                ['ingredient_id' => $egg->id, 'quantity' => 1, 'unit' => Unit::PIECE->value, 'wastage_percent' => 0],
            ]);
        }

        if (! $coffee->recipes()->exists()) {
            $recipe = $coffee->recipes()->create(['version' => 1, 'yield_quantity' => 1, 'is_active' => true]);
            $recipe->ingredients()->createMany([
                ['ingredient_id' => $bean->id, 'quantity' => 0.018, 'unit' => Unit::KG->value, 'wastage_percent' => 3],
                ['ingredient_id' => $milk->id, 'quantity' => 0.030, 'unit' => Unit::LITER->value, 'wastage_percent' => 0],
                ['ingredient_id' => $ice->id, 'quantity' => 0.100, 'unit' => Unit::KG->value, 'wastage_percent' => 0],
            ]);
        }

        Discount::query()->updateOrCreate(
            ['code' => 'WELCOME10'],
            [
                'branch_id' => null,
                'name' => 'Welcome 10%',
                'type' => DiscountType::PERCENTAGE->value,
                'value' => 10,
                'min_order_total' => 5,
                'max_discount_amount' => 2,
                'usage_limit' => 100,
                'is_active' => true,
            ]
        );

        $main->tables()->updateOrCreate(['table_number' => 'T01'], ['name' => 'Window Table', 'capacity' => 4, 'area' => 'Main Hall', 'status' => 'AVAILABLE']);
        $main->tables()->updateOrCreate(['table_number' => 'T02'], ['name' => 'Corner Table', 'capacity' => 2, 'area' => 'Main Hall', 'status' => 'AVAILABLE']);
        $second->tables()->updateOrCreate(['table_number' => 'T01'], ['name' => 'Garden Table', 'capacity' => 4, 'area' => 'Garden', 'status' => 'AVAILABLE']);

        foreach ([$main, $second] as $branch) {
            foreach ([
                ['receipt_header', 'Thank you for dining with POSMaster!', SettingType::STRING, true],
                ['currency_symbol', '$', SettingType::STRING, true],
                ['service_charge_enabled', $branch->service_charge_rate > 0, SettingType::BOOLEAN, true],
            ] as [$key, $value, $type, $public]) {
                SystemSetting::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'key' => $key],
                    [
                        'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                        'type' => $type->value,
                        'is_public' => $public,
                    ]
                );
            }
        }
    }

    protected function user(string $name, string $email, string $role, array $branchIds): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => 'password',
                'status' => 'ACTIVE',
            ]
        );

        $user->syncRoles([$role]);
        $user->branches()->sync(collect($branchIds)->mapWithKeys(
            fn ($id) => [$id => ['is_primary' => $id === $branchIds[0], 'started_at' => now()]]
        )->all());

        return $user;
    }
}
