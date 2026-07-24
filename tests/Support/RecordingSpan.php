<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3McpTelemetryBridge\Tests\Support;

use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Rasuvaeff\Yii3Telemetry\TraceContext;

final class RecordingSpan implements SpanInterface
{
    /** @var array<string, bool|int|float|string|array|null> */
    public array $attributes = [];

    public SpanStatusCode $statusCode = SpanStatusCode::Unset;

    public ?\Throwable $recordedException = null;

    public bool $ended = false;

    public function __construct(
        public string $name,
    ) {}

    #[\Override]
    public function setAttribute(string $key, bool|int|float|string|array|null $value): void
    {
        $this->attributes[$key] = $value;
    }

    #[\Override]
    public function updateName(string $name): void
    {
        $this->name = $name;
    }

    #[\Override]
    public function setStatus(SpanStatusCode $code, ?string $description = null): void
    {
        $this->statusCode = $code;
    }

    #[\Override]
    public function addEvent(string $name, array $attributes = []): void {}

    #[\Override]
    public function recordException(\Throwable $exception): void
    {
        $this->recordedException = $exception;
    }

    #[\Override]
    public function end(): void
    {
        $this->ended = true;
    }

    #[\Override]
    public function isRecording(): bool
    {
        return !$this->ended;
    }

    #[\Override]
    public function getTraceContext(): TraceContext
    {
        return new TraceContext(
            traceId: str_repeat('0', 32),
            spanId: str_repeat('0', 16),
        );
    }
}
