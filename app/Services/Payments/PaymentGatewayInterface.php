<?php

namespace App\Services\Payments;

interface PaymentGatewayInterface
{
    public function initiate(array $payload): array;

    public function verify(string $reference): array;
}
