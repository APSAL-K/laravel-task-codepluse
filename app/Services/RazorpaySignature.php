<?php

namespace App\Services;

class RazorpaySignature
{
    public function checkoutIsValid(string $orderId, string $paymentId, ?string $signature): bool
    {
        return $this->matches($orderId.'|'.$paymentId, $signature, (string) config('razorpay.key_secret'));
    }

    public function webhookIsValid(string $rawBody, ?string $signature): bool
    {
        return $this->matches($rawBody, $signature, (string) config('razorpay.webhook_secret'));
    }

    private function matches(string $message, ?string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $message, $secret), $signature);
    }
}
