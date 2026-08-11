<?php

namespace ServerAvatar\Watchtower\Log;

use DateTimeImmutable;
use JsonSerializable;

class LogData implements JsonSerializable
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $level,
        public readonly string $message,
        public readonly ?array $context,
        public readonly ?string $channel,
        public readonly ?string $requestId,
        public readonly ?string $exceptionClass,
        public readonly ?string $exceptionMessage,
        public readonly ?string $file,
        public readonly ?int $line,
        public readonly string $environment,
        public readonly string $host,
        public readonly string $agentVersion,
        public readonly DateTimeImmutable $timestamp,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            uuid: $data['uuid'],
            level: $data['level'],
            message: $data['message'],
            context: $data['context'] ?? null,
            channel: $data['channel'] ?? null,
            requestId: $data['request_id'] ?? null,
            exceptionClass: $data['exception_class'] ?? null,
            exceptionMessage: $data['exception_message'] ?? null,
            file: $data['file'] ?? null,
            line: $data['line'] ?? null,
            environment: $data['environment'],
            host: $data['host'],
            agentVersion: $data['agent_version'],
            timestamp: new DateTimeImmutable($data['timestamp']),
        );
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'level' => $this->level,
            'message' => $this->message,
            'context' => $this->context,
            'channel' => $this->channel,
            'request_id' => $this->requestId,
            'exception_class' => $this->exceptionClass,
            'exception_message' => $this->exceptionMessage,
            'file' => $this->file,
            'line' => $this->line,
            'environment' => $this->environment,
            'host' => $this->host,
            'agent_version' => $this->agentVersion,
            'timestamp' => $this->timestamp->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
