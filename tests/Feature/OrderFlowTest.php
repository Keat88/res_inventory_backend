<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Branch $branch;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cashier = User::query()->where('email', 'cashier@posmaster.test')->firstOrFail();
        $this->branch = Branch::query()->where('code', 'PNH-01')->firstOrFail();
        $this->token = $this->cashier->createToken('phpunit')->plainTextToken;
    }

    public function test_full_order_lifecycle_deducts_inventory_and_captures_payment(): void
    {
        $friedRice = MenuItem::query()->where('slug', 'fried-rice')->firstOrFail();
        $coffee = MenuItem::query()->where('slug', 'iced-coffee')->firstOrFail();
        $large = $coffee->variants()->where('code', 'L')->firstOrFail();
        $sugar = $coffee->modifierGroups()->firstOrFail()->options()->where('name', '50%')->firstOrFail();

        $rice = Ingredient::query()->where('sku', 'ING-RICE')->firstOrFail();
        $riceBefore = (float) BranchInventory::query()
            ->where('branch_id', $this->branch->id)
            ->where('ingredient_id', $rice->id)
            ->value('quantity');

        $response = $this->withToken($this->token)->postJson(
            "/api/v1/branches/{$this->branch->id}/orders",
            [
                'type' => 'DINE_IN',
                'notes' => 'Feature test order',
                'items' => [
                    [
                        'menu_item_id' => $friedRice->id,
                        'quantity' => 2,
                        'special_instruction' => 'No onion',
                    ],
                    [
                        'menu_item_id' => $coffee->id,
                        'menu_item_variant_id' => $large->id,
                        'quantity' => 1,
                        'modifiers' => [
                            ['modifier_option_id' => $sugar->id, 'quantity' => 1],
                        ],
                    ],
                ],
            ]
        );

        $response->assertCreated()->assertJsonPath('data.status', OrderStatus::DRAFT->value);

        $orderId = $response->json('data.id');
        $grandTotal = (float) $response->json('data.grand_total');
        $this->assertGreaterThan(0, $grandTotal);

        $this->withToken($this->token)
            ->postJson("/api/v1/orders/{$orderId}/send-to-kitchen")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::SENT_TO_KITCHEN->value);

        $riceAfter = (float) BranchInventory::query()
            ->where('branch_id', $this->branch->id)
            ->where('ingredient_id', $rice->id)
            ->value('quantity');

        $this->assertLessThan($riceBefore, $riceAfter);
        $this->assertSame(round($riceBefore - 2 * 0.2 * 1.02, 3), round($riceAfter, 3));

        $payment = $this->withToken($this->token)->postJson(
            "/api/v1/orders/{$orderId}/payments",
            [
                'method' => 'CASH',
                'amount' => $grandTotal,
                'cash_received' => $grandTotal + 10,
            ]
        );

        $payment->assertCreated()->assertJsonPath('data.status', PaymentStatus::PAID->value);

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame(PaymentStatus::PAID, $order->payment_status);
        $this->assertEqualsWithDelta($grandTotal, (float) $order->paid_total, 0.001);

        $this->withToken($this->token)
            ->postJson("/api/v1/orders/{$orderId}/receipt")
            ->assertOk();

        $this->withToken($this->token)
            ->postJson("/api/v1/orders/{$orderId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::COMPLETED->value);
    }

    public function test_order_requires_items(): void
    {
        $this->withToken($this->token)->postJson(
            "/api/v1/branches/{$this->branch->id}/orders",
            ['type' => 'TAKEAWAY', 'items' => []]
        )->assertStatus(422);
    }

    public function test_order_cannot_be_sent_to_kitchen_twice(): void
    {
        $friedRice = MenuItem::query()->where('slug', 'fried-rice')->firstOrFail();

        $orderId = $this->withToken($this->token)->postJson(
            "/api/v1/branches/{$this->branch->id}/orders",
            [
                'type' => 'TAKEAWAY',
                'items' => [['menu_item_id' => $friedRice->id, 'quantity' => 1]],
            ]
        )->json('data.id');

        $this->withToken($this->token)->postJson("/api/v1/orders/{$orderId}/send-to-kitchen")->assertOk();

        $this->withToken($this->token)
            ->postJson("/api/v1/orders/{$orderId}/send-to-kitchen")
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'INVALID_ORDER_STATE');
    }

    public function test_order_creation_is_idempotent(): void
    {
        $friedRice = MenuItem::query()->where('slug', 'fried-rice')->firstOrFail();
        $payload = [
            'type' => 'TAKEAWAY',
            'items' => [['menu_item_id' => $friedRice->id, 'quantity' => 1]],
        ];

        $first = $this->withToken($this->token)
            ->withHeader('Idempotency-Key', 'order-key-1')
            ->postJson("/api/v1/branches/{$this->branch->id}/orders", $payload)
            ->assertCreated();

        $second = $this->withToken($this->token)
            ->withHeader('Idempotency-Key', 'order-key-1')
            ->postJson("/api/v1/branches/{$this->branch->id}/orders", $payload)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_cash_payment_rejects_insufficient_cash(): void
    {
        $friedRice = MenuItem::query()->where('slug', 'fried-rice')->firstOrFail();

        $order = $this->withToken($this->token)->postJson(
            "/api/v1/branches/{$this->branch->id}/orders",
            [
                'type' => 'TAKEAWAY',
                'items' => [['menu_item_id' => $friedRice->id, 'quantity' => 1]],
            ]
        );

        $this->withToken($this->token)->postJson(
            "/api/v1/orders/{$order->json('data.id')}/payments",
            ['method' => 'CASH', 'amount' => (float) $order->json('data.grand_total'), 'cash_received' => 0.01]
        )->assertStatus(422)->assertJsonPath('errors.code', 'INSUFFICIENT_CASH');
    }
}
