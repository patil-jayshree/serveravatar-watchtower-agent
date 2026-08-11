<?php

namespace ServerAvatar\Watchtower\Services;

class QuerySanitizer
{
    /**
     * Binding types that are considered sensitive and should be redacted.
     */
    protected array $sensitiveBindingTypes = [
        'password',
        'secret',
        'token',
        'api_key',
        'api-key',
        'apikey',
        'auth',
        'credential',
        'private',
        'session',
    ];

    /**
     * Binding names that are considered sensitive.
     */
    protected array $sensitiveBindingNames = [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'api_key',
        'api_secret',
        'access_token',
        'refresh_token',
        'bearer_token',
        'auth_token',
        'session_id',
        'cookie',
        'authorization',
        'credential',
        'private_key',
        'aws_access_key_id',
        'aws_secret_access_key',
    ];

    /**
     * SQL patterns that should never be captured.
     */
    protected array $blockedPatterns = [
        '#^\s*SHOW\s+(FULL\s+)?TABLES#i',
        '#^\s*SHOW\s+CREATE\s+TABLE#i',
        '#^\s*SHOW\s+INDEX#i',
        '#^\s*SHOW\s+COLUMNS#i',
        '#^\s*DESCRIBE#i',
        '#^\s*EXPLAIN#i',
    ];

    /**
     * Sanitize query data before sending to Watchtower.
     */
    public function sanitize(array $data): array
    {
        // Sanitize bindings
        if (isset($data['bindings']) && is_array($data['bindings'])) {
            $data['bindings'] = $this->sanitizeBindings($data['bindings']);
        }

        // Sanitize SQL (remove any embedded sensitive data)
        if (isset($data['sql'])) {
            $data['sql'] = $this->sanitizeSql($data['sql']);
        }

        // Sanitize database name
        if (isset($data['database_name'])) {
            $data['database_name'] = $this->sanitizeDatabaseName($data['database_name']);
        }

        return $data;
    }

    /**
     * Sanitize bindings array, redacting sensitive values.
     */
    public function sanitizeBindings(array $bindings): array
    {
        $sanitized = [];

        foreach ($bindings as $key => $value) {
            $keyStr = is_string($key) ? strtolower($key) : '';
            $valueStr = is_string($value) ? strtolower($value) : '';

            // Check if key suggests sensitive data
            if ($this->isSensitiveKey($keyStr)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            // Check if value suggests sensitive data
            if ($this->isSensitiveValue($valueStr)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            // Truncate long string values
            if (is_string($value) && strlen($value) > 500) {
                $sanitized[$key] = substr($value, 0, 500) . '...[TRUNCATED]';
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * Check if a binding key suggests sensitive data.
     */
    protected function isSensitiveKey(string $key): bool
    {
        foreach ($this->sensitiveBindingNames as $sensitiveName) {
            if (str_contains($key, $sensitiveName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a binding value suggests sensitive data.
     */
    protected function isSensitiveValue(string $value): bool
    {
        // Check for common secret patterns
        if (preg_match('#^(eyj|pk_live|sk_live|sk_test|btok_|sq0csp|r_live_)#i', $value)) {
            return true; // Stripe keys
        }

        if (preg_match('#^AKIA[0-9A-Z]{16}$#', $value)) {
            return true; // AWS Access Key ID
        }

        if (preg_match('#^[a-zA-Z0-9/+=]{40}$#', $value) && strlen($value) === 40) {
            return true; // AWS Secret Key pattern
        }

        if (preg_match('#^(ghp_|github_pat_)#i', $value)) {
            return true; // GitHub tokens
        }

        if (preg_match('#^[a-f0-9]{32}$#i', $value)) {
            return true; // MD5 hashes could be passwords
        }

        if (preg_match('#^[a-f0-9]{64}$#i', $value)) {
            return true; // SHA-256 hashes, long tokens
        }

        return false;
    }

    /**
     * Sanitize SQL string to remove embedded sensitive data.
     */
    public function sanitizeSql(string $sql): string
    {
        // Remove password() function calls
        $sql = preg_replace("/PASSWORD\s*\(\s*[^)]+\s*\)/i", "PASSWORD('[REDACTED]')", $sql);

        // Remove identified values after = in SET clauses
        $sql = preg_replace("/SET\s+(\w+)\s*=\s*'[^']{1,200}'/i", "SET $1='[REDACTED]'", $sql);

        // Remove VALUES with long strings in INSERT
        $sql = preg_replace_callback(
            "/VALUES\s*\([^)]+\)/i",
            function ($matches) {
                $values = $matches[0];
                // Replace long quoted strings with placeholder
                $values = preg_replace("/'[^']{50,}'/", "'[LONG_VALUE]'", $values);
                return $values;
            },
            $sql
        );

        return $sql;
    }

    /**
     * Sanitize database name to avoid leaking sensitive info.
     */
    protected function sanitizeDatabaseName(string $name): string
    {
        // Remove any credentials-like patterns
        $name = preg_replace('/:[^@]+@/', ':[PASS]@', $name);

        return $name;
    }

    /**
     * Check if a query should be blocked/captured.
     */
    public function shouldBlock(string $sql): bool
    {
        foreach ($this->blockedPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a custom sensitive binding name.
     */
    public function addSensitiveBindingName(string $name): void
    {
        $this->sensitiveBindingNames[] = strtolower($name);
    }

    /**
     * Add a custom blocked SQL pattern.
     */
    public function addBlockedPattern(string $pattern): void
    {
        $this->blockedPatterns[] = $pattern;
    }
}
