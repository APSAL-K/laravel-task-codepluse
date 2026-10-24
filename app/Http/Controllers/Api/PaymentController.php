<?php

namespace App\Http\Controllers\Api;

use App\Contracts\RazorpayGateway;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentSettlementService;
use App\Services\RazorpaySignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function create(Order $order, RazorpayGateway $gateway)
    {
        $payment = DB::transaction(function () use ($order, $gateway) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if ($order->payment_status === 'paid') {
                abort(409, 'Order is already paid.');
            }
            if ($order->order_status === 'cancelled') {
                abort(409, 'This order was cancelled. Place a new order to pay.');
            }

            $payment = $order->payments()->whereIn('status', ['created', 'failed'])->latest('id')->first();

            if (! $payment) {
                $currency = config('razorpay.currency');
                $rzpOrder = $gateway->createOrder($order->total_amount, $currency, $order->order_number);

                $payment = $order->payments()->create([
                    'razorpay_order_id' => $rzpOrder['id'],
                    'amount' => $order->total_amount,
                    'currency' => $currency,
                    'status' => 'created',
                ]);
            }

            return $this->ensurePaymentLink($payment, $order, $gateway);
        });

        return response()->json(['data' => [
            'key_id' => config('razorpay.key_id'),
            'razorpay_order_id' => $payment->razorpay_order_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'order_reference' => $order->order_number,
            'order_id' => $order->id,
            'payment_link' => $payment->payment_link_url,
        ]]);
    }

    private function ensurePaymentLink(Payment $payment, Order $order, RazorpayGateway $gateway): Payment
    {
        if ($payment->payment_link_url) {
            return $payment;
        }

        try {
            $link = $gateway->createPaymentLink(
                $payment->amount,
                $payment->currency,
                $order->order_number.'-'.$payment->id,
                'Payment for order '.$order->order_number,
                url('/')."?order={$order->id}",
            );
            $payment->update(['payment_link_id' => $link['id'], 'payment_link_url' => $link['short_url']]);
        } catch (\Throwable $e) {
            report($e);
        }

        return $payment;
    }

    public function verify(Request $request, RazorpaySignature $signature, PaymentSettlementService $settlement)
    {
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $payment = Payment::where('razorpay_order_id', $data['razorpay_order_id'])->first();
        if (! $payment) {
            return response()->json(['code' => 'not_found', 'message' => 'No payment found for this Razorpay order.'], 404);
        }

        if (! $signature->checkoutIsValid($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature'])) {
            if ($payment->status !== 'captured') {
                $payment->update(['verification_status' => 'invalid_signature']);
            }

            return response()->json(['code' => 'invalid_signature', 'message' => 'Payment signature could not be verified.'], 400);
        }

        $result = $settlement->markCaptured($payment, $data['razorpay_payment_id'], 'checkout_verify');

        if ($result === PaymentSettlementService::CONFLICT) {
            return response()->json(['message' => 'Order already paid with a different payment.'], 409);
        }

        $payment->update(['verification_status' => 'verified', 'verified_at' => now()]);

        return response()->json(['data' => [
            'status' => 'verified',
            'result' => $result,
            'order_id' => $payment->order_id,
            'payment_status' => $payment->order->fresh()->payment_status,
        ]]);
    }
}
