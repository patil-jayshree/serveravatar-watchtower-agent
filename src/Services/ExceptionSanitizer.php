<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Services;

class ExceptionSanitizer
{
    /**
     * Patterns that indicate sensitive information.
     */
    protected array $sensitivePatterns = [
        // Passwords
        '#password["\']?\s*[:=]\s*["\'][^"\']{1,100}["\']#i' => '[PASSWORD REDACTED]',
        '#"password"\s*:\s*"[^"]{1,100}"#i' => '"password": "[PASSWORD]"',

        // Secrets
        '#secret["\']?\s*[:=]\s*["\'][^"\']{1,100}["\']#i' => '[SECRET REDACTED]',

        // API keys
        '#api[_-]?key["\']?\s*[:=]\s*["\'][^"\']{1,100}["\']#i' => '[API_KEY REDACTED]',
        '#api[_-]?secret["\']?\s*[:=]\s*["\'][^"\']{1,100}["\']#i' => '[API_SECRET REDACTED]',

        // Authorization headers
        '#authorization["\']?\s*[:=]\s*["\'][^"\']{1,200}["\']#i' => '[AUTHORIZATION REDACTED]',
        '#bearer\s+[a-zA-Z0-9\-_.~+/]{10,}#i' => 'Bearer [TOKEN REDACTED]',

        // Database credentials in DSN strings
        '#mysql://[^:]+:[^@]+@#i' => 'mysql://[USER]:[PASS]@',

        // AWS keys
        '#AKIA[0-9A-Z]{16}#' => '[AWS_ACCESS_KEY_ID]',
        '#[a-zA-Z0-9/+=]{40}(?=.*aws)#i' => '[AWS_SECRET_KEY]',

        // Environment variables with sensitive names
        '#ENV\[\["\'](?:DB_PASSWORD|DB_USERNAME|APP_KEY|SECRET|TOKEN|PASSWORD|API_KEY)["\'\s]#i' => 'ENV[[SECRET]',

        // Cookie values
        '#cookie["\']?\s*[:=]\s*["\'][^"\']{1,200}["\']#i' => '[COOKIE REDACTED]',

        // Token in URL query
        '#[?&](?:token|api_key|key)=[a-zA-Z0-9\-_.~+/]{10,}#i' => '[QUERY_TOKEN REDACTED]',
    ];

    /**
     * Sensitive route patterns that might appear in stack traces.
     */
    protected array $sensitiveRoutePatterns = [
        '#/api/.*/token#i',
        '#/auth/.*/callback#i',
        '#/oauth/#i',
        '#/webhook/#i',
    ];

    /**
     * Sanitize exception data to remove sensitive information.
     */
    public function sanitize(array $data): array
    {
        // Always sanitize message first
        if (isset($data['message'])) {
            $data['message'] = $this->sanitizeText($data['message']);
        }

        // Sanitize stack trace
        if (isset($data['stack_trace'])) {
            $data['stack_trace'] = $this->sanitizeStackTrace($data['stack_trace']);
        }

        // Sanitize file path (remove absolute system paths)
        if (isset($data['file'])) {
            $data['file'] = $this->sanitizeFilePath($data['file']);
        }

        // Remove authorization-related headers
        if (isset($data['headers']) && is_array($data['headers'])) {
            $data['headers'] = $this->sanitizeHeaders($data['headers']);
        }

        return $data;
    }

    /**
     * Sanitize a text string.
     */
    public function sanitizeText(string $text): string
    {
        foreach ($this->sensitivePatterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * Sanitize a stack trace.
     */
    public function sanitizeStackTrace(string $stackTrace): string
    {
        // First, apply general text sanitization
        $sanitized = $this->sanitizeText($stackTrace);

        // Then sanitize file paths in the trace
        $sanitized = preg_replace_callback(
            '#\d+\s+[a-zA-Z\\_]+::[a-zA-Z\\_]+\([^\)]+\)\s+at\s+(.+):(\d+)#i',
            function ($matches) {
                return '#X ' . $matches[1] . '(' . $this->sanitizeFilePath($matches[2]) . ':' . $matches[3] . ')';
            },
            $sanitized
        );

        return $sanitized;
    }

    /**
     * Sanitize a file path to avoid leaking system information.
     */
    public function sanitizeFilePath(string $path): string
    {
        // Remove base path to show only relative path from project root
        $basePath = base_path();
        if (str_starts_with($path, $basePath)) {
            return 'app/' . ltrim(substr($path, strlen($basePath)), '/');
        }

        // For vendor paths, just show package name
        if (str_contains($path, 'vendor/')) {
            $parts = explode('vendor/', $path);
            if (count($parts) === 2) {
                return 'vendor/' . $parts[1];
            }
        }

        return $path;
    }

    /**
     * Sanitize HTTP headers.
     */
    public function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = [
            'authorization',
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ];

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitiveHeaders)) {
                $headers[$key] = '[REDACTED]';
            }
        }

        return $headers;
    }

    /**
     * Check if a string contains potential secrets.
     */
    public function containsSecrets(string $text): bool
    {
        foreach (array_keys($this->sensitivePatterns) as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a custom sensitive pattern.
     */
    public function addPattern(string $pattern, string $replacement): void
    {
        $this->sensitivePatterns[$pattern] = $replacement;
    }
}
