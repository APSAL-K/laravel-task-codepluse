<?php

namespace App\Services;

use App\Contracts\RazorpayGateway;
use Razorpay\Api\Api;

class RazorpayApiGateway implements RazorpayGateway
{
    private function api(): Api
    {
        return new Api(config('razorpay.key_id'), config('razorpay.key_secret'));
    }

    public function createOrder(int $amount, string $currency, string $receipt): array
    {
        return $this->api()->order->create([
            'amount' => $amount,
            'currency' => $currency,
            'receipt' => $receipt,
        ])->toArray();
    }

    public function createPaymentLink(int $amount, string $currency, string $referenceId, string $description, ?string $callbackUrl = null): array
    {
        $params = [
            'amount' => $amount,
            'currency' => $currency,
            'accept_partial' => false,
            'reference_id' => $referenceId,
            'description' => $description,
            'reminder_enable' => false,
        ];

        if ($callbackUrl && str_starts_with($callbackUrl, 'https://')) {
            $params['callback_url'] = $callbackUrl;
            $params['callback_method'] = 'get';
        }

        return $this->api()->paymentLink->create($params)->toArray();
    }

    public function cancelPaymentLink(string $paymentLinkId): void
    {
        $this->api()->paymentLink->fetch($paymentLinkId)->cancel();
    }
}
