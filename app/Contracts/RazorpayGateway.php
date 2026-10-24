<?php

namespace App\Contracts;

interface RazorpayGateway
{
    public function createOrder(int $amount, string $currency, string $receipt): array;

    public function createPaymentLink(int $amount, string $currency, string $referenceId, string $description, ?string $callbackUrl = null): array;

    public function cancelPaymentLink(string $paymentLinkId): void;
}
