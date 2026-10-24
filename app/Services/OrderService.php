<?php

namespace App\Services;

use App\Contracts\RazorpayGateway;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function create(array $items, array $customer = []): Order
    {
        $quantities = [];
        foreach ($items as $item) {
            $id = (int) $item['product_id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) $item['quantity'];
        }
        ksort($quantities);

        return DB::transaction(function () use ($quantities, $customer) {
            $products = Product::whereIn('id', array_keys($quantities))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'total_amount' => 0,
                'order_status' => 'pending',
                'payment_status' => 'unpaid',
                'customer_name' => $customer['name'] ?? null,
                'customer_email' => $customer['email'] ?? null,
                'customer_phone' => $customer['contact'] ?? null,
            ]);

            $total = 0;
            foreach ($quantities as $productId => $quantity) {
                $product = $products->get($productId);
                if (! $product) {
                    throw ValidationException::withMessages(['items' => "Product {$productId} does not exist."]);
                }

                $reserved = Product::where('id', $productId)
                    ->where('stock_quantity', '>=', $quantity)
                    ->decrement('stock_quantity', $quantity);

                if ($reserved === 0) {
                    throw ValidationException::withMessages([
                        'items' => "Insufficient stock for '{$product->name}'.",
                    ]);
                }

                $subtotal = $product->price * $quantity;
                $total += $subtotal;

                $order->items()->create([
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'subtotal' => $subtotal,
                ]);
            }

            $order->update(['total_amount' => $total]);

            return $order->load('items');
        });
    }

    public function cancel(Order $order, RazorpayGateway $gateway): Order
    {
        $linkIds = DB::transaction(function () use ($order) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if ($order->order_status === 'cancelled') {
                return [];
            }
            if (! $order->isCancellable() || $order->payments()->where('status', 'captured')->exists()) {
                abort(409, 'This order is already paid and can no longer be cancelled.');
            }

            $items = $order->items()->orderBy('product_id')->get();
            foreach ($items as $item) {
                Product::where('id', $item->product_id)->increment('stock_quantity', $item->quantity);
            }

            $payments = $order->payments()->whereIn('status', ['created', 'failed'])->get();
            $linkIds = $payments->pluck('payment_link_id')->filter()->values()->all();
            $order->payments()->whereIn('status', ['created', 'failed'])->update(['status' => 'cancelled']);

            $order->update(['order_status' => 'cancelled']);

            return $linkIds;
        });

        foreach ($linkIds as $linkId) {
            try {
                $gateway->cancelPaymentLink($linkId);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $order->fresh();
    }

    private function generateOrderNumber(): string
    {
        do {
            $number = 'ORD-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }
}
