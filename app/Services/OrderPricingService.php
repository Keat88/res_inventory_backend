<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\BranchMenuItemPrice;
use App\Models\Discount;
use App\Models\MenuItem;
use App\Models\ModifierOption;

class OrderPricingService
{
    /**
     * Resolve the effective price for an item/variant at a branch.
     */
    public function resolveUnitPrice(int $branchId, MenuItem $item, ?int $variantId = null): float
    {
        $overrides = BranchMenuItemPrice::query()
            ->where('branch_id', $branchId)
            ->where('menu_item_id', $item->id)
            ->get();

        $variantOverride = $overrides->firstWhere('menu_item_variant_id', $variantId);
        if ($variantOverride && $variantOverride->price !== null) {
            return (float) $variantOverride->price;
        }

        if ($variantId === null) {
            $itemOverride = $overrides->firstWhere('menu_item_variant_id', null);
            if ($itemOverride && $itemOverride->price !== null) {
                return (float) $itemOverride->price;
            }
        }

        if ($variantId !== null) {
            $variant = $item->variants->firstWhere('id', $variantId);
            if (! $variant) {
                throw new BusinessException('Variant does not belong to this menu item.', 'INVALID_VARIANT', null, 422);
            }

            return (float) $variant->price;
        }

        return (float) $item->base_price;
    }

    public function isAvailableAtBranch(int $branchId, MenuItem $item, ?int $variantId = null): bool
    {
        if (! $item->is_available || $item->status !== 'ACTIVE') {
            return false;
        }

        $override = BranchMenuItemPrice::query()
            ->where('branch_id', $branchId)
            ->where('menu_item_id', $item->id)
            ->where(function ($q) use ($variantId) {
                $q->where('menu_item_variant_id', $variantId);
                if ($variantId === null) {
                    $q->orWhereNull('menu_item_variant_id');
                }
            })
            ->first();

        return $override ? (bool) $override->is_available : true;
    }

    /**
     * Validate modifiers against the item's modifier groups and calculate their totals.
     *
     * @return array{modifiers: array<int, array<string, mixed>>, modifier_total: float}
     */
    public function priceModifiers(MenuItem $item, array $modifiers): array
    {
        $item->loadMissing('modifierGroups.options');
        $groups = $item->modifierGroups;

        $result = [];
        $total = 0.0;

        foreach ($groups as $group) {
            $groupSelections = collect($modifiers)->filter(function ($modifier) use ($group) {
                $option = ModifierOption::query()->find($modifier['modifier_option_id'] ?? null);

                return $option && (int) $option->modifier_group_id === $group->id;
            });

            $count = (int) $groupSelections->sum(fn ($m) => (float) ($m['quantity'] ?? 1));

            if ($group->is_required && $count < max(1, $group->min_selection)) {
                throw new BusinessException("Modifier group '{$group->name}' is required.", 'MODIFIER_REQUIRED', ['group' => $group->name], 422);
            }

            if ($count > 0 && $count < $group->min_selection) {
                throw new BusinessException("Select at least {$group->min_selection} option(s) for '{$group->name}'.", 'MODIFIER_MIN_SELECTION', ['group' => $group->name], 422);
            }

            if ($count > $group->max_selection) {
                throw new BusinessException("Select at most {$group->max_selection} option(s) for '{$group->name}'.", 'MODIFIER_MAX_SELECTION', ['group' => $group->name], 422);
            }

            foreach ($groupSelections as $modifier) {
                $option = $group->options->firstWhere('id', (int) ($modifier['modifier_option_id'] ?? 0));
                if (! $option) {
                    throw new BusinessException('Invalid modifier option for this item.', 'INVALID_MODIFIER', null, 422);
                }

                $quantity = (float) ($modifier['quantity'] ?? 1);
                $lineTotal = round((float) $option->price * $quantity, 2);
                $total += $lineTotal;

                $result[] = [
                    'modifier_option_id' => $option->id,
                    'group_name_snapshot' => $group->name,
                    'option_name_snapshot' => $option->name,
                    'unit_price' => (float) $option->price,
                    'quantity' => $quantity,
                    'total' => $lineTotal,
                ];
            }
        }

        if ($this->hasUnknownModifier($item, $modifiers)) {
            throw new BusinessException('One or more modifiers are not available for this item.', 'INVALID_MODIFIER', null, 422);
        }

        return ['modifiers' => $result, 'modifier_total' => round($total, 2)];
    }

    protected function hasUnknownModifier(MenuItem $item, array $modifiers): bool
    {
        $allowed = $item->modifierGroups->flatMap(fn ($group) => $group->options->pluck('id'))->all();

        foreach ($modifiers as $modifier) {
            if (! in_array((int) ($modifier['modifier_option_id'] ?? 0), $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a priced line snapshot for an order item.
     */
    public function priceLine(int $branchId, array $line): array
    {
        $item = MenuItem::query()->with(['variants', 'modifierGroups.options'])->findOrFail($line['menu_item_id']);

        if (! $this->isAvailableAtBranch($branchId, $item, $line['menu_item_variant_id'] ?? null)) {
            throw new BusinessException("Menu item '{$item->name}' is not available.", 'ITEM_UNAVAILABLE', ['menu_item_id' => $item->id], 409);
        }

        $variantId = $line['menu_item_variant_id'] ?? null;
        $variant = $variantId ? $item->variants->firstWhere('id', (int) $variantId) : null;

        if ($variantId && ! $variant) {
            throw new BusinessException('Variant does not belong to this menu item.', 'INVALID_VARIANT', null, 422);
        }

        $unitPrice = $this->resolveUnitPrice($branchId, $item, $variant?->id);
        $quantity = (float) ($line['quantity'] ?? 1);

        if ($quantity <= 0) {
            throw new BusinessException('Item quantity must be greater than zero.', 'INVALID_QUANTITY', null, 422);
        }

        $modifierData = $this->priceModifiers($item, $line['modifiers'] ?? []);
        $lineSubtotal = round(($unitPrice + $modifierData['modifier_total']) * $quantity, 2);

        return [
            'menu_item_id' => $item->id,
            'menu_item_variant_id' => $variant?->id,
            'name_snapshot' => $item->name,
            'variant_name_snapshot' => $variant?->name,
            'unit_price' => $unitPrice + $modifierData['modifier_total'],
            'base_unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_subtotal' => $lineSubtotal,
            'special_instruction' => $line['special_instruction'] ?? null,
            'modifiers' => $modifierData['modifiers'],
        ];
    }

    /**
     * Compute order totals using server-side rates and discount rules.
     *
     * @param  array<int, array<string, mixed>>  $pricedLines
     */
    public function computeTotals(Branch $branch, array $pricedLines, ?Discount $discount = null, ?array $manualDiscount = null): array
    {
        $subtotal = round(collect($pricedLines)->sum('line_subtotal'), 2);

        $discountAmount = 0.0;
        $discountType = null;
        $discountValue = 0.0;

        if ($discount) {
            if (! $discount->isUsableFor($subtotal)) {
                throw new BusinessException('Discount is not valid for this order.', 'DISCOUNT_NOT_VALID', null, 409);
            }

            $discountType = $discount->type;
            $discountValue = (float) $discount->value;
        } elseif ($manualDiscount) {
            $discountType = DiscountType::from($manualDiscount['type']);
            $discountValue = (float) $manualDiscount['value'];
        }

        if ($discountType === DiscountType::PERCENTAGE) {
            $discountAmount = round($subtotal * min(100, $discountValue) / 100, 2);
        } elseif ($discountType === DiscountType::FIXED) {
            $discountAmount = round(min($subtotal, $discountValue), 2);
        }

        if ($discount?->max_discount_amount !== null) {
            $discountAmount = min($discountAmount, (float) $discount->max_discount_amount);
        }

        $discountAmount = round(max(0, min($subtotal, $discountAmount)), 2);
        $taxable = round($subtotal - $discountAmount, 2);

        $taxRate = (float) $branch->tax_rate;
        $serviceRate = (float) $branch->service_charge_rate;
        $taxTotal = round($taxable * $taxRate / 100, 2);
        $serviceTotal = round($taxable * $serviceRate / 100, 2);
        $grandTotal = round($taxable + $taxTotal + $serviceTotal, 2);

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discountAmount,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'tax_rate' => $taxRate,
            'tax_total' => $taxTotal,
            'service_charge_rate' => $serviceRate,
            'service_charge_total' => $serviceTotal,
            'grand_total' => $grandTotal,
        ];
    }

    public function resolveDiscount(?string $code, int $branchId): ?Discount
    {
        if (! $code) {
            return null;
        }

        $discount = Discount::query()
            ->where('code', $code)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->first();

        if (! $discount || ! $discount->is_active) {
            throw new BusinessException('Discount code is invalid or inactive.', 'DISCOUNT_INVALID', null, 422);
        }

        return $discount;
    }
}
