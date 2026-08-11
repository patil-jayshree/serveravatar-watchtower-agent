<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Services;

class CommandSanitizer
{
    /**
     * Default list of sensitive option names that should be redacted.
     */
    protected const DEFAULT_SENSITIVE_OPTIONS = [
        'password',
        'passwd',
        'secret',
        'token',
        'api-key',
        'api_key',
        'apikey',
        'auth',
        'credential',
        'private',
        'access',
        'key',
        'pass',
        'pwd',
    ];

    /**
     * Get the list of sensitive option names.
     *
     * @return array<string>
     */
    protected function getSensitiveOptions(): array
    {
        $configOptions = config('watchtower.command_monitoring.sensitive_options', []);
        return array_unique(array_merge(self::DEFAULT_SENSITIVE_OPTIONS, $configOptions));
    }

    /**
     * Check if an option name is sensitive.
     */
    protected function isSensitiveOption(string $name): bool
    {
        $name = strtolower(ltrim($name, '-'));
        foreach ($this->getSensitiveOptions() as $sensitive) {
            if ($name === $sensitive || str_contains($name, $sensitive)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sanitize a list of command arguments.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function sanitizeArguments(array $arguments): array
    {
        $sanitized = [];
        foreach ($arguments as $key => $value) {
            // Redact values that look like secrets
            if ($this->looksLikeSecret((string) $key, $value)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $this->sanitizeValue($value);
            }
        }
        return $sanitized;
    }

    /**
     * Sanitize a list of command options.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function sanitizeOptions(array $options): array
    {
        $sanitized = [];
        foreach ($options as $key => $value) {
            $cleanKey = ltrim((string) $key, '-');

            if ($this->isSensitiveOption($cleanKey)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif ($this->looksLikeSecret($cleanKey, $value)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $this->sanitizeValue($value);
            }
        }
        return $sanitized;
    }

    /**
     * Check if a key/value pair looks like a secret.
     */
    protected function looksLikeSecret(string $key, mixed $value): bool
    {
        $key = strtolower($key);

        // Check key name
        foreach ($this->getSensitiveOptions() as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        // Check value patterns for obvious secrets
        if (is_string($value) && strlen($value) > 0) {
            // URLs with credentials
            if (preg_match('/^[a-z]+:\\/\\/[^:]+:[^@]+@/', $value)) {
                return true;
            }
            // Base64-looking secrets (long random strings)
            if (strlen($value) > 32 && preg_match('/^[A-Za-z0-9+\\/=]+$/', $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize a single value.
     */
    protected function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn($v) => $this->sanitizeValue($v), $value);
        }

        if (is_string($value)) {
            // Remove potential credential patterns
            $sanitized = preg_replace('/([a-z]+:\\/\\/)([^:@]+):([^@]+)@/', '$1$2:[REDACTED]@', $value);
            return $sanitized !== null ? $sanitized : $value;
        }

        return $value;
    }

    /**
     * Sanitize full command data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function sanitize(array $data): array
    {
        if (isset($data['options']) && is_array($data['options'])) {
            $data['options'] = $this->sanitizeOptions($data['options']);
        }

        if (isset($data['arguments']) && is_array($data['arguments'])) {
            $data['arguments'] = $this->sanitizeArguments($data['arguments']);
        }

        // Sanitize exception data
        if (isset($data['exception_message'])) {
            $data['exception_message'] = $this->sanitizeText($data['exception_message']);
        }

        if (isset($data['stack_trace'])) {
            $data['stack_trace'] = $this->sanitizeStackTrace($data['stack_trace']);
        }

        return $data;
    }

    /**
     * Sanitize text (exception messages, etc.).
     */
    public function sanitizeText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        // Remove potential credential patterns
        $text = preg_replace('/([a-z]+:\\/\\/)([^:@]+):([^@]+)@/', '$1$2:[REDACTED]@', $text);

        return $text !== null ? $text : '';
    }

    /**
     * Sanitize a stack trace.
     */
    public function sanitizeStackTrace(?string $trace): ?string
    {
        if ($trace === null) {
            return null;
        }

        // Remove credential patterns from stack trace lines
        $lines = explode("\n", $trace);
        $sanitized = [];
        foreach ($lines as $line) {
            // Remove URLs with credentials from stack trace
            $clean = preg_replace('/[a-z]+:\\/\\/[^:]+:[^@]+@[^\s\'"]+/', '[REDACTED]', $line);
            $sanitized[] = $clean !== null ? $clean : $line;
        }

        return implode("\n", $sanitized);
    }
}
