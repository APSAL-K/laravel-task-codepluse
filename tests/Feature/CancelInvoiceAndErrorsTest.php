<?php

namespace Tests\Feature;

use App\Contracts\RazorpayGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Razorpay\Api\Errors\BadRequestError;
use Tests\TestCase;

class CancelInvoiceAndErrorsTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'wh_secret';

    public array $cancelledLinks = [];
    public ?\Throwable $orderError = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['razorpay.key_secret' => 'ks', 'razorpay.webhook_secret' => self::WEBHOOK_SECRET, 'razorpay.currency' => 'INR']);

        $this->app->bind(RazorpayGateway::class, fn () => new class($this) implements RazorpayGateway {
            private static int $n = 0;

            public function __construct(private CancelInvoiceAndErrorsTest $t) {}

            public function createOrder(int $amount, string $currency, string $receipt): array
            {
                if ($this->t->orderError) {
                    throw $this->t->orderError;
                }

                return ['id' => 'order_c'.(++self::$n)];
            }

            public function createPaymentLink(int $amount, string $currency, string $referenceId, string $description, ?string $callbackUrl = null): array
            {
                return ['id' => 'plink_'.$referenceId, 'short_url' => 'https://rzp.io/i/'.$referenceId];
            }

            public function cancelPaymentLink(string $paymentLinkId): void
            {
                $this->t->cancelledLinks[] = $paymentLinkId;
            }
        });
    }

    private function place(int $qty = 2, array $extra = []): array
    {
        $p = Product::first() ?? Product::create(['name' => 'Mouse', 'price' => 50000, 'stock_quantity' => 10]);
        $order = $this->postJson('/api/orders', ['items' => [['product_id' => $p->id, 'quantity' => $qty]]] + $extra)->assertCreated()->json('data');
        $this->postJson("/api/orders/{$order['id']}/payment")->assertOk();

        return [Order::find($order['id']), $p, Payment::where('order_id', $order['id'])->first()];
    }

    private function hook(array $payload, string $eventId)
    {
        $raw = json_encode($payload);

        return $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $raw, self::WEBHOOK_SECRET),
            'HTTP_X_RAZORPAY_EVENT_ID' => $eventId,
        ], $raw);
    }

    private function capture(Payment $p, string $payId = 'pay_1')
    {
        return $this->hook(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'id' => $payId, 'order_id' => $p->razorpay_order_id, 'amount' => $p->amount, 'currency' => 'INR',
            'method' => 'upi',
        ]]]], 'evt_'.$payId);
    }

    public function test_cancel_restores_stock_closes_payments_and_cancels_the_link(): void
    {
        [$order, $product, $payment] = $this->place(3);
        $this->assertSame(7, $product->fresh()->stock_quantity);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('data.order_status', 'cancelled')->assertJsonPath('data.can_cancel', false);

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame([$payment->payment_link_id], $this->cancelledLinks);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_paid_order_cannot_be_cancelled(): void
    {
        [$order, $product, $payment] = $this->place();
        $this->capture($payment)->assertOk();

        $this->postJson("/api/orders/{$order->id}/cancel")->assertStatus(409)
            ->assertJsonPath('success', false)->assertJsonPath('code', 'conflict');
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(8, $product->fresh()->stock_quantity);
    }

    public function test_cancelled_order_cannot_start_a_payment(): void
    {
        [$order] = $this->place();
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $this->postJson("/api/orders/{$order->id}/payment")->assertStatus(409)->assertJsonPath('code', 'conflict');
    }

    public function test_payment_arriving_after_cancellation_is_recorded_but_order_stays_cancelled(): void
    {
        [$order, , $payment] = $this->place();
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $this->capture($payment)->assertOk();

        $order = $order->fresh();
        $this->assertSame('cancelled', $order->order_status);
        $this->assertSame('paid', $order->payment_status);
        $this->getJson("/api/orders/{$order->id}/invoice")->assertStatus(409);
    }

    public function test_late_failure_does_not_reopen_a_cancelled_payment(): void
    {
        [$order, , $payment] = $this->place();
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $this->hook(['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => [
            'id' => 'pay_f', 'order_id' => $payment->razorpay_order_id, 'amount' => $payment->amount, 'currency' => 'INR',
        ]]]], 'evt_f')->assertOk();

        $this->assertSame('cancelled', $payment->fresh()->status);
    }

    public function test_invoice_is_available_only_after_payment(): void
    {
        [$order, , $payment] = $this->place(2, ['customer' => ['name' => 'Asha', 'email' => 'asha@example.com', 'contact' => '9876543210']]);

        $this->getJson("/api/orders/{$order->id}/invoice")->assertStatus(409)->assertJsonPath('success', false);
        $this->get("/invoice/{$order->order_number}")->assertStatus(409);

        $this->capture($payment, 'pay_inv')->assertOk();

        $res = $this->getJson("/api/orders/{$order->id}/invoice")->assertOk()
            ->assertJsonPath('data.invoice_number', str_replace('ORD-', 'INV-', $order->order_number))
            ->assertJsonPath('data.total', 100000)
            ->assertJsonPath('data.customer.name', 'Asha')
            ->assertJsonPath('data.payment.method', 'upi')
            ->assertJsonPath('data.payment.razorpay_payment_id', 'pay_inv')
            ->assertJsonPath('data.items.0.quantity', 2);
        $this->assertSame(url("/invoice/{$order->order_number}"), $res->json('data.url'));

        $this->get("/invoice/{$order->order_number}")->assertOk()
            ->assertSee('Invoice')->assertSee($order->order_number)->assertSee('1,000.00')->assertSee('Asha')->assertSee('pay_inv');

        $this->getJson("/api/orders/{$order->id}")->assertJsonPath('data.invoice_url', url("/invoice/{$order->order_number}"));
    }

    public function test_invoice_page_for_unknown_order_is_404(): void
    {
        $this->get('/invoice/ORD-NOPE')->assertNotFound();
    }

    public function test_success_responses_have_a_consistent_envelope(): void
    {
        Product::create(['name' => 'Mouse', 'price' => 50000, 'stock_quantity' => 1]);

        $this->getJson('/api/products')->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['success', 'data']);
    }

    public function test_validation_errors_are_readable(): void
    {
        $this->postJson('/api/orders', ['items' => [['product_id' => 999, 'quantity' => 1]]])->assertStatus(422)
            ->assertJsonPath('success', false)->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('message', 'Product 999 does not exist.')
            ->assertJsonStructure(['errors' => ['items.0.product_id']]);

        $this->postJson('/api/orders', [])->assertStatus(422)->assertJsonPath('message', 'Add at least one item to the order.');
        $this->postJson('/api/products', ['name' => 'x', 'price' => 5, 'stock_quantity' => 1])->assertStatus(422)
            ->assertJsonPath('message', 'Price must be at least ₹1.');
    }

    public function test_missing_resources_and_endpoints_return_clean_404s(): void
    {
        $this->getJson('/api/orders/9999')->assertStatus(404)->assertExactJson(['success' => false, 'code' => 'not_found', 'message' => 'Order not found.']);
        $this->deleteJson('/api/products/9999')->assertStatus(404)->assertJsonPath('message', 'Product not found.');
        $this->getJson('/api/nope')->assertStatus(404)->assertJsonPath('message', 'Endpoint not found.');
        $this->postJson('/api/payments/verify', ['razorpay_order_id' => 'o', 'razorpay_payment_id' => 'p', 'razorpay_signature' => 's'])
            ->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_wrong_method_returns_405_json(): void
    {
        $this->putJson('/api/orders')->assertStatus(405)->assertJsonPath('code', 'method_not_allowed')->assertJsonPath('success', false);
    }

    public function test_gateway_failure_is_a_502_not_a_stack_trace(): void
    {
        $p = Product::create(['name' => 'Mouse', 'price' => 50000, 'stock_quantity' => 5]);
        $id = $this->postJson('/api/orders', ['items' => [['product_id' => $p->id, 'quantity' => 1]]])->json('data.id');
        $this->orderError = new BadRequestError('Authentication failed', 'BAD_REQUEST_ERROR', 401);

        $this->postJson("/api/orders/{$id}/payment")->assertStatus(502)
            ->assertJsonPath('success', false)->assertJsonPath('code', 'payment_gateway_error')
            ->assertJsonPath('message', 'Payment provider error: Authentication failed. Please try again.');
        $this->assertSame(0, Payment::count());
    }

    public function test_unexpected_errors_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);
        $p = Product::create(['name' => 'Mouse', 'price' => 50000, 'stock_quantity' => 5]);
        $id = $this->postJson('/api/orders', ['items' => [['product_id' => $p->id, 'quantity' => 1]]])->json('data.id');
        $this->orderError = new \RuntimeException('SQLSTATE[HY000]: secret table name');

        $res = $this->postJson("/api/orders/{$id}/payment")->assertStatus(500)
            ->assertExactJson(['success' => false, 'code' => 'server_error', 'message' => 'Something went wrong on our side. Please try again.']);
        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
    }

    public function test_webhook_errors_use_the_failure_envelope(): void
    {
        $this->call('POST', '/api/webhooks/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => 'bad'], '{}')
            ->assertStatus(400)->assertJsonPath('success', false)->assertJsonPath('status', 'invalid_signature');
        $this->assertSame(0, WebhookEvent::count());
    }
}
