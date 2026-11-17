<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveBranch
{
    public function __construct(protected BranchContext $branchContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $branch = $request->route('branch');
        $branchId = $branch?->id ?? $branch ?? $request->header('X-Branch-Id');

        if ($branchId) {
            $model = Branch::query()->find($branchId);

            abort_if($model === null, 404, 'Branch not found.');

            $this->branchContext->set($model);
            $request->attributes->set('branch', $model);
        }

        return $next($request);
    }
}
