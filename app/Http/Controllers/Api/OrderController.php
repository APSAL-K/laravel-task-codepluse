<?php

namespace App\Http\Controllers\Api;

use App\Contracts\RazorpayGateway;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::withCount('items')->latest('id')->limit(100)->get()->map(fn ($o) => [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'total_amount' => $o->total_amount,
            'order_status' => $o->order_status,
            'payment_status' => $o->payment_status,
            'items_count' => $o->items_count,
            'can_cancel' => $o->isCancellable(),
            'invoice_url' => $o->invoiceUrl(),
            'created_at' => $o->created_at,
        ]);

        return response()->json(['data' => $orders]);
    }

    public function store(Request $request, OrderService $orders)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'customer' => ['nullable', 'array'],
            'customer.name' => ['nullable', 'string', 'max:120'],
            'customer.email' => ['nullable', 'email', 'max:190'],
            'customer.contact' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\-\s()]{6,32}$/'],
        ], [
            'items.required' => 'Add at least one item to the order.',
            'items.*.product_id.exists' => 'Product :input does not exist.',
            'items.*.quantity.min' => 'Quantity must be at least 1.',
            'customer.contact.regex' => 'Enter a valid phone number.',
        ]);

        $order = $orders->create($data['items'], $data['customer'] ?? []);

        return response()->json(['data' => $this->present($order)], 201);
    }

    public function show(Order $order)
    {
        $order->load(['items.product:id,name', 'payments.attempts']);

        return response()->json(['data' => $this->present($order)]);
    }

    public function cancel(Order $order, OrderService $orders, RazorpayGateway $gateway)
    {
        $order = $orders->cancel($order, $gateway)->load(['items.product:id,name', 'payments.attempts']);

        return response()->json(['message' => 'Order cancelled.', 'data' => $this->present($order)]);
    }

    private function present(Order $order): array
    {
        $order->loadMissing('items');

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'total_amount' => $order->total_amount,
            'order_status' => $order->order_status,
            'payment_status' => $order->payment_status,
            'can_cancel' => $order->isCancellable(),
            'invoice_url' => $order->invoiceUrl(),
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'contact' => $order->customer_phone,
            ],
            'items' => $order->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'name' => $i->relationLoaded('product') ? $i->product?->name : null,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price,
                'subtotal' => $i->subtotal,
            ])->values(),
            'payments' => $order->relationLoaded('payments')
                ? $order->payments->map(fn ($p) => [
                    'razorpay_order_id' => $p->razorpay_order_id,
                    'razorpay_payment_id' => $p->razorpay_payment_id,
                    'amount' => $p->amount,
                    'currency' => $p->currency,
                    'status' => $p->status,
                    'verification_status' => $p->verification_status,
                    'confirmed_via' => $p->confirmed_via,
                    'payment_link' => $p->status === 'cancelled' ? null : $p->payment_link_url,
                    'attempts' => $p->attempts->map(fn ($a) => [
                        'razorpay_payment_id' => $a->razorpay_payment_id,
                        'status' => $a->status,
                        'method' => $a->method,
                        'method_detail' => $a->method_detail,
                        'error_code' => $a->error_code,
                        'error_description' => $a->error_description,
                    ])->values(),
                ])->values()
                : [],
        ];
    }
}
