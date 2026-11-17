<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Branch $branch, Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->where(fn ($q) => $q->where('branch_id', $branch->id)->orWhereNull('branch_id'))
            ->with('user:id,name,email')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', '%'.$request->string('action').'%'))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')));

        return ApiResponse::paginated($query->latest('created_at')->paginate($request->integer('per_page', 50)));
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        return ApiResponse::success($auditLog->load('user:id,name,email', 'branch:id,name,code'));
    }
}
