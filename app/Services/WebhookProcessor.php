<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookProcessor
{
    public const INVALID_SIGNATURE = 'invalid_signature';
    public const MALFORMED = 'malformed';
    public const DUPLICATE = 'duplicate';
    public const PROCESSED = 'processed';
    public const IGNORED = 'ignored';
    public const FAILED = 'failed';

    public function __construct(
        private RazorpaySignature $signature,
        private PaymentSettlementService $settlement,
    ) {}

    public function handle(string $rawBody, ?string $signature, ?string $eventIdHeader): string
    {
        if (! $this->signature->webhookIsValid($rawBody, $signature)) {
            Log::warning('Razorpay webhook rejected: invalid signature');

            return self::INVALID_SIGNATURE;
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload) || empty($payload['event'])) {
            return self::MALFORMED;
        }

        $eventId = $eventIdHeader ?: 'sha256:'.hash('sha256', $rawBody);

        try {
            $event = WebhookEvent::create([
                'event_id' => $eventId,
                'event_type' => $payload['event'],
                'payload' => $payload,
                'processing_status' => 'received',
            ]);
        } catch (UniqueConstraintViolationException) {
            $event = WebhookEvent::where('event_id', $eventId)->firstOrFail();
        }

        try {
            return DB::transaction(function () use ($event, $payload) {
                $locked = WebhookEvent::lockForUpdate()->findOrFail($event->id);

                if (in_array($locked->processing_status, ['processed', 'ignored'], true)) {
                    return self::DUPLICATE;
                }

                $locked->increment('attempts');
                [$status, $note] = $this->dispatch($payload);

                $locked->update([
                    'processing_status' => $status,
                    'error' => $note,
                    'processed_at' => now(),
                ]);

                return $status;
            });
        } catch (Throwable $e) {
            report($e);
            WebhookEvent::whereKey($event->id)->update([
                'processing_status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return self::FAILED;
        }
    }

    private function dispatch(array $payload): array
    {
        $event = $payload['event'];
        $paymentEntity = data_get($payload, 'payload.payment.entity');
        $orderEntity = data_get($payload, 'payload.order.entity');

        if (! in_array($event, ['payment.captured', 'payment.failed', 'order.paid', 'payment_link.paid'], true)) {
            return ['ignored', 'unsupported event'];
        }

        $linkEntity = data_get($payload, 'payload.payment_link.entity');
        $razorpayOrderId = $paymentEntity['order_id'] ?? $orderEntity['id'] ?? null;
        $razorpayPaymentId = $paymentEntity['id'] ?? null;
        if (! $razorpayPaymentId) {
            return ['ignored', 'payload missing payment id'];
        }

        $payment = null;
        if ($event === 'payment_link.paid' && ! empty($linkEntity['id'])) {
            $payment = Payment::where('payment_link_id', $linkEntity['id'])->first();
        }
        if (! $payment && $razorpayOrderId) {
            $payment = Payment::where('razorpay_order_id', $razorpayOrderId)->first();
        }
        $payment ??= Payment::where('razorpay_payment_id', $razorpayPaymentId)->first();

        if (! $payment) {
            return ['ignored', 'no local payment for razorpay order '.($razorpayOrderId ?? '(none)')];
        }

        if ($event === 'payment.failed') {
            $this->settlement->markFailed(
                $payment,
                $razorpayPaymentId,
                $paymentEntity['error_code'] ?? null,
                $paymentEntity['error_description'] ?? null,
                $paymentEntity,
            );

            return ['processed', null];
        }

        $amount = (int) ($paymentEntity['amount'] ?? $orderEntity['amount_paid'] ?? -1);
        $currency = $paymentEntity['currency'] ?? $orderEntity['currency'] ?? null;
        if ($amount !== (int) $payment->amount || $currency !== $payment->currency) {
            Log::error('Razorpay webhook amount/currency mismatch', [
                'payment_id' => $payment->id, 'expected' => $payment->amount, 'got' => $amount,
            ]);

            return ['ignored', 'amount or currency mismatch'];
        }

        $result = $this->settlement->markCaptured(
            $payment,
            $razorpayPaymentId,
            $event === 'payment_link.paid' ? 'payment_link' : 'webhook',
            $paymentEntity,
        );

        return ['processed', $result === PaymentSettlementService::CONFLICT ? 'conflicting payment id; kept original' : null];
    }
}
