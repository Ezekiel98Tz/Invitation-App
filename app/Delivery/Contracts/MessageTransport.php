<?php

namespace App\Delivery\Contracts;

use App\Delivery\TransportResult;

/**
 * A delivery transport performs the actual send for one channel. Composition,
 * persistence and presentation live elsewhere, so a future real provider can be
 * added by implementing this interface — the notifications, jobs, delivery
 * records and Delivery Lab UI stay unchanged.
 */
interface MessageTransport
{
    /**
     * @param  string  $channel  'sms' or 'whatsapp'.
     * @param  array{to: string, message: string, sender: ?string}  $payload
     */
    public function send(string $channel, array $payload): TransportResult;
}
