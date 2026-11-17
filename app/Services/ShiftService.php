<?php

namespace App\Services;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Exceptions\BusinessException;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    public function __construct(protected AuditService $audit) {}

    public function open(User $user, int $branchId, float $openingCash, ?string $notes = null): CashierShift
    {
        return DB::transaction(function () use ($user, $branchId, $openingCash, $notes) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $existing = CashierShift::query()
                ->where('branch_id', $branchId)
                ->where('cashier_id', $user->id)
                ->where('status', ShiftStatus::OPEN->value)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new BusinessException('This cashier already has an open shift for the branch.', 'SHIFT_ALREADY_OPEN', [
                    'shift_id' => $existing->id,
                ], 409);
            }

            $shift = CashierShift::query()->create([
                'branch_id' => $branchId,
                'cashier_id' => $user->id,
                'status' => ShiftStatus::OPEN->value,
                'opened_at' => now(),
                'opening_cash' => round($openingCash, 2),
                'notes' => $notes,
            ]);

            $this->audit->log('shift.opened', 'cashier_shift', $shift->id, null, [
                'opening_cash' => $openingCash,
            ], $branchId, $user->id);

            return $shift;
        });
    }

    public function addCashMovement(CashierShift $shift, CashMovementType $type, float $amount, ?string $reason, User $user): CashMovement
    {
        return DB::transaction(function () use ($shift, $type, $amount, $reason, $user) {
            if ($shift->status !== ShiftStatus::OPEN) {
                throw new BusinessException('Cash movements require an open shift.', 'SHIFT_CLOSED', null, 409);
            }

            if ($amount <= 0) {
                throw new BusinessException('Cash movement amount must be greater than zero.', 'INVALID_AMOUNT', null, 422);
            }

            $movement = CashMovement::query()->create([
                'cashier_shift_id' => $shift->id,
                'type' => $type->value,
                'amount' => round($amount, 2),
                'reason' => $reason,
                'created_by' => $user->id,
            ]);

            if ($type === CashMovementType::CASH_IN) {
                $shift->cash_in = round((float) $shift->cash_in + $amount, 2);
            } else {
                $shift->cash_out = round((float) $shift->cash_out + $amount, 2);
            }

            $shift->save();

            return $movement;
        });
    }

    public function close(CashierShift $shift, User $user, float $actualClosingCash, ?string $notes = null): CashierShift
    {
        return DB::transaction(function () use ($shift, $user, $actualClosingCash, $notes) {
            $shift = CashierShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if ($shift->status !== ShiftStatus::OPEN) {
                throw new BusinessException('This shift is already closed.', 'SHIFT_CLOSED', null, 409);
            }

            $cashSales = (float) $shift->payments()
                ->where('method', PaymentMethod::CASH->value)
                ->whereIn('status', [
                    PaymentStatus::PAID->value,
                    PaymentStatus::PARTIALLY_REFUNDED->value,
                ])
                ->sum('amount');

            $expected = round(
                (float) $shift->opening_cash + $cashSales + (float) $shift->cash_in - (float) $shift->cash_out,
                2
            );

            $shift->cash_sales = round($cashSales, 2);
            $shift->expected_cash = $expected;
            $shift->actual_closing_cash = round($actualClosingCash, 2);
            $shift->difference = round($actualClosingCash - $expected, 2);
            $shift->closed_at = now();
            $shift->status = ShiftStatus::CLOSED->value;
            $shift->notes = $notes ?? $shift->notes;
            $shift->save();

            $this->audit->log('shift.closed', 'cashier_shift', $shift->id, null, [
                'expected_cash' => $expected,
                'actual_closing_cash' => $actualClosingCash,
                'difference' => $shift->difference,
            ], $shift->branch_id, $user->id);

            return $shift->refresh()->load('movements');
        });
    }

    public function summary(CashierShift $shift): array
    {
        $byMethod = $shift->payments()
            ->whereIn('status', [PaymentStatus::PAID->value, PaymentStatus::PARTIALLY_REFUNDED->value])
            ->selectRaw('method, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('method')
            ->get();

        return [
            'shift_id' => $shift->id,
            'status' => $shift->status->value,
            'opened_at' => $shift->opened_at?->toIso8601String(),
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'opening_cash' => (float) $shift->opening_cash,
            'cash_sales' => (float) $shift->cash_sales,
            'cash_in' => (float) $shift->cash_in,
            'cash_out' => (float) $shift->cash_out,
            'expected_cash' => (float) $shift->expected_cash,
            'actual_closing_cash' => $shift->actual_closing_cash !== null ? (float) $shift->actual_closing_cash : null,
            'difference' => $shift->difference !== null ? (float) $shift->difference : null,
            'payments_by_method' => $byMethod,
            'movements' => $shift->movements()->with('createdBy:id,name')->latest()->get(),
        ];
    }
}
