<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/products', [ProductController::class, 'index']);
Route::post('/products', [ProductController::class, 'store']);
Route::put('/products/{product}', [ProductController::class, 'update']);
Route::delete('/products/{product}', [ProductController::class, 'destroy']);

Route::get('/orders', [OrderController::class, 'index']);
Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{order}', [OrderController::class, 'show']);
Route::post('/orders/{order}/payment', [PaymentController::class, 'create']);
Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
Route::get('/orders/{order}/invoice', [InvoiceController::class, 'api']);

Route::post('/payments/verify', [PaymentController::class, 'verify']);

Route::post('/webhooks/razorpay', [WebhookController::class, 'razorpay']);

Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
Route::get('/webhook-events', [DashboardController::class, 'webhookEvents']);
