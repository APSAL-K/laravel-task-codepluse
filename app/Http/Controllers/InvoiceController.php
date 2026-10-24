<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\InvoiceService;

class InvoiceController extends Controller
{
    public function api(Order $order, InvoiceService $invoices)
    {
        $this->ensureAvailable($order);

        return response()->json(['data' => $invoices->forOrder($order)]);
    }

    public function page(string $orderNumber, InvoiceService $invoices)
    {
        $order = Order::where('order_number', $orderNumber)->firstOrFail();
        abort_unless($order->hasInvoice(), 409, 'Invoice is available only after the payment is completed.');

        return view('invoice', ['invoice' => $invoices->forOrder($order)]);
    }

    private function ensureAvailable(Order $order): void
    {
        abort_unless($order->hasInvoice(), 409, 'Invoice is available only after the payment is completed.');
    }
}
