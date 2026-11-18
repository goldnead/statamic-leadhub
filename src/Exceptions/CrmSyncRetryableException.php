<?php

namespace Goldnead\Leadhub\Exceptions;

use RuntimeException;

/**
 * Thrown by the CRM sync job when a destination answered with a rate limit or
 * a server error. The queue then retries the job (up to its `tries`), and a
 * last failure lands in failed_jobs instead of disappearing.
 */
class CrmSyncRetryableException extends RuntimeException
{
    /**
     * @param  array<string, int|null>  $codes  status code per destination key
     */
    public static function for(string $contactUuid, array $codes): self
    {
        $parts = [];
        foreach ($codes as $key => $code) {
            $parts[] = $key.' (HTTP '.($code ?? '?').')';
        }

        return new self('[LeadHub] CRM sync for contact '.$contactUuid.' failed temporarily: '.implode(', ', $parts));
    }
}
