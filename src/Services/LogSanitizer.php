<?php

namespace ServerAvatar\Watchtower\Services;

class LogSanitizer
{
    /**
     * Keys that should be redacted in log context.
     */
    protected array $sensitiveKeys = [
        // Passwords
        'password',
        'password_confirmation',
        'passwd',
        'pass',
        // Auth tokens
        'token',
        'api_token',
        'apiKey',
        'api_key',
        'access_token',
        'accessToken',
        'refresh_token',
        'refreshToken',
        'bearer',
        'authorization',
        'auth_token',
        'authToken',
        'client_secret',
        'clientSecret',
        'secret',
        'secret_key',
        'secretKey',
        // Session/cookie
        'session',
        'session_id',
        'sessionId',
        'cookie',
        'cookies',
        'csrf_token',
        'csrfToken',
        'csrftoken',
        '_token',
        // Database
        'db_password',
        'db_password',
        'database_password',
        'db_host',
        'db_user',
        'db_username',
        'mysql_password',
        'postgres_password',
        'pgsql_password',
        // AWS
        'aws_access_key',
        'aws_secret_key',
        'aws_token',
        // Private/personal
        'private_key',
        'privateKey',
        'private',
        'personal',
        'ssn',
        'social_security',
        'credit_card',
        'card_number',
        'cvv',
        'cvc',
        // Laravel specific
        'laravel_token',
        'xcsrf_token',
        // Generic secrets
        'app_key',
        'appSecret',
        'app_secret',
        'encryption_key',
        'hmac_key',
        'hash',
        'signature',
        'WebhookSecret',
        'webhook_secret',
        // HTTP headers sensitive keys (partial match)
        'x-api-key',
        'x-auth-token',
        'x-csrf-token',
    ];

    /**
     * Patterns that indicate sensitive values.
     */
    protected array $sensitivePatterns = [
        '/Bearer\s+[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+/i' => 'Bearer [REDACTED]',
        '/token["\']?\s*[:=]\s*["\']?[a-zA-Z0-9\-_]{20,}/i' => 'token: [REDACTED]',
        '/api[_-]?key["\']?\s*[:=]\s*["\']?[a-zA-Z0-9\-_]{16,}/i' => 'api_key: [REDACTED]',
        '/password["\']?\s*[:=]\s*["\']?[^\s"\']]{3,}/i' => 'password: [REDACTED]',
        '/secret["\']?\s*[:=]\s*["\']?[^\s"\']]{3,}/i' => 'secret: [REDACTED]',
    ];

    /**
     * HTTP header names that are sensitive.
     */
    protected array $sensitiveHeaders = [
        'authorization',
        'cookie',
        'set-cookie',
        'x-csrf-token',
        'x-xsrf-token',
        'x-api-key',
        'x-auth-token',
    ];

    /**
     * Maximum depth for sanitizing nested arrays.
     */
    protected int $maxDepth = 10;

    /**
     * Sanitize log context data by redacting sensitive values.
     */
    public function sanitize(mixed $context, int $depth = 0): mixed
    {
        if ($depth >= $this->maxDepth) {
            return '[MAX_DEPTH_EXCEEDED]';
        }

        if (is_array($context)) {
            return $this->sanitizeArray($context, $depth);
        }

        if (is_string($context)) {
            return $this->sanitizeString($context);
        }

        return $context;
    }

    /**
     * Sanitize an array recursively.
     */
    protected function sanitizeArray(array $context, int $depth): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            // Check if this key is sensitive
            if ($this->isSensitiveKey($key)) {
                $sanitized[$key] = $this->redact($key);
                continue;
            }

            // Recursively sanitize nested structures
            $sanitized[$key] = $this->sanitize($value, $depth + 1);
        }

        return $sanitized;
    }

    /**
     * Sanitize a string value.
     */
    protected function sanitizeString(string $value): string
    {
        foreach ($this->sensitivePatterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value);
        }

        return $value;
    }

    /**
     * Check if a key name is sensitive.
     */
    protected function isSensitiveKey(string $key): bool
    {
        $keyLower = strtolower($key);

        foreach ($this->sensitiveKeys as $sensitiveKey) {
            if (str_contains($keyLower, strtolower($sensitiveKey))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the redacted representation for a sensitive key.
     */
    protected function redact(string $key): string
    {
        return '[REDACTED]';
    }

    /**
     * Sanitize HTTP headers.
     */
    public function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $name => $value) {
            if ($this->isSensitiveHeader($name)) {
                $sanitized[$name] = is_array($value) ? ['[REDACTED]'] : ['[REDACTED]'];
            } else {
                $sanitized[$name] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Check if a header name is sensitive.
     */
    protected function isSensitiveHeader(string $name): bool
    {
        return in_array(strtolower($name), $this->sensitiveHeaders, true);
    }

    /**
     * Add custom sensitive keys at runtime.
     */
    public function addSensitiveKeys(array $keys): void
    {
        $this->sensitiveKeys = array_merge($this->sensitiveKeys, $keys);
    }

    /**
     * Add custom sensitive patterns at runtime.
     */
    public function addSensitivePatterns(array $patterns): void
    {
        $this->sensitivePatterns = array_merge($this->sensitivePatterns, $patterns);
    }
}
