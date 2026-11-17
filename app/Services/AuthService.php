<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(protected AuditService $audit) {}

    /**
     * @return array{user: User, token: string, token_type: string}
     */
    public function login(string $email, string $password, string $deviceName = 'pos'): array
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== UserStatus::ACTIVE) {
            throw new BusinessException('This account is not active.', 'ACCOUNT_INACTIVE', null, 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($deviceName)->plainTextToken;

        $this->audit->log('auth.login', 'user', $user->id, null, [
            'device_name' => $deviceName,
        ], $user->primaryBranch()?->id, $user->id);

        return [
            'user' => $user->load('roles:id,name', 'branches:id,name,code'),
            'token' => $token,
            'token_type' => 'Bearer',
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();

        $this->audit->log('auth.logout', 'user', $user->id, null, null, $user->primaryBranch()?->id, $user->id);
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill(['password' => $newPassword])->save();
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        $this->audit->log('auth.password_changed', 'user', $user->id, null, null, $user->primaryBranch()?->id, $user->id);
    }

    public function sendResetLink(string $email): string
    {
        $status = Password::sendResetLink(['email' => $email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw new BusinessException(__($status), 'PASSWORD_RESET_FAILED', null, 422);
        }

        return __($status);
    }

    public function resetPassword(array $credentials): string
    {
        $status = Password::reset($credentials, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw new BusinessException(__($status), 'PASSWORD_RESET_FAILED', null, 422);
        }

        return __($status);
    }
}
