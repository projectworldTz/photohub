<?php

namespace App\Services\WhatsApp;

interface WhatsAppGatewayInterface
{
    public function send(string $phone, string $message): void;
}
