<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\BranchInventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    private function range(?string $from, ?string $to): array
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : now()->startOfDay();
        $end = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        return [$start, $end];
    }

    private function periodExpression(string $groupBy): string
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        return match ($groupBy) {
            'week' => $sqlite ? "strftime('%Y-%W', created_at)" : "DATE_FORMAT(created_at, '%x-%v')",
            'month' => $sqlite ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')",
            'year' => $sqlite ? "strftime('%Y', created_at)" : "DATE_FORMAT(created_at, '%Y')",
            default => $sqlite ? "strftime('%Y-%m-%d', created_at)" : "DATE_FORMAT(created_at, '%Y-%m-%d')",
        };
    }

    public function salesSummary(int $branchId, ?string $from = null, ?string $to = null, string $groupBy = 'day'): array
    {
        [$start, $end] = $this->range($from, $to);
        $expr = $this->periodExpression($groupBy);

        $rows = Order::query()
            ->where('branch_id', $branchId)
            ->whereBetween('created_at', [$start, $end])
            ->where('status', '!=', OrderStatus::CANCELLED->value)
            ->selectRaw("{$expr} as period, COUNT(*) as orders, SUM(subtotal) as subtotal, SUM(discount_total) as discount_total, SUM(tax_total) as tax_total, SUM(service_charge_total) as service_charge_total, SUM(grand_total) as grand_total, SUM(paid_total) as paid_total")
            ->groupByRaw($expr)
            ->orderBy('period')
            ->get();

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'group_by' => $groupBy,
            'rows' => $rows->map(fn ($row) => [
                'period' => $row->period,
                'orders' => (int) $row->orders,
                'subtotal' => (float) $row->subtotal,
                'discount_total' => (float) $row->discount_total,
                'tax_total' => (float) $row->tax_total,
                'service_charge_total' => (float) $row->service_charge_total,
                'grand_total' => (float) $row->grand_total,
                'paid_total' => (float) $row->paid_total,
            ])->values(),
            'totals' => [
                'orders' => (int) $rows->sum('orders'),
                'subtotal' => round((float) $rows->sum('subtotal'), 2),
                'discount_total' => round((float) $rows->sum('discount_total'), 2),
                'tax_total' => round((float) $rows->sum('tax_total'), 2),
                'service_charge_total' => round((float) $rows->sum('service_charge_total'), 2),
                'grand_total' => round((float) $rows->sum('grand_total'), 2),
                'paid_total' => round((float) $rows->sum('paid_total'), 2),
            ],
        ];
    }

    public function bestSelling(int $branchId, ?string $from = null, ?string $to = null, int $limit = 20): array
    {
        [$start, $end] = $this->range($from, $to);

        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.branch_id', $branchId)
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', '!=', OrderStatus::CANCELLED->value)
            ->selectRaw('order_items.menu_item_id, order_items.name_snapshot, SUM(order_items.quantity) as quantity_sold, SUM(order_items.line_total) as revenue')
            ->groupBy('order_items.menu_item_id', 'order_items.name_snapshot')
            ->orderByDesc('quantity_sold')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'menu_item_id' => $row->menu_item_id,
                'name' => $row->name_snapshot,
                'quantity_sold' => (float) $row->quantity_sold,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    public function paymentMethods(int $branchId, ?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->range($from, $to);

        return Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.branch_id', $branchId)
            ->whereBetween('payments.created_at', [$start, $end])
            ->whereIn('payments.status', [
                PaymentStatus::PAID->value,
                PaymentStatus::PARTIALLY_REFUNDED->value,
            ])
            ->selectRaw('payments.method, COUNT(*) as count, SUM(payments.amount) as total')
            ->groupBy('payments.method')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->method,
                'count' => (int) $row->count,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    public function branchComparison(?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->range($from, $to);

        return Order::query()
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', '!=', OrderStatus::CANCELLED->value)
            ->selectRaw('orders.branch_id, branches.name as branch_name, COUNT(*) as orders, SUM(orders.grand_total) as grand_total')
            ->groupBy('orders.branch_id', 'branches.name')
            ->orderByDesc('grand_total')
            ->get()
            ->map(fn ($row) => [
                'branch_id' => $row->branch_id,
                'branch_name' => $row->branch_name,
                'orders' => (int) $row->orders,
                'grand_total' => round((float) $row->grand_total, 2),
            ])
            ->all();
    }

    public function cashierPerformance(int $branchId, ?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->range($from, $to);

        return Order::query()
            ->join('users', 'users.id', '=', 'orders.cashier_id')
            ->where('orders.branch_id', $branchId)
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', '!=', OrderStatus::CANCELLED->value)
            ->selectRaw('orders.cashier_id, users.name as cashier_name, COUNT(*) as orders, SUM(orders.grand_total) as grand_total')
            ->groupBy('orders.cashier_id', 'users.name')
            ->orderByDesc('grand_total')
            ->get()
            ->map(fn ($row) => [
                'cashier_id' => $row->cashier_id,
                'cashier_name' => $row->cashier_name,
                'orders' => (int) $row->orders,
                'grand_total' => round((float) $row->grand_total, 2),
            ])
            ->all();
    }

    public function inventoryStatus(int $branchId, bool $lowOnly = false): array
    {
        $query = BranchInventory::query()
            ->with('ingredient:id,name,sku,unit')
            ->where('branch_id', $branchId);

        if ($lowOnly) {
            $query->whereColumn('quantity', '<=', 'min_stock');
        }

        return $query->orderBy('quantity')->get()->map(fn (BranchInventory $inventory) => [
            'ingredient_id' => $inventory->ingredient_id,
            'ingredient' => $inventory->ingredient?->name,
            'sku' => $inventory->ingredient?->sku,
            'unit' => $inventory->ingredient?->unit,
            'quantity' => (float) $inventory->quantity,
            'min_stock' => (float) $inventory->min_stock,
            'is_low' => $inventory->isBelowMinimum(),
        ])->all();
    }

    public function purchaseSummary(int $branchId, ?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->range($from, $to);

        $orders = PurchaseOrder::query()
            ->with('supplier:id,name')
            ->where('branch_id', $branchId)
            ->whereBetween('created_at', [$start, $end])
            ->where('status', '!=', PurchaseOrderStatus::CANCELLED->value)
            ->get();

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'orders' => $orders->count(),
            'totals' => [
                'subtotal' => round((float) $orders->sum('subtotal'), 2),
                'tax_total' => round((float) $orders->sum('tax_total'), 2),
                'discount_total' => round((float) $orders->sum('discount_total'), 2),
                'grand_total' => round((float) $orders->sum('grand_total'), 2),
            ],
            'by_status' => $orders->groupBy(fn ($order) => $order->status->value)
                ->map(fn ($group) => $group->count()),
        ];
    }

    public function dashboard(int $branchId): array
    {
        [$start, $end] = $this->range(null, null);

        $orders = Order::query()
            ->where('branch_id', $branchId)
            ->whereBetween('created_at', [$start, $end])
            ->where('status', '!=', OrderStatus::CANCELLED->value);

        $revenue = (float) (clone $orders)->sum('grand_total');
        $count = (clone $orders)->count();

        return [
            'date' => $start->toDateString(),
            'orders_today' => $count,
            'revenue_today' => round($revenue, 2),
            'average_order_value' => $count > 0 ? round($revenue / $count, 2) : 0,
            'open_orders' => Order::query()
                ->where('branch_id', $branchId)
                ->whereNotIn('status', [OrderStatus::COMPLETED->value, OrderStatus::CANCELLED->value])
                ->count(),
            'low_stock_items' => BranchInventory::query()
                ->where('branch_id', $branchId)
                ->whereColumn('quantity', '<=', 'min_stock')
                ->count(),
        ];
    }
}
