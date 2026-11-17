<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Events\LowStockDetected;
use App\Exceptions\InsufficientStockException;
use App\Models\BranchInventory;
use App\Models\Order;
use App\Models\Recipe;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Deduct ingredients for every order line that has an active recipe.
     * Idempotent per order item ingredient through the stock movement unique index.
     */
    public function deductForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->loadMissing('items');
            $requirements = [];
            $recipeCache = [];

            foreach ($order->items as $item) {
                if (! $item->menu_item_id) {
                    continue;
                }

                $recipe = $recipeCache[$item->menu_item_id]
                    ??= Recipe::query()
                        ->with('ingredients')
                        ->where('menu_item_id', $item->menu_item_id)
                        ->where('is_active', true)
                        ->latest('version')
                        ->first();

                if (! $recipe) {
                    continue;
                }

                $yield = max((float) $recipe->yield_quantity, 0.001);

                foreach ($recipe->ingredients as $recipeIngredient) {
                    $perUnit = ((float) $recipeIngredient->quantity
                        * (1 + (float) $recipeIngredient->wastage_percent / 100)) / $yield;
                    $required = $perUnit * (float) $item->quantity;
                    $key = $recipeIngredient->ingredient_id;
                    $requirements[$key] = ($requirements[$key] ?? 0) + $required;
                }
            }

            ksort($requirements);

            foreach ($requirements as $ingredientId => $requiredRaw) {
                $required = round($requiredRaw, 3);

                if ($required <= 0) {
                    continue;
                }

                $alreadyDeducted = StockMovement::query()
                    ->where('branch_id', $order->branch_id)
                    ->where('reference_type', 'order')
                    ->where('reference_id', $order->id)
                    ->where('ingredient_id', $ingredientId)
                    ->where('type', StockMovementType::SALE_OUT->value)
                    ->exists();

                if ($alreadyDeducted) {
                    continue;
                }

                $inventory = BranchInventory::query()
                    ->where('branch_id', $order->branch_id)
                    ->where('ingredient_id', $ingredientId)
                    ->lockForUpdate()
                    ->first();

                if (! $inventory) {
                    $inventory = BranchInventory::query()->firstOrCreate(
                        ['branch_id' => $order->branch_id, 'ingredient_id' => $ingredientId],
                        ['quantity' => 0, 'min_stock' => 0]
                    );
                    $inventory = BranchInventory::query()->whereKey($inventory->id)->lockForUpdate()->first();
                }

                $available = (float) $inventory->quantity;

                if ($available < $required) {
                    throw new InsufficientStockException(
                        $inventory->ingredient->name,
                        $required,
                        $available
                    );
                }

                $newBalance = round($available - $required, 3);
                $inventory->quantity = $newBalance;
                $inventory->last_movement_at = now();
                $inventory->save();

                StockMovement::query()->create([
                    'branch_id' => $order->branch_id,
                    'ingredient_id' => $ingredientId,
                    'type' => StockMovementType::SALE_OUT->value,
                    'quantity' => -$required,
                    'balance_after' => $newBalance,
                    'reference_type' => 'order',
                    'reference_id' => $order->id,
                    'note' => 'Automatic deduction for order '.$order->order_number,
                    'created_by' => $order->cashier_id,
                    'occurred_at' => now(),
                ]);

                $this->maybeDispatchLowStock($inventory, $available);
            }
        });
    }

    /**
     * Reverse SALE_OUT movements for a cancelled/refunded order.
     */
    public function reverseForOrder(Order $order, string $reason = 'Order cancelled'): void
    {
        DB::transaction(function () use ($order, $reason) {
            $movements = StockMovement::query()
                ->where('branch_id', $order->branch_id)
                ->where('reference_type', 'order')
                ->where('reference_id', $order->id)
                ->where('type', StockMovementType::SALE_OUT->value)
                ->orderBy('ingredient_id')
                ->get();

            foreach ($movements as $movement) {
                $alreadyReversed = StockMovement::query()
                    ->where('branch_id', $order->branch_id)
                    ->where('reference_type', 'order_reversal')
                    ->where('reference_id', $order->id)
                    ->where('ingredient_id', $movement->ingredient_id)
                    ->exists();

                if ($alreadyReversed) {
                    continue;
                }

                $quantity = abs((float) $movement->quantity);

                $inventory = BranchInventory::query()
                    ->where('branch_id', $order->branch_id)
                    ->where('ingredient_id', $movement->ingredient_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $newBalance = round((float) $inventory->quantity + $quantity, 3);
                $inventory->quantity = $newBalance;
                $inventory->last_movement_at = now();
                $inventory->save();

                StockMovement::query()->create([
                    'branch_id' => $order->branch_id,
                    'ingredient_id' => $movement->ingredient_id,
                    'type' => StockMovementType::ADJUSTMENT->value,
                    'quantity' => $quantity,
                    'balance_after' => $newBalance,
                    'reference_type' => 'order_reversal',
                    'reference_id' => $order->id,
                    'reversal_of_id' => $movement->id,
                    'note' => $reason,
                    'created_by' => $order->cashier_id,
                    'occurred_at' => now(),
                ]);
            }
        });
    }

    public function adjust(
        int $branchId,
        int $ingredientId,
        float $quantityChange,
        StockMovementType $type,
        ?int $userId = null,
        ?string $note = null
    ): StockMovement {
        return DB::transaction(function () use ($branchId, $ingredientId, $quantityChange, $type, $userId, $note) {
            $inventory = BranchInventory::query()
                ->where('branch_id', $branchId)
                ->where('ingredient_id', $ingredientId)
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                $inventory = BranchInventory::query()->firstOrCreate(
                    ['branch_id' => $branchId, 'ingredient_id' => $ingredientId],
                    ['quantity' => 0, 'min_stock' => 0]
                );
                $inventory = BranchInventory::query()->whereKey($inventory->id)->lockForUpdate()->first();
            }

            $available = (float) $inventory->quantity;
            $newBalance = round($available + $quantityChange, 3);

            if ($newBalance < 0) {
                throw new InsufficientStockException($inventory->ingredient->name, abs($quantityChange), $available);
            }

            $inventory->quantity = $newBalance;
            $inventory->last_movement_at = now();
            $inventory->save();

            $movement = StockMovement::query()->create([
                'branch_id' => $branchId,
                'ingredient_id' => $ingredientId,
                'type' => $type->value,
                'quantity' => $quantityChange,
                'balance_after' => $newBalance,
                'reference_type' => 'manual',
                'note' => $note,
                'created_by' => $userId,
                'occurred_at' => now(),
            ]);

            $this->maybeDispatchLowStock($inventory, $available);

            return $movement;
        });
    }

    public function receiveStock(
        int $branchId,
        int $ingredientId,
        float $quantity,
        float $unitCost,
        string $referenceType,
        int $referenceId,
        ?int $userId = null,
        ?string $idempotencyKey = null
    ): StockMovement {
        return DB::transaction(function () use ($branchId, $ingredientId, $quantity, $unitCost, $referenceType, $referenceId, $userId, $idempotencyKey) {
            $inventory = BranchInventory::query()
                ->where('branch_id', $branchId)
                ->where('ingredient_id', $ingredientId)
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                $inventory = BranchInventory::query()->firstOrCreate(
                    ['branch_id' => $branchId, 'ingredient_id' => $ingredientId],
                    ['quantity' => 0, 'min_stock' => 0]
                );
                $inventory = BranchInventory::query()->whereKey($inventory->id)->lockForUpdate()->first();
            }

            $newBalance = round((float) $inventory->quantity + $quantity, 3);
            $inventory->quantity = $newBalance;
            $inventory->last_movement_at = now();
            $inventory->save();

            return StockMovement::query()->create([
                'branch_id' => $branchId,
                'ingredient_id' => $ingredientId,
                'type' => StockMovementType::PURCHASE_IN->value,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'balance_after' => $newBalance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $userId,
                'occurred_at' => now(),
            ]);
        });
    }

    public function transferOut(
        int $branchId,
        int $ingredientId,
        float $quantity,
        int $transferId,
        ?int $userId = null
    ): StockMovement {
        return DB::transaction(function () use ($branchId, $ingredientId, $quantity, $transferId, $userId) {
            $inventory = BranchInventory::query()
                ->where('branch_id', $branchId)
                ->where('ingredient_id', $ingredientId)
                ->lockForUpdate()
                ->first();

            if (! $inventory || (float) $inventory->quantity < $quantity) {
                throw new InsufficientStockException(
                    $inventory?->ingredient?->name ?? 'Ingredient #'.$ingredientId,
                    $quantity,
                    (float) ($inventory?->quantity ?? 0)
                );
            }

            $newBalance = round((float) $inventory->quantity - $quantity, 3);
            $inventory->quantity = $newBalance;
            $inventory->last_movement_at = now();
            $inventory->save();

            return StockMovement::query()->create([
                'branch_id' => $branchId,
                'ingredient_id' => $ingredientId,
                'type' => StockMovementType::TRANSFER_OUT->value,
                'quantity' => -$quantity,
                'balance_after' => $newBalance,
                'reference_type' => 'stock_transfer_out',
                'reference_id' => $transferId,
                'created_by' => $userId,
                'occurred_at' => now(),
            ]);
        });
    }

    public function transferIn(
        int $branchId,
        int $ingredientId,
        float $quantity,
        int $transferId,
        ?int $userId = null
    ): StockMovement {
        return DB::transaction(function () use ($branchId, $ingredientId, $quantity, $transferId, $userId) {
            $inventory = BranchInventory::query()
                ->where('branch_id', $branchId)
                ->where('ingredient_id', $ingredientId)
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                $inventory = BranchInventory::query()->firstOrCreate(
                    ['branch_id' => $branchId, 'ingredient_id' => $ingredientId],
                    ['quantity' => 0, 'min_stock' => 0]
                );
                $inventory = BranchInventory::query()->whereKey($inventory->id)->lockForUpdate()->first();
            }

            $newBalance = round((float) $inventory->quantity + $quantity, 3);
            $inventory->quantity = $newBalance;
            $inventory->last_movement_at = now();
            $inventory->save();

            return StockMovement::query()->create([
                'branch_id' => $branchId,
                'ingredient_id' => $ingredientId,
                'type' => StockMovementType::TRANSFER_IN->value,
                'quantity' => $quantity,
                'balance_after' => $newBalance,
                'reference_type' => 'stock_transfer_in',
                'reference_id' => $transferId,
                'created_by' => $userId,
                'occurred_at' => now(),
            ]);
        });
    }

    protected function maybeDispatchLowStock(BranchInventory $inventory, float $previousQuantity): void
    {
        $minStock = (float) $inventory->min_stock;

        if ($minStock <= 0) {
            return;
        }

        $wasAbove = $previousQuantity > $minStock;
        $isAtOrBelow = (float) $inventory->quantity <= $minStock;

        if ($wasAbove && $isAtOrBelow) {
            $inventory->loadMissing('ingredient');
            event(new LowStockDetected($inventory));
        }
    }
}
