<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DiningTableController;
use App\Http\Controllers\Api\V1\DiscountController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\IngredientController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\KitchenController;
use App\Http\Controllers\Api\V1\MenuItemController;
use App\Http\Controllers\Api\V1\MenuItemVariantController;
use App\Http\Controllers\Api\V1\ModifierGroupController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\PublicController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\SupplierController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:login');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:login');

    Route::get('public/menu', [PublicController::class, 'menu']);
    Route::get('public/settings', [PublicController::class, 'settings']);
    Route::post('webhooks/payments/{method}', [PaymentWebhookController::class, 'handle'])->middleware('throttle:payments');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        Route::get('branches', [BranchController::class, 'index'])->middleware('permission:branches.view');
        Route::post('branches', [BranchController::class, 'store'])->middleware('permission:branches.manage');
        Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('permission:branches.view');
        Route::match(['put', 'patch'], 'branches/{branch}', [BranchController::class, 'update'])->middleware('permission:branches.manage');
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('permission:branches.manage');

        Route::get('employees', [EmployeeController::class, 'index'])->middleware('permission:employees.view');
        Route::post('employees', [EmployeeController::class, 'store'])->middleware('permission:employees.manage');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employees.view');
        Route::match(['put', 'patch'], 'employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employees.manage');
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:employees.manage');
        Route::post('employees/{employee}/branches', [EmployeeController::class, 'assignBranches'])->middleware('permission:employees.manage');

        Route::get('categories', [CategoryController::class, 'index'])->middleware('permission:categories.view');
        Route::post('categories', [CategoryController::class, 'store'])->middleware('permission:categories.manage');
        Route::get('categories/{category}', [CategoryController::class, 'show'])->middleware('permission:categories.view');
        Route::match(['put', 'patch'], 'categories/{category}', [CategoryController::class, 'update'])->middleware('permission:categories.manage');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('permission:categories.manage');

        Route::get('menu-items', [MenuItemController::class, 'index'])->middleware('permission:menu.view');
        Route::post('menu-items', [MenuItemController::class, 'store'])->middleware('permission:menu.manage');
        Route::get('menu-items/{menuItem}', [MenuItemController::class, 'show'])->middleware('permission:menu.view');
        Route::match(['put', 'patch'], 'menu-items/{menuItem}', [MenuItemController::class, 'update'])->middleware('permission:menu.manage');
        Route::delete('menu-items/{menuItem}', [MenuItemController::class, 'destroy'])->middleware('permission:menu.manage');
        Route::post('menu-items/{menuItem}/variants', [MenuItemVariantController::class, 'store'])->middleware('permission:menu.manage');
        Route::match(['put', 'patch'], 'menu-items/{menuItem}/variants/{variant}', [MenuItemVariantController::class, 'update'])->middleware('permission:menu.manage');
        Route::delete('menu-items/{menuItem}/variants/{variant}', [MenuItemVariantController::class, 'destroy'])->middleware('permission:menu.manage');

        Route::get('modifier-groups', [ModifierGroupController::class, 'index'])->middleware('permission:menu.view');
        Route::post('modifier-groups', [ModifierGroupController::class, 'store'])->middleware('permission:menu.manage');
        Route::get('modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'show'])->middleware('permission:menu.view');
        Route::match(['put', 'patch'], 'modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'update'])->middleware('permission:menu.manage');
        Route::delete('modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'destroy'])->middleware('permission:menu.manage');

        Route::get('recipes', [RecipeController::class, 'index'])->middleware('permission:recipes.view');
        Route::post('menu-items/{menuItem}/recipes', [RecipeController::class, 'store'])->middleware('permission:recipes.manage');
        Route::get('recipes/{recipe}', [RecipeController::class, 'show'])->middleware('permission:recipes.view');
        Route::match(['put', 'patch'], 'recipes/{recipe}', [RecipeController::class, 'update'])->middleware('permission:recipes.manage');
        Route::delete('recipes/{recipe}', [RecipeController::class, 'destroy'])->middleware('permission:recipes.manage');

        Route::get('ingredients', [IngredientController::class, 'index'])->middleware('permission:inventory.view');
        Route::post('ingredients', [IngredientController::class, 'store'])->middleware('permission:inventory.manage');
        Route::get('ingredients/{ingredient}', [IngredientController::class, 'show'])->middleware('permission:inventory.view');
        Route::match(['put', 'patch'], 'ingredients/{ingredient}', [IngredientController::class, 'update'])->middleware('permission:inventory.manage');
        Route::delete('ingredients/{ingredient}', [IngredientController::class, 'destroy'])->middleware('permission:inventory.manage');

        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:suppliers.view');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:suppliers.manage');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('permission:suppliers.view');
        Route::match(['put', 'patch'], 'suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers.manage');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:suppliers.manage');

        Route::get('reports/branches', [ReportController::class, 'branches'])->middleware('permission:reports.view');

        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->middleware('permission:audit_logs.view');

        Route::get('orders/{order}', [OrderController::class, 'show'])->middleware('permission:orders.view');
        Route::match(['put', 'patch'], 'orders/{order}', [OrderController::class, 'update'])->middleware('permission:orders.update');
        Route::delete('orders/{order}', [OrderController::class, 'destroy'])->middleware('permission:orders.cancel');
        Route::post('orders/{order}/send-to-kitchen', [OrderController::class, 'sendToKitchen'])->middleware('permission:orders.update');
        Route::post('orders/{order}/hold', [OrderController::class, 'hold'])->middleware('permission:orders.update');
        Route::post('orders/{order}/resume', [OrderController::class, 'resume'])->middleware('permission:orders.update');
        Route::post('orders/{order}/complete', [OrderController::class, 'complete'])->middleware('permission:orders.update');
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('permission:orders.cancel');
        Route::post('orders/{order}/items', [OrderController::class, 'addItems'])->middleware('permission:orders.update');
        Route::match(['put', 'patch'], 'orders/{order}/items/{item}', [OrderController::class, 'updateItem'])->middleware('permission:orders.update');
        Route::delete('orders/{order}/items/{item}', [OrderController::class, 'removeItem'])->middleware('permission:orders.update');
        Route::get('orders/{order}/receipt', [OrderController::class, 'showReceipt'])->middleware('permission:receipts.view');
        Route::post('orders/{order}/receipt', [OrderController::class, 'receipt'])->middleware('permission:receipts.create');
        Route::post('orders/{order}/receipt/print', [OrderController::class, 'printReceipt'])->middleware('permission:receipts.view');
        Route::get('orders/{order}/statuses', [OrderController::class, 'statuses'])->middleware('permission:orders.view');

        Route::get('orders/{order}/payments', [PaymentController::class, 'index'])->middleware('permission:payments.view');
        Route::post('orders/{order}/payments', [PaymentController::class, 'store'])->middleware(['permission:payments.create', 'idempotent']);

        Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:payments.view');
        Route::post('payments/{payment}/confirm', [PaymentController::class, 'confirm'])->middleware(['permission:payments.confirm', 'idempotent']);
        Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])->middleware(['permission:payments.refund', 'idempotent']);

        Route::get('shifts/{shift}', [ShiftController::class, 'show'])->middleware('permission:shifts.view');
        Route::post('shifts/{shift}/close', [ShiftController::class, 'close'])->middleware('permission:shifts.open');
        Route::get('shifts/{shift}/summary', [ShiftController::class, 'summary'])->middleware('permission:shifts.view');
        Route::post('shifts/{shift}/cash-movements', [ShiftController::class, 'cashMovement'])->middleware('permission:shifts.open');

        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchase_orders.view');
        Route::match(['put', 'patch'], 'purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchase_orders.manage');
        Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:purchase_orders.manage');
        Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchase_orders.approve');
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->middleware(['permission:purchase_orders.receive', 'idempotent']);
        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchase_orders.manage');
        Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->middleware('permission:purchase_orders.manage');

        Route::get('stock-transfers/{stockTransfer}', [StockTransferController::class, 'show'])->middleware('permission:stock_transfers.view');
        Route::post('stock-transfers/{stockTransfer}/approve', [StockTransferController::class, 'approve'])->middleware('permission:stock_transfers.manage');
        Route::post('stock-transfers/{stockTransfer}/dispatch', [StockTransferController::class, 'dispatch'])->middleware(['permission:stock_transfers.dispatch', 'idempotent']);
        Route::post('stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive'])->middleware(['permission:stock_transfers.receive', 'idempotent']);
        Route::post('stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->middleware('permission:stock_transfers.manage');
        Route::delete('stock-transfers/{stockTransfer}', [StockTransferController::class, 'destroy'])->middleware('permission:stock_transfers.manage');

        Route::get('discounts/{discount}', [DiscountController::class, 'show'])->middleware('permission:discounts.view');

        Route::prefix('branches/{branch}')->middleware(['branch', 'branch.access'])->group(function () {
            Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
            Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:customers.manage');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
            Route::match(['put', 'patch'], 'customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.manage');
            Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.manage');

            Route::get('tables', [DiningTableController::class, 'index'])->middleware('permission:tables.view');
            Route::post('tables', [DiningTableController::class, 'store'])->middleware('permission:tables.manage');
            Route::get('tables/{table}', [DiningTableController::class, 'show'])->middleware('permission:tables.view');
            Route::match(['put', 'patch'], 'tables/{table}', [DiningTableController::class, 'update'])->middleware('permission:tables.manage');
            Route::delete('tables/{table}', [DiningTableController::class, 'destroy'])->middleware('permission:tables.manage');
            Route::patch('tables/{table}/status', [DiningTableController::class, 'updateStatus'])->middleware('permission:tables.manage');
            Route::post('tables/{table}/assign', [DiningTableController::class, 'assign'])->middleware('permission:tables.manage');
            Route::post('tables/{table}/transfer', [DiningTableController::class, 'transfer'])->middleware('permission:tables.manage');
            Route::post('tables/{table}/merge', [DiningTableController::class, 'merge'])->middleware('permission:tables.manage');

            Route::get('orders', [OrderController::class, 'index'])->middleware('permission:orders.view');
            Route::post('orders', [OrderController::class, 'store'])->middleware(['permission:orders.create', 'idempotent']);

            Route::post('menu-items/{menuItem}/price', [MenuItemController::class, 'setBranchPrice'])->middleware('permission:menu.manage');

            Route::get('kitchen/queue', [KitchenController::class, 'queue'])->middleware('permission:kitchen.view');
            Route::post('kitchen/orders/{order}/accept', [KitchenController::class, 'accept'])->middleware('permission:kitchen.update');
            Route::patch('kitchen/orders/{order}/items/{item}', [KitchenController::class, 'updateItem'])->middleware('permission:kitchen.update');

            Route::get('inventory', [InventoryController::class, 'index'])->middleware('permission:inventory.view');
            Route::get('inventory/low-stock', [InventoryController::class, 'lowStock'])->middleware('permission:inventory.view');
            Route::get('inventory/movements', [InventoryController::class, 'movements'])->middleware('permission:inventory.view');
            Route::match(['put', 'patch'], 'inventory/{ingredient}', [InventoryController::class, 'updateSettings'])->middleware('permission:inventory.manage');
            Route::post('inventory/{ingredient}/adjust', [InventoryController::class, 'adjust'])->middleware('permission:inventory.adjust');

            Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchase_orders.view');
            Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase_orders.manage');

            Route::get('stock-transfers', [StockTransferController::class, 'index'])->middleware('permission:stock_transfers.view');
            Route::post('stock-transfers', [StockTransferController::class, 'store'])->middleware('permission:stock_transfers.manage');

            Route::get('shifts', [ShiftController::class, 'index'])->middleware('permission:shifts.view');
            Route::get('shifts/current', [ShiftController::class, 'current'])->middleware('permission:shifts.view');
            Route::post('shifts/open', [ShiftController::class, 'open'])->middleware('permission:shifts.open');

            Route::get('discounts', [DiscountController::class, 'index'])->middleware('permission:discounts.view');
            Route::post('discounts', [DiscountController::class, 'store'])->middleware('permission:discounts.manage');
            Route::match(['put', 'patch'], 'discounts/{discount}', [DiscountController::class, 'update'])->middleware('permission:discounts.manage');
            Route::delete('discounts/{discount}', [DiscountController::class, 'destroy'])->middleware('permission:discounts.manage');

            Route::get('reports/dashboard', [ReportController::class, 'dashboard'])->middleware('permission:reports.view');
            Route::get('reports/sales', [ReportController::class, 'sales'])->middleware('permission:reports.view');
            Route::get('reports/best-selling', [ReportController::class, 'bestSelling'])->middleware('permission:reports.view');
            Route::get('reports/payment-methods', [ReportController::class, 'paymentMethods'])->middleware('permission:reports.view');
            Route::get('reports/cashiers', [ReportController::class, 'cashiers'])->middleware('permission:reports.view');
            Route::get('reports/inventory', [ReportController::class, 'inventory'])->middleware('permission:reports.view');
            Route::get('reports/purchases', [ReportController::class, 'purchases'])->middleware('permission:reports.view');

            Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit_logs.view');

            Route::get('settings', [SettingController::class, 'index'])->middleware('permission:settings.view');
            Route::match(['put', 'patch'], 'settings', [SettingController::class, 'update'])->middleware('permission:settings.manage');
            Route::delete('settings/{key}', [SettingController::class, 'destroy'])->middleware('permission:settings.manage');
        });
    });
});
