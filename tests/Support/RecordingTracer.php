<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3McpTelemetryBridge\Tests\Support;

use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Rasuvaeff\Yii3Telemetry\TraceContext;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Rasuvaeff\Yii3Telemetry\TracerInterface;

/**
 * Minimal TracerInterface double honoring the frozen trace() contract:
 * callback throws -> recordException + Error status + span ended + rethrow.
 */
final class RecordingTracer implements TracerInterface
{
    /** @var list<RecordingSpan> */
    public array $spans = [];

    #[\Override]
    public function trace(
        string $name,
        callable $callback,
        array $attributes = [],
        bool $scoped = true,
        TraceKind $traceKind = TraceKind::Internal,
        ?int $startNanos = null,
    ): mixed {
        $span = new RecordingSpan($name);
        $span->attributes = $attributes;
        $this->spans[] = $span;

        try {
            /** @var mixed $result */
            $result = $callback($span);
        } catch (\Throwable $exception) {
            $span->recordException($exception);
            $span->setStatus(SpanStatusCode::Error, $exception->getMessage());
            $span->end();

            throw $exception;
        }

        $span->end();

        return $result;
    }

    #[\Override]
    public function startSpan(
        string $name,
        array $attributes = [],
        TraceKind $traceKind = TraceKind::Internal,
        ?int $startNanos = null,
    ): SpanInterface {
        $span = new RecordingSpan($name);
        $span->attributes = $attributes;
        $this->spans[] = $span;

        return $span;
    }

    #[\Override]
    public function currentSpan(): SpanInterface
    {
        return new RecordingSpan('current');
    }

    #[\Override]
    public function getContext(): TraceContext
    {
        return new TraceContext(
            traceId: str_repeat('0', 32),
            spanId: str_repeat('0', 16),
        );
    }
}
