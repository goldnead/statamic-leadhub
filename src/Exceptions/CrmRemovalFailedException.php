<?php

namespace Goldnead\Leadhub\Exceptions;

use RuntimeException;

/**
 * Thrown by the opt-out removal job when a destination did not take the
 * removal. The queue then retries (up to the job's `tries`), and a last
 * failure lands in failed_jobs and on the contact's timeline.
 */
class CrmRemovalFailedException extends RuntimeException
{
    /**
     * @param  array<string, string|null>  $failures  message per destination key
     */
    public static function for(string $contactUuid, array $failures): self
    {
        $parts = [];
        foreach ($failures as $key => $message) {
            $parts[] = $key.($message ? ' ('.$message.')' : '');
        }

        return new self('[LeadHub] Opt-out removal for contact '.$contactUuid.' failed: '.implode(', ', $parts));
    }
}
