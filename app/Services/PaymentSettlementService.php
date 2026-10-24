<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentSettlementService
{
    public const CAPTURED = 'captured';
    public const ALREADY_CAPTURED = 'already_captured';
    public const CONFLICT = 'conflict';

    public static function methodFields(?array $entity): array
    {
        if (! $entity || empty($entity['method'])) {
            return [];
        }

        $detail = match ($entity['method']) {
            'card' => trim(($entity['card']['network'] ?? '').' ****'.($entity['card']['last4'] ?? '')),
            'netbanking' => $entity['bank'] ?? null,
            'wallet' => $entity['wallet'] ?? null,
            default => null,
        };

        return ['method' => $entity['method'], 'method_detail' => $detail ?: null];
    }

    public function markCaptured(Payment $payment, string $razorpayPaymentId, string $via, ?array $entity = null): string
    {
        return DB::transaction(function () use ($payment, $razorpayPaymentId, $via, $entity) {
            $order = Order::lockForUpdate()->findOrFail($payment->order_id);
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);

            if ($payment->status === 'captured') {
                return $payment->razorpay_payment_id === $razorpayPaymentId
                    ? self::ALREADY_CAPTURED
                    : self::CONFLICT;
            }

            PaymentAttempt::updateOrCreate(
                ['razorpay_payment_id' => $razorpayPaymentId],
                ['payment_id' => $payment->id, 'status' => 'captured', 'error_code' => null, 'error_description' => null]
                    + self::methodFields($entity),
            );

            $payment->update([
                'status' => 'captured',
                'razorpay_payment_id' => $razorpayPaymentId,
                'confirmed_via' => $via,
                'captured_at' => now(),
            ]);

            if ($order->payment_status === 'paid') {
                Log::warning('Order already paid; extra captured payment needs review/refund', [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'razorpay_payment_id' => $razorpayPaymentId,
                ]);
            } elseif ($order->order_status === 'cancelled') {
                $order->update(['payment_status' => 'paid']);
                Log::warning('Payment captured for a cancelled order; refund required', [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'razorpay_payment_id' => $razorpayPaymentId,
                ]);
            } else {
                $order->update(['payment_status' => 'paid', 'order_status' => 'confirmed']);
            }

            return self::CAPTURED;
        });
    }

    public function markFailed(Payment $payment, string $razorpayPaymentId, ?string $code, ?string $description, ?array $entity = null): void
    {
        DB::transaction(function () use ($payment, $razorpayPaymentId, $code, $description, $entity) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);

            $attempt = PaymentAttempt::where('razorpay_payment_id', $razorpayPaymentId)->first();
            if ($attempt?->status === 'captured') {
                return;
            }

            PaymentAttempt::updateOrCreate(
                ['razorpay_payment_id' => $razorpayPaymentId],
                [
                    'payment_id' => $payment->id,
                    'status' => 'failed',
                    'error_code' => $code,
                    'error_description' => $description ? mb_substr($description, 0, 255) : null,
                ] + self::methodFields($entity),
            );

            if (! in_array($payment->status, ['captured', 'cancelled'], true)) {
                $payment->update(['status' => 'failed']);
            }
        });
    }
}
