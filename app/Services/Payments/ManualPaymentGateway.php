<?php

namespace App\Services\Payments;

class ManualPaymentGateway implements PaymentGatewayInterface
{
    public function initiate(array $payload): array
    {
        return ['status' => 'manual', 'reference' => $payload['reference'] ?? null];
    }

    public function verify(string $reference): array
    {
        return ['status' => 'pending', 'reference' => $reference];
    }
}
