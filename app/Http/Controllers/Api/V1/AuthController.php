<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(protected AuthService $auth) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->auth->login(
            $data['email'],
            $data['password'],
            $data['device_name'] ?? 'pos'
        );

        return ApiResponse::success($result, 'Logged in successfully');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles:id,name', 'branches:id,name,code');

        return ApiResponse::success([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'branches' => $user->branches,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return ApiResponse::success(null, 'Logged out successfully');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        $this->auth->changePassword($request->user(), $data['current_password'], $data['new_password']);

        return ApiResponse::success(null, 'Password changed successfully');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $status = $this->auth->sendResetLink($data['email']);

        return ApiResponse::success(null, $status);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = $this->auth->resetPassword($data);

        return ApiResponse::success(null, $status);
    }
}
