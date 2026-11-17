<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_user_can_login_and_fetch_profile(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@posmaster.test',
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);

        $login->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user']]);

        $token = $login->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'admin@posmaster.test')
            ->assertJsonPath('data.roles.0', 'ADMIN');
    }

    public function test_login_with_invalid_credentials_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@posmaster.test',
            'password' => 'wrong-password',
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
    }

    public function test_cashier_cannot_manage_branches(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@posmaster.test',
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)
            ->postJson('/api/v1/branches', [
                'code' => 'NEW-01',
                'name' => 'Unauthorized Branch',
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/branches')->assertUnauthorized();
    }

    public function test_branch_access_is_enforced(): void
    {
        $cashier = User::query()->where('email', 'cashier@posmaster.test')->firstOrFail();
        $otherBranch = Branch::query()->where('code', 'SR-01')->firstOrFail();

        $token = $cashier->createToken('phpunit')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/branches/{$otherBranch->id}/orders")
            ->assertForbidden();
    }
}
