<?php

namespace App\Providers;

use App\Contracts\RazorpayGateway;
use App\Services\RazorpayApiGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RazorpayGateway::class, RazorpayApiGateway::class);
    }

    public function boot(): void
    {
    }
}
