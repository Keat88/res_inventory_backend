<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CashMovementType;
use App\Enums\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Services\ShiftService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShiftController extends Controller
{
    public function __construct(protected ShiftService $shifts) {}

    public function index(Branch $branch, Request $request): JsonResponse
    {
        $query = CashierShift::query()
            ->where('branch_id', $branch->id)
            ->with('cashier:id,name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('cashier_id'), fn ($q) => $q->where('cashier_id', $request->integer('cashier_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('opened_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('opened_at', '<=', $request->date('to')));

        return ApiResponse::paginated($query->latest('opened_at')->paginate($request->integer('per_page', 20)));
    }

    public function current(Request $request, Branch $branch): JsonResponse
    {
        $shift = CashierShift::query()
            ->where('branch_id', $branch->id)
            ->where('cashier_id', $request->user()->id)
            ->where('status', ShiftStatus::OPEN->value)
            ->with('movements')
            ->first();

        return ApiResponse::success($shift);
    }

    public function open(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $shift = $this->shifts->open($request->user(), $branch->id, (float) $data['opening_cash'], $data['notes'] ?? null);

        return ApiResponse::created($shift, 'Shift opened successfully');
    }

    public function show(CashierShift $shift): JsonResponse
    {
        $this->authorize('view', $shift);

        return ApiResponse::success($shift->load('cashier:id,name', 'movements.createdBy:id,name'));
    }

    public function close(Request $request, CashierShift $shift): JsonResponse
    {
        $this->authorize('close', $shift);

        $data = $request->validate([
            'actual_closing_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        return ApiResponse::success(
            $this->shifts->close($shift, $request->user(), (float) $data['actual_closing_cash'], $data['notes'] ?? null),
            'Shift closed successfully'
        );
    }

    public function summary(CashierShift $shift): JsonResponse
    {
        $this->authorize('view', $shift);

        return ApiResponse::success($this->shifts->summary($shift));
    }

    public function cashMovement(Request $request, CashierShift $shift): JsonResponse
    {
        $this->authorize('close', $shift);

        $data = $request->validate([
            'type' => ['required', Rule::in(CashMovementType::values())],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $movement = $this->shifts->addCashMovement(
            $shift,
            CashMovementType::from($data['type']),
            (float) $data['amount'],
            $data['reason'] ?? null,
            $request->user()
        );

        return ApiResponse::created($movement, 'Cash movement recorded');
    }
}
