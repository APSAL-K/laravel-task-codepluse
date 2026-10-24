<?php

namespace Tests\Feature;

use App\Contracts\RazorpayGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_SECRET = 'test_key_secret';
    private const WEBHOOK_SECRET = 'test_webhook_secret';

    private int $rzpOrderSeq = 0;

    public array $cancelledLinks = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'razorpay.key_id' => 'rzp_test_abc',
            'razorpay.key_secret' => self::KEY_SECRET,
            'razorpay.webhook_secret' => self::WEBHOOK_SECRET,
            'razorpay.currency' => 'INR',
        ]);

        $this->app->bind(RazorpayGateway::class, fn () => new class($this) implements RazorpayGateway {
            public function __construct(private PaymentWorkflowTest $t) {}

            public function createOrder(int $amount, string $currency, string $receipt): array
            {
                return ['id' => 'order_'.$this->t->nextRzpOrder(), 'amount' => $amount, 'currency' => $currency];
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

    public function nextRzpOrder(): int
    {
        return ++$this->rzpOrderSeq;
    }

    private function makeOrderWithPayment(int $qty = 2): array
    {
        $product = Product::create(['name' => 'Mouse', 'price' => 50000, 'stock_quantity' => 10]);
        $orderId = $this->postJson('/api/orders', ['items' => [['product_id' => $product->id, 'quantity' => $qty]]])
            ->assertCreated()->json('data.id');
        $checkout = $this->postJson("/api/orders/{$orderId}/payment")->assertOk()->json('data');

        return [Order::find($orderId), Payment::where('razorpay_order_id', $checkout['razorpay_order_id'])->first(), $checkout];
    }

    private function entity(Payment $p, string $paymentId, array $extra = []): array
    {
        return array_merge([
            'id' => $paymentId, 'order_id' => $p->razorpay_order_id,
            'amount' => $p->amount, 'currency' => $p->currency,
        ], $extra);
    }

    private function webhook(array $payload, ?string $eventId = null, ?string $signature = null, ?string $rawOverride = null)
    {
        $raw = $rawOverride ?? json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature ??= hash_hmac('sha256', $raw, self::WEBHOOK_SECRET);

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($signature !== '') {
            $server['HTTP_X_RAZORPAY_SIGNATURE'] = $signature;
        }
        if ($eventId) {
            $server['HTTP_X_RAZORPAY_EVENT_ID'] = $eventId;
        }

        return $this->call('POST', '/api/webhooks/razorpay', [], [], [], $server, $raw);
    }

    private function captured(Payment $p, string $paymentId): array
    {
        return ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => $this->entity($p, $paymentId, ['status' => 'captured'])]]];
    }

    private function failed(Payment $p, string $paymentId): array
    {
        return ['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => $this->entity($p, $paymentId, [
            'status' => 'failed', 'error_code' => 'BAD_REQUEST_ERROR', 'error_description' => 'Payment failed',
        ])]]];
    }

    public function test_products_are_listed(): void
    {
        Product::create(['name' => 'Mouse', 'price' => 50000, 'stock_quantity' => 3]);

        $this->getJson('/api/products')->assertOk()
            ->assertJsonPath('data.0.name', 'Mouse')->assertJsonPath('data.0.stock_quantity', 3);
    }

    public function test_order_total_is_computed_from_database_prices_and_client_amount_is_ignored(): void
    {
        $p = Product::create(['name' => 'Mouse', 'price' => 50000, 'stock_quantity' => 10]);

        $res = $this->postJson('/api/orders', [
            'amount' => 1, 'items' => [['product_id' => $p->id, 'quantity' => 2]],
        ])->assertCreated();

        $res->assertJsonPath('data.total_amount', 100000)
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.items.0.unit_price', 50000);
        $this->assertSame(8, $p->fresh()->stock_quantity);
    }

    public function test_order_validation_and_stock(): void
    {
        $p = Product::create(['name' => 'Mouse', 'price' => 50000, 'stock_quantity' => 1]);

        $this->postJson('/api/orders', ['items' => [['product_id' => 999, 'quantity' => 1]]])->assertUnprocessable();
        $this->postJson('/api/orders', ['items' => [['product_id' => $p->id, 'quantity' => 0]]])->assertUnprocessable();
        $this->postJson('/api/orders', ['items' => [['product_id' => $p->id, 'quantity' => 2]]])->assertUnprocessable();

        $this->assertSame(0, Order::count(), 'failed order must roll back entirely');
        $this->assertSame(1, $p->fresh()->stock_quantity);
    }

    public function test_payment_endpoint_exposes_only_public_checkout_details(): void
    {
        [, , $checkout] = $this->makeOrderWithPayment();

        $this->assertSame(['key_id', 'razorpay_order_id', 'amount', 'currency', 'order_reference', 'order_id', 'payment_link'], array_keys($checkout));
        $this->assertSame(100000, $checkout['amount']);
        $this->assertStringStartsWith('https://rzp.io/i/', $checkout['payment_link']);
        $this->assertStringNotContainsString(self::KEY_SECRET, json_encode($checkout));
    }

    public function test_verify_accepts_valid_signature_and_rejects_invalid(): void
    {
        [$order, $payment] = $this->makeOrderWithPayment();

        $this->postJson('/api/payments/verify', [
            'razorpay_order_id' => $payment->razorpay_order_id,
            'razorpay_payment_id' => 'pay_1',
            'razorpay_signature' => 'forged',
        ])->assertStatus(400);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame('invalid_signature', $payment->fresh()->verification_status);

        $sig = hash_hmac('sha256', $payment->razorpay_order_id.'|pay_1', self::KEY_SECRET);
        $this->postJson('/api/payments/verify', [
            'razorpay_order_id' => $payment->razorpay_order_id,
            'razorpay_payment_id' => 'pay_1',
            'razorpay_signature' => $sig,
        ])->assertOk()->assertJsonPath('data.payment_status', 'paid');

        $this->assertSame('verified', $payment->fresh()->verification_status);
    }

    public function test_successful_payment(): void
    {
        [$order, $payment] = $this->makeOrderWithPayment();

        $this->webhook($this->captured($payment, 'pay_1'), 'evt_1')->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame('captured', $payment->fresh()->status);
        $this->assertSame('pay_1', $payment->fresh()->razorpay_payment_id);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('processed', WebhookEvent::first()->processing_status);
    }

    public function test_invalid_or_missing_signature_is_rejected_without_changes(): void
    {
        [$order, $payment] = $this->makeOrderWithPayment();
        $payload = $this->captured($payment, 'pay_1');

        $this->webhook($payload, 'evt_1', 'deadbeef')->assertStatus(400);
        $this->webhook($payload, 'evt_2', '')->assertStatus(400);

        $raw = json_encode($payload, JSON_PRETTY_PRINT);
        $this->webhook($payload, 'evt_3', hash_hmac('sha256', json_encode($payload), self::WEBHOOK_SECRET), $raw)->assertStatus(400);

        $this->assertSame('created', $payment->fresh()->status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_duplicate_webhook_has_no_extra_side_effects(): void
    {
        [$order, $payment] = $this->makeOrderWithPayment();
        $payload = $this->captured($payment, 'pay_1');

        $this->webhook($payload, 'evt_1')->assertOk()->assertJsonPath('status', 'processed');
        $capturedAt = $payment->fresh()->captured_at;
        $this->travel(5)->minutes();
        $this->webhook($payload, 'evt_1')->assertOk()->assertJsonPath('status', 'duplicate');
        $this->webhook($payload, 'evt_1')->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, WebhookEvent::count());
        $this->assertEquals($capturedAt, $payment->fresh()->captured_at);
        $this->assertSame(1, WebhookEvent::first()->attempts);
        $this->assertSame(1, $payment->attempts()->count());
    }

    public function test_duplicate_without_event_id_header_is_deduplicated_by_body_hash(): void
    {
        [, $payment] = $this->makeOrderWithPayment();
        $payload = $this->captured($payment, 'pay_1');

        $this->webhook($payload)->assertOk()->assertJsonPath('status', 'processed');
        $this->webhook($payload)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_failed_payment_then_successful_retry(): void
    {
        [$order, $payment] = $this->makeOrderWithPayment();

        $this->webhook($this->failed($payment, 'pay_fail'), 'evt_1')->assertOk();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame('failed', $payment->fresh()->status);

        $again = $this->postJson("/api/orders/{$order->id}/payment")->assertOk()->json('data');
        $this->assertSame($payment->razorpay_order_id, $again['razorpay_order_id']);

        $sig = hash_hmac('sha256', $payment->razorpay_order_id.'|pay_ok', self::KEY_SECRET);
        $this->postJson('/api/payments/verify', [
            'razorpay_order_id' => $payment->razorpay_order_id,
            'razorpay_payment_id' => 'pay_ok',
            'razorpay_signature' => $sig,
        ])->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('captured', $payment->fresh()->status);
        $this->assertEqualsCanonicalizing(
            ['pay_fail' => 'failed', 'pay_ok' => 'captured'],
            $payment->attempts()->pluck('status', 'razorpay_payment_id')->all(),
        );
    }

    public function test_webhook_settles_order_without_any_checkout_callback(): void
    {
        [$order, $payment] = $this->makeOrderWithPayment();

        $this->webhook($this->captured($payment, 'pay_1'), 'evt_1')->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('webhook', $payment->fresh()->confirmed_via);
        $this->assertNull($payment->fresh()->verification_status);
    }

    public function test_delayed_failure_cannot_downgrade_a_paid_order(): void
    {
        [$order, $payment] = $this->makeOrderWithPayment();

        $this->webhook($this->captured($payment, 'pay_1'), 'evt_1')->assertOk();
        $this->webhook($this->failed($payment, 'pay_1'), 'evt_2')->assertOk();
        $this->webhook($this->failed($payment, 'pay_other'), 'evt_3')->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('captured', $payment->fresh()->status);
        $this->assertSame('captured', $payment->attempts()->where('razorpay_payment_id', 'pay_1')->value('status'));
        $this->assertSame(3, WebhookEvent::where('processing_status', 'processed')->count());
    }

    public function test_amount_mismatch_does_not_mark_paid(): void
    {
        [$order, $payment] = $this->makeOrderWithPayment();
        $payload = $this->captured($payment, 'pay_1');
        $payload['payload']['payment']['entity']['amount'] = 100;

        $this->webhook($payload, 'evt_1')->assertOk()->assertJsonPath('status', 'ignored');
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_order_paid_event_is_handled(): void
    {
        [$order, $payment] = $this->makeOrderWithPayment();
        $payload = [
            'event' => 'order.paid',
            'payload' => [
                'payment' => ['entity' => $this->entity($payment, 'pay_1')],
                'order' => ['entity' => ['id' => $payment->razorpay_order_id, 'amount_paid' => $payment->amount, 'currency' => 'INR']],
            ],
        ];

        $this->webhook($payload, 'evt_1')->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);

        $this->webhook($this->captured($payment, 'pay_1'), 'evt_2')->assertOk();
        $this->assertSame(1, $payment->attempts()->count());
    }

    public function test_payment_link_is_reused_and_payment_link_paid_settles_the_order(): void
    {
        [$order, $payment, $checkout] = $this->makeOrderWithPayment();

        $again = $this->postJson("/api/orders/{$order->id}/payment")->assertOk()->json('data');
        $this->assertSame($checkout['payment_link'], $again['payment_link']);
        $this->assertSame(1, Payment::count());

        $payload = [
            'event' => 'payment_link.paid',
            'payload' => [
                'payment_link' => ['entity' => ['id' => $payment->fresh()->payment_link_id, 'amount_paid' => $payment->amount]],
                'payment' => ['entity' => [
                    'id' => 'pay_link1', 'order_id' => 'order_created_by_link', 'amount' => $payment->amount,
                    'currency' => 'INR', 'method' => 'card', 'card' => ['network' => 'Visa', 'last4' => '4242'],
                ]],
            ],
        ];
        $this->webhook($payload, 'evt_link')->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('payment_link', $payment->fresh()->confirmed_via);
        $attempt = $payment->attempts()->first();
        $this->assertSame('card', $attempt->method);
        $this->assertSame('Visa ****4242', $attempt->method_detail);

        $follow = $this->captured($payment, 'pay_link1');
        $follow['payload']['payment']['entity']['order_id'] = 'order_created_by_link';
        $this->webhook($follow, 'evt_link2')->assertOk();
        $this->assertSame(1, $payment->attempts()->count());

        $this->getJson("/api/orders/{$order->id}")->assertJsonPath('data.payments.0.attempts.0.method', 'card')
            ->assertJsonPath('data.payments.0.payment_link', $checkout['payment_link']);
    }

    public function test_payment_still_works_when_link_creation_fails(): void
    {
        $this->app->bind(RazorpayGateway::class, fn () => new class implements RazorpayGateway {
            public function createOrder(int $amount, string $currency, string $receipt): array
            {
                return ['id' => 'order_x'];
            }

            public function createPaymentLink(int $amount, string $currency, string $referenceId, string $description, ?string $callbackUrl = null): array
            {
                throw new \RuntimeException('links not enabled');
            }

            public function cancelPaymentLink(string $paymentLinkId): void {}
        });
        $p = Product::create(['name' => 'Mouse', 'price' => 50000, 'stock_quantity' => 10]);
        $id = $this->postJson('/api/orders', ['items' => [['product_id' => $p->id, 'quantity' => 1]]])->json('data.id');

        $this->postJson("/api/orders/{$id}/payment")->assertOk()
            ->assertJsonPath('data.razorpay_order_id', 'order_x')->assertJsonPath('data.payment_link', null);
    }

    public function test_unknown_event_is_audited_and_acknowledged(): void
    {
        $this->webhook(['event' => 'refund.created', 'payload' => []], 'evt_1')->assertOk()->assertJsonPath('status', 'ignored');
        $this->assertSame('ignored', WebhookEvent::first()->processing_status);
    }
}
