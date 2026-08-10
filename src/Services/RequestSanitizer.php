<?php

namespace ServerAvatar\Watchtower\Services;

class RequestSanitizer
{
    /**
     * Default sensitive field patterns to redact.
     */
    protected array $sensitiveFields = [
        // Passwords
        'password',
        'passwd',
        'pwd',
        'secret',

        // Authentication
        'token',
        'api_key',
        'apikey',
        'api-key',
        'auth',
        'authorization',
        'bearer',

        // Session
        'session',
        'cookie',
        'csrf',
        '_token',

        // Security
        'ssl',
        'cert',
        'private',
        'credential',

        // Common sensitive headers
        'x-api-key',
        'x-auth-token',
        'x-csrf-token',
    ];

    /**
     * Redact sensitive values from an array.
     */
    public function sanitize(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            // Check if this key matches any sensitive pattern
            if ($this->isSensitive($lowerKey)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->sanitize($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Check if a header name is sensitive.
     */
    public function isSensitive(string $key): bool
    {
        foreach ($this->sensitiveFields as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add custom sensitive field patterns.
     */
    public function addSensitiveField(string $pattern): void
    {
        $this->sensitiveFields[] = strtolower($pattern);
    }
}
