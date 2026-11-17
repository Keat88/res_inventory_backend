<?php

namespace App\Http\Middleware;

use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBranchAccess
{
    public function __construct(protected BranchContext $branchContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $branchId = $this->branchContext->id();

        if ($user && $branchId && ! $user->canAccessBranch($branchId)) {
            abort(403, 'You do not have access to this branch.');
        }

        return $next($request);
    }
}
