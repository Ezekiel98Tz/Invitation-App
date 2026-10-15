<?php

namespace App\Delivery;

/**
 * Result of a transport send attempt.
 *
 * `$accepted` means the transport accepted/submitted the message — it is NOT
 * proof of delivery. `$mode` distinguishes a real provider response
 * ('provider') from a local sandbox capture ('sandbox'). `$referenceId` is only
 * ever set from a real provider response; sandbox never fabricates one.
 */
final class TransportResult
{
    /**
     * @param  array<string, mixed>  $meta  Safe, non-secret diagnostics.
     */
    public function __construct(
        public readonly bool $accepted,
        public readonly string $mode,
        public readonly ?string $referenceId = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $reason = null,
        public readonly ?string $error = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function accepted(string $mode, ?string $referenceId = null, ?int $httpStatus = null, array $meta = []): self
    {
        return new self(true, $mode, $referenceId, $httpStatus, null, null, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function failed(string $mode, string $reason, string $error, ?int $httpStatus = null, array $meta = []): self
    {
        return new self(false, $mode, null, $httpStatus, $reason, $error, $meta);
    }
}
