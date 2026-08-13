<?php

declare(strict_types=1);

namespace ServerAvatar\Watchtower\Services;

use Throwable;

class ExceptionSanitizer
{
    /**
     * Patterns for sensitive data that should be redacted from stack traces.
     */
    protected array $sensitivePatterns = [
        // Key-value patterns (quoted values)
        '/(["\']?(?:password|secret|token|api[_-]?key|auth|authorization|bearer|credential|credit[_-]?card|cvv|ssn)["\']?\s*[:=]\s*)["\'][^"\']{1,100}["\']/i',
        // Bearer tokens
        '/(bearer\s+)[a-zA-Z0-9\-_.~+\/=]{10,}/i',
        // Basic auth
        '/(basic\s+)[a-zA-Z0-9+\/=]{10,}/i',
        // URLs with credentials
        '/(https?:\/\/)[^:\/]+:[^@\/\n]+@[^\/\n]+/i',
        // Environment variable access patterns that might expose secrets
        '/(env\(["\'][^"\']{1,50}["\']\))/i',
    ];

    /**
     * Keys that indicate sensitive function arguments.
     */
    protected array $sensitiveArgNames = [
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'bearer',
        'credential',
        'access_token',
        'refresh_token',
        'client_secret',
        'private_key',
    ];

    /**
     * Sanitize a stack trace array.
     *
     * @param  array<int, array{file?: string, line?: int, class?: string, type?: string, function?: string, args?: array}>  $trace
     * @return array<int, array{file?: string, line?: int, class?: string, type?: string, function?: string, args?: array}>
     */
    public function sanitizeTrace(array $trace): array
    {
        return array_map(function (array $frame) {
            // Sanitize file path (remove absolute paths, keep relative)
            if (isset($frame['file'])) {
                $frame['file'] = $this->sanitizeFilePath($frame['file']);
            }

            // Sanitize arguments
            if (isset($frame['args']) && is_array($frame['args'])) {
                $frame['args'] = $this->sanitizeArgs($frame['args']);
            }

            return $frame;
        }, $trace);
    }

    /**
     * Sanitize a single line of stack trace text.
     */
    public function sanitizeStackTraceText(string $trace): string
    {
        $sanitized = $trace;

        foreach ($this->sensitivePatterns as $pattern) {
            $sanitized = preg_replace($pattern, '$1[REDACTED]', $sanitized);
        }

        return $sanitized;
    }

    /**
     * Parse a stack trace from an exception into a structured array.
     *
     * @return array<int, array{file: string, line: int, class: string, type: string, function: string}>
     */
    public function parseTrace(Throwable $exception): array
    {
        $trace = $exception->getTrace();

        // Filter out vendor frames (laravel, php internal)
        $trace = array_filter($trace, function (array $frame) {
            // Skip frames from vendor directories
            if (isset($frame['file']) && $this->isVendorPath($frame['file'])) {
                return false;
            }
            return true;
        });

        // Add the exception location as the first frame
        array_unshift($trace, [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'class' => get_class($exception),
            'type' => '→',
            'function' => '__construct',
        ]);

        return array_values($trace);
    }

    /**
     * Extract the class and function from the exception's immediate context.
     *
     * @return array{class: string, function: string}
     */
    public function extractExceptionContext(Throwable $exception): array
    {
        $trace = $exception->getTrace();

        // Look for the first frame that's not the exception itself
        foreach ($trace as $frame) {
            if (! isset($frame['file']) || $frame['file'] !== $exception->getFile()) {
                return [
                    'class' => $frame['class'] ?? '',
                    'function' => $frame['function'] ?? '',
                ];
            }
        }

        // If all frames are from the same file, extract from the trace
        if (! empty($trace)) {
            $frame = $trace[0];
            return [
                'class' => $frame['class'] ?? '',
                'function' => $frame['function'] ?? '',
            ];
        }

        return [
            'class' => get_class($exception),
            'function' => '__throw',
        ];
    }

    /**
     * Check if a file path is from a vendor directory.
     */
    protected function isVendorPath(string $path): bool
    {
        return str_contains($path, 'vendor/')
            || str_contains($path, 'vendor\\')
            || str_contains($path, '/vendor/')
            || str_contains($path, '\\vendor\\');
    }

    /**
     * Sanitize a file path to show relative path from project root.
     */
    protected function sanitizeFilePath(string $path): string
    {
        // Try to extract relative path after 'app/' or 'src/'
        if (preg_match('/(?:app|src)\/(.+)$/', $path, $matches)) {
            return 'app/' . $matches[1];
        }

        // For other paths, extract last 3 segments
        $parts = explode('/', str_replace('\\', '/', $path));
        if (count($parts) > 3) {
            return implode('/', array_slice($parts, -3));
        }

        return $path;
    }

    /**
     * Sanitize function arguments.
     *
     * @param  array<int, mixed>  $args
     * @return array<int, mixed>
     */
    protected function sanitizeArgs(array $args): array
    {
        return array_map(function ($arg) {
            if (is_array($arg)) {
                return '[Array]';
            }

            if (is_object($arg)) {
                return get_class($arg) . '::class';
            }

            if (is_string($arg)) {
                // Check if it looks like sensitive data
                if ($this->looksLikeSensitiveData($arg)) {
                    return '[REDACTED]';
                }
                // Truncate long strings
                if (strlen($arg) > 100) {
                    return substr($arg, 0, 100) . '...';
                }
                return $arg;
            }

            if (is_bool($arg)) {
                return $arg ? 'true' : 'false';
            }

            if (is_null($arg)) {
                return 'null';
            }

            return var_export($arg, true);
        }, $args);
    }

    /**
     * Check if a string value looks like sensitive data.
     */
    protected function looksLikeSensitiveData(string $value): bool
    {
        // Check for token-like patterns
        if (preg_match('/^(?:bearer|basic|token|eyJ[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+)$/i', trim($value))) {
            return true;
        }

        // Check for long random strings (likely API keys, tokens)
        if (strlen($value) > 32 && preg_match('/^[a-zA-Z0-9\-_=\/]+$/', $value)) {
            return true;
        }

        return false;
    }

    /**
     * Sanitize an exception data array for API submission.
     * This is the main entry point used by ExceptionTelemetry.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitize(array $data): array
    {
        // Sanitize stack_trace if it's a string
        if (isset($data['stack_trace']) && is_string($data['stack_trace'])) {
            $data['stack_trace'] = $this->sanitizeStackTraceText($data['stack_trace']);
        }

        // Sanitize message
        if (isset($data['message'])) {
            $data['message'] = $this->sanitizeStackTraceText($data['message']);
        }

        // Sanitize file path
        if (isset($data['file'])) {
            $data['file'] = $this->sanitizeFilePath($data['file']);
        }

        return $data;
    }

    /**
     * Extract a context window of source code around the exception line.
     * Returns a JSON-encoded array of {line_number, line_content, is_failing_line}.
     *
     * @return array{file: string|null, context: string|null}
     */
    public function extractSourceContext(string $file, int $line, int $window = 7): array
    {
        $result = [
            'file' => null,
            'context' => null,
        ];

        // Validate file exists and is readable
        if (empty($file) || ! file_exists($file) || ! is_readable($file)) {
            return $result;
        }

        // Sanitize the file path for display (relative path)
        $result['file'] = $this->sanitizeFilePath($file);

        // Read file content (only what we need)
        $fileHandle = fopen($file, 'r');
        if ($fileHandle === false) {
            return $result;
        }

        // Calculate line range: $window lines before, $window lines after
        $startLine = max(1, $line - $window);
        $endLine = $line + $window;

        $contextLines = [];
        $currentLine = 1;

        while (($buffer = fgets($fileHandle)) !== false) {
            if ($currentLine >= $startLine && $currentLine <= $endLine) {
                $contextLines[] = [
                    'line' => $currentLine,
                    'content' => $this->sanitizeSourceLine($buffer),
                    'is_failing' => ($currentLine === $line),
                ];
            }

            if ($currentLine > $endLine) {
                break;
            }

            $currentLine++;
        }

        fclose($fileHandle);

        if (! empty($contextLines)) {
            $result['context'] = json_encode($contextLines);
        }

        return $result;
    }

    /**
     * Sanitize a single source code line.
     */
    protected function sanitizeSourceLine(string $line): string
    {
        // Remove trailing newline/whitespace
        $line = rtrim($line, "\r\n");

        // Truncate very long lines (e.g., minified JS, long strings)
        if (strlen($line) > 500) {
            $line = substr($line, 0, 500) . '...';
        }

        // Redact patterns that look like credentials, tokens, keys
        $line = preg_replace(
            '/(["\']?(?:password|secret|token|api[_-]?key|auth|authorization|bearer|credential)["\']?\s*[:=]\s*)[\"\'][^\"\']{1,100}[\"\']/i',
            '$1[REDACTED]',
            $line
        ) ?? $line;

        // Redact long base64-like strings that might be secrets
        $line = preg_replace(
            '/[a-zA-Z0-9\-_]{50,}(?==|$)/',
            '[REDACTED]',
            $line
        ) ?? $line;

        return $line;
    }
}
