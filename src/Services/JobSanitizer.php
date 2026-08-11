<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Services;

class JobSanitizer
{
    /**
     * Sensitive keys that should never be captured from job data.
     */
    protected array $blockedKeys = [
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
        'session',
        'cookie',
        'authorization',
        'credential',
        'private_key',
        'aws_access_key_id',
        'aws_secret_access_key',
        'stripe_key',
        'github_token',
        'gitlab_token',
        'slack_token',
        'mailgun_key',
        'nexmo_key',
        'twilio_key',
        'db_password',
        'database_password',
        'mysql_password',
        'postgres_password',
        'redis_password',
    ];

    /**
     * Patterns that indicate sensitive data.
     */
    protected array $sensitivePatterns = [
        '#eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+#' => '[JWT_TOKEN]',
        '#sk_live_[A-Za-z0-9]{24,}#' => '[STRIPE_KEY]',
        '#sk_test_[A-Za-z0-9]{24,}#' => '[STRIPE_TEST_KEY]',
        '#pk_live_[A-Za-z0-9]{24,}#' => '[STRIPE_PUB_KEY]',
        '#pk_test_[A-Za-z0-9]{24,}#' => '[STRIPE_TEST_PUB_KEY]',
        '#AKIA[0-9A-Z]{16}#' => '[AWS_ACCESS_KEY]',
        '#-[A-Za-z0-9/+]{40}#' => '[AWS_SECRET_KEY]',
        '#ghp_[A-Za-z0-9]{36,}#' => '[GITHUB_TOKEN]',
        '#github_pat_[A-Za-z0-9_]{22,82}#' => '[GITHUB_PAT]',
        '#xox[baprs]-[A-Za-z0-9-]{10,}#' => '[SLACK_TOKEN]',
    ];

    /**
     * Sanitize job data before sending to Watchtower.
     */
    public function sanitize(array $data): array
    {
        // Sanitize exception message
        if (isset($data['exception_message'])) {
            $data['exception_message'] = $this->sanitizeText($data['exception_message']);
        }

        // Sanitize stack trace
        if (isset($data['stack_trace'])) {
            $data['stack_trace'] = $this->sanitizeStackTrace($data['stack_trace']);
        }

        // Remove blocked keys from any data
        $data = $this->removeBlockedKeys($data);

        // Sanitize text fields
        foreach (['job_name', 'queue', 'connection', 'exception_class', 'exception_file'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->sanitizeText($data[$field]);
            }
        }

        return $data;
    }

    /**
     * Sanitize a text string.
     */
    public function sanitizeText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        foreach ($this->sensitivePatterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        // Remove potential password= values
        $text = preg_replace("/(password\s*[=:]\s*)['\"][^'\"]{1,100}['\"]/i", '$1[REDACTED]', $text);

        // Remove potential secret= values
        $text = preg_replace("/(secret\s*[=:]\s*)['\"][^'\"]{1,100}['\"]/i", '$1[REDACTED]', $text);

        // Remove potential token= values
        $text = preg_replace("/(token\s*[=:]\s*)['\"][A-Za-z0-9_-]{10,100}['\"]/i", '$1[REDACTED]', $text);

        return $text;
    }

    /**
     * Sanitize a stack trace.
     */
    public function sanitizeStackTrace(?string $stackTrace): ?string
    {
        if ($stackTrace === null) {
            return null;
        }

        // Remove arguments from stack frames (file paths are fine)
        $lines = explode("\n", $stackTrace);
        $sanitized = [];

        foreach ($lines as $line) {
            // Remove argument values from method calls
            // Example: #1 /path/to/file.php(123): method('secret123')
            $line = preg_replace("/(\\(\\d+\\):\\s+)[^\\(]+\\(([^)]*)\\)/", '$1[...]', $line);

            // Remove argument values from function calls
            // Example: #1 method('secret123', 'password')
            $line = preg_replace("/\\([^)]*['\"][^'\"]{10,}['\"][^)]*\\)/", '([...])', $line);

            // Sanitize any remaining sensitive patterns
            foreach ($this->sensitivePatterns as $pattern => $replacement) {
                $line = preg_replace($pattern, $replacement, $line);
            }

            $sanitized[] = $line;
        }

        return implode("\n", $sanitized);
    }

    /**
     * Remove blocked keys from data array.
     */
    protected function removeBlockedKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            $keyLower = strtolower((string) $key);

            // Check if this key is blocked
            foreach ($this->blockedKeys as $blocked) {
                if (str_contains($keyLower, $blocked)) {
                    unset($data[$key]);
                    continue 2;
                }
            }

            // Recursively sanitize nested arrays
            if (is_array($value)) {
                $data[$key] = $this->removeBlockedKeys($value);
            }
        }

        return $data;
    }

    /**
     * Add a custom blocked key.
     */
    public function addBlockedKey(string $key): void
    {
        $this->blockedKeys[] = strtolower($key);
    }

    /**
     * Check if a job should be ignored.
     */
    public function shouldIgnore(string $jobName, ?string $queue = null): bool
    {
        $ignoredJobs = config('watchtower.queue_monitoring.ignored_jobs', []);
        $ignoredQueues = config('watchtower.queue_monitoring.ignored_queues', []);

        foreach ($ignoredJobs as $pattern) {
            if (fnmatch($pattern, $jobName)) {
                return true;
            }
        }

        if ($queue !== null) {
            foreach ($ignoredQueues as $pattern) {
                if (fnmatch($pattern, $queue)) {
                    return true;
                }
            }
        }

        return false;
    }
}
