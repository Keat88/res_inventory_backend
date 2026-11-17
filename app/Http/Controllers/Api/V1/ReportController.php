<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(protected ReportService $reports) {}

    public function sales(Branch $branch, Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->salesSummary(
            $branch->id,
            $request->query('from'),
            $request->query('to'),
            (string) $request->query('group_by', 'day')
        ));
    }

    public function bestSelling(Branch $branch, Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->bestSelling(
            $branch->id,
            $request->query('from'),
            $request->query('to'),
            $request->integer('limit', 20)
        ));
    }

    public function paymentMethods(Branch $branch, Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->paymentMethods($branch->id, $request->query('from'), $request->query('to')));
    }

    public function branches(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only administrators can compare branches.');

        return ApiResponse::success($this->reports->branchComparison($request->query('from'), $request->query('to')));
    }

    public function cashiers(Branch $branch, Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->cashierPerformance($branch->id, $request->query('from'), $request->query('to')));
    }

    public function inventory(Branch $branch, Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->inventoryStatus($branch->id, $request->boolean('low')));
    }

    public function purchases(Branch $branch, Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->purchaseSummary($branch->id, $request->query('from'), $request->query('to')));
    }

    public function dashboard(Branch $branch): JsonResponse
    {
        return ApiResponse::success($this->reports->dashboard($branch->id));
    }
}
