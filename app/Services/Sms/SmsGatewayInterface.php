<?php

namespace App\Services\Sms;

interface SmsGatewayInterface
{
    public function send(string $phone, string $message): void;
}
