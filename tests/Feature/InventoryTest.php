<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->branch = Branch::query()->where('code', 'PNH-01')->firstOrFail();
        $this->token = User::query()->where('email', 'inventory@posmaster.test')->firstOrFail()
            ->createToken('phpunit')->plainTextToken;
    }

    public function test_stock_can_be_adjusted_and_movement_recorded(): void
    {
        $rice = Ingredient::query()->where('sku', 'ING-RICE')->firstOrFail();

        $before = (float) BranchInventory::query()
            ->where('branch_id', $this->branch->id)
            ->where('ingredient_id', $rice->id)
            ->value('quantity');

        $this->withToken($this->token)->postJson(
            "/api/v1/branches/{$this->branch->id}/inventory/{$rice->id}/adjust",
            ['quantity_change' => 2.5, 'note' => 'Delivery count correction']
        )->assertOk();

        $after = (float) BranchInventory::query()
            ->where('branch_id', $this->branch->id)
            ->where('ingredient_id', $rice->id)
            ->value('quantity');

        $this->assertEqualsWithDelta($before + 2.5, $after, 0.001);

        $this->withToken($this->token)->getJson(
            "/api/v1/branches/{$this->branch->id}/inventory/movements?ingredient_id={$rice->id}"
        )->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_low_stock_endpoint_lists_items_below_minimum(): void
    {
        $rice = Ingredient::query()->where('sku', 'ING-RICE')->firstOrFail();

        $this->withToken($this->token)->postJson(
            "/api/v1/branches/{$this->branch->id}/inventory/{$rice->id}/adjust",
            ['quantity_change' => -24]
        )->assertOk();

        $response = $this->withToken($this->token)->getJson(
            "/api/v1/branches/{$this->branch->id}/inventory/low-stock"
        )->assertOk();

        $ids = collect($response->json('data'))->pluck('ingredient_id')->all();

        $this->assertContains($rice->id, $ids);
    }

    public function test_public_menu_is_accessible_without_auth(): void
    {
        $this->getJson("/api/v1/public/menu?branch_id={$this->branch->id}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
