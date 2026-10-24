<?php

use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->file(public_path('index.html')));

Route::get('/invoice/{orderNumber}', [InvoiceController::class, 'page']);
