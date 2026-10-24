<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_update_and_delete_product(): void
    {
        $id = $this->postJson('/api/products', ['name' => 'Webcam', 'price' => 249900, 'stock_quantity' => 7])
            ->assertCreated()->json('data.id');

        $this->putJson("/api/products/{$id}", ['name' => 'Webcam HD', 'price' => 259900, 'stock_quantity' => 3])
            ->assertOk()->assertJsonPath('data.name', 'Webcam HD')->assertJsonPath('data.price', 259900);

        $this->deleteJson("/api/products/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('products', ['id' => $id]);
    }

    public function test_product_validation(): void
    {
        $this->postJson('/api/products', ['name' => '', 'price' => 0, 'stock_quantity' => -1])->assertUnprocessable();
        $this->postJson('/api/products', ['name' => 'X', 'price' => 'abc', 'stock_quantity' => 1])->assertUnprocessable();
    }

    public function test_product_with_orders_cannot_be_deleted(): void
    {
        $p = Product::create(['name' => 'Mouse', 'price' => 50000, 'stock_quantity' => 5]);
        $this->postJson('/api/orders', ['items' => [['product_id' => $p->id, 'quantity' => 1]]])->assertCreated();

        $this->deleteJson("/api/products/{$p->id}")->assertStatus(409);
        $this->assertDatabaseHas('products', ['id' => $p->id]);
    }

    public function test_public_list_hides_out_of_stock_but_admin_list_shows_all(): void
    {
        Product::create(['name' => 'In', 'price' => 100, 'stock_quantity' => 1]);
        Product::create(['name' => 'Out', 'price' => 100, 'stock_quantity' => 0]);

        $this->getJson('/api/products')->assertJsonCount(1, 'data');
        $this->getJson('/api/products?all=1')->assertJsonCount(2, 'data');
    }

    public function test_orders_list_and_dashboard_stats(): void
    {
        $p = Product::create(['name' => 'Mouse', 'price' => 50000, 'stock_quantity' => 5]);
        $this->postJson('/api/orders', ['items' => [['product_id' => $p->id, 'quantity' => 2]]])->assertCreated();

        $this->getJson('/api/orders')->assertOk()->assertJsonPath('data.0.items_count', 1)->assertJsonPath('data.0.payment_status', 'unpaid');
        $this->getJson('/api/dashboard/stats')->assertOk()->assertJsonPath('data.orders', 1)->assertJsonPath('data.revenue', 0);
        $this->getJson('/api/webhook-events')->assertOk()->assertJsonCount(0, 'data');
    }
}
