<?php

namespace App\Services;

use App\Models\Order;

class InvoiceService
{
    public function forOrder(Order $order): array
    {
        $order->load(['items.product:id,name', 'payments.attempts']);

        $payment = $order->payments->firstWhere('status', 'captured');
        $attempt = $payment?->attempts->firstWhere('status', 'captured');

        return [
            'invoice_number' => $order->invoiceNumber(),
            'order_number' => $order->order_number,
            'invoice_date' => ($payment?->captured_at ?? $order->updated_at)?->toIso8601String(),
            'status' => 'paid',
            'currency' => $payment?->currency ?? config('razorpay.currency'),
            'seller' => [
                'name' => config('shop.name'),
                'address' => config('shop.address'),
                'gstin' => config('shop.gstin'),
                'email' => config('shop.email'),
            ],
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
            ],
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->product?->name ?? 'Product #'.$i->product_id,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price,
                'subtotal' => $i->subtotal,
            ])->values()->all(),
            'total' => $order->total_amount,
            'payment' => [
                'razorpay_payment_id' => $payment?->razorpay_payment_id,
                'method' => $attempt?->method,
                'method_detail' => $attempt?->method_detail,
                'confirmed_via' => $payment?->confirmed_via,
                'paid_at' => $payment?->captured_at?->toIso8601String(),
            ],
            'url' => $order->invoiceUrl(),
        ];
    }
}
