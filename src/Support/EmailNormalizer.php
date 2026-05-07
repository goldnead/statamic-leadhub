<?php

namespace Goldnead\Leadhub\Support;

class EmailNormalizer
{
    /**
     * Normalize an email address for deduplication.
     *
     * Defaults: trim + lowercase. No aggressive Gmail-specific rules
     * (those break business addresses).
     */
    public static function normalize(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $config = config('leadhub.email_normalization', [
            'trim' => true,
            'lowercase' => true,
        ]);

        $value = $email;

        if ($config['trim'] ?? true) {
            $value = trim($value);
        }

        if ($config['lowercase'] ?? true) {
            $value = mb_strtolower($value);
        }

        return $value === '' ? null : $value;
    }

    public static function isValid(?string $email): bool
    {
        if ($email === null) {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
