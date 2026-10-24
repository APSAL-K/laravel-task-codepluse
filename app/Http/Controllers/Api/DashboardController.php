<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\WebhookEvent;

class DashboardController extends Controller
{
    public function stats()
    {
        return response()->json(['data' => [
            'products' => Product::count(),
            'low_stock' => Product::where('stock_quantity', '<=', 5)->count(),
            'orders' => Order::count(),
            'paid_orders' => Order::where('payment_status', 'paid')->count(),
            'unpaid_orders' => Order::where('payment_status', 'unpaid')->where('order_status', '!=', 'cancelled')->count(),
            'cancelled_orders' => Order::where('order_status', 'cancelled')->count(),
            'revenue' => (int) Order::where('payment_status', 'paid')->where('order_status', 'confirmed')->sum('total_amount'),
            'webhook_events' => WebhookEvent::count(),
            'webhook_failed' => WebhookEvent::where('processing_status', 'failed')->count(),
        ]]);
    }

    public function webhookEvents()
    {
        return response()->json(['data' => WebhookEvent::latest('id')->limit(100)->get()]);
    }
}
