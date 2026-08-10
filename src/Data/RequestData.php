<?php

namespace ServerAvatar\Watchtower\Data;

class RequestData
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $method,
        public readonly string $path,
        public readonly ?string $url,
        public readonly ?string $routeName,
        public readonly ?string $controllerAction,
        public readonly int $statusCode,
        public readonly int $durationMs,
        public readonly ?int $memoryBytes,
        public readonly ?string $host,
        public readonly ?string $userAgent,
        public readonly ?string $ip,
        public readonly string $environment,
        public readonly ?string $contentType,
        public readonly string $requestedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'method' => $this->method,
            'path' => $this->path,
            'url' => $this->url,
            'route_name' => $this->routeName,
            'controller_action' => $this->controllerAction,
            'status_code' => $this->statusCode,
            'duration_ms' => $this->durationMs,
            'memory_bytes' => $this->memoryBytes,
            'host' => $this->host,
            'user_agent' => $this->userAgent,
            'ip' => $this->ip,
            'environment' => $this->environment,
            'content_type' => $this->contentType,
            'requested_at' => $this->requestedAt,
        ];
    }
}
