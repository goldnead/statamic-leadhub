<?php

namespace Goldnead\Leadhub\Support;

/**
 * The outcome of pushing a contact to a single CRM destination.
 */
class SyncResult
{
    public function __construct(
        public bool $success,
        public ?int $code = null,
        public ?string $message = null,
        public ?string $remoteId = null,
    ) {}

    public static function ok(?string $remoteId = null, ?int $code = 200, ?string $message = null): self
    {
        return new self(true, $code, $message, $remoteId);
    }

    public static function fail(?string $message = null, ?int $code = null): self
    {
        return new self(false, $code, $message);
    }

    /**
     * A failure worth another attempt: the remote is rate limiting or broken
     * right now. A 4xx it will answer the same way next time is not.
     */
    public function isRetryable(): bool
    {
        return ! $this->success
            && $this->code !== null
            && ($this->code === 429 || $this->code >= 500);
    }
}
