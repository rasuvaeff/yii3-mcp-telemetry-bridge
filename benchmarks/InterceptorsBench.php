<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3McpTelemetryBridge\Benchmarks;

use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3McpTelemetryBridge\MetricsToolCallInterceptor;
use Rasuvaeff\Yii3McpTelemetryBridge\TracingToolCallInterceptor;
use Rasuvaeff\Yii3Metrics\MetricRegistry;
use Rasuvaeff\Yii3Metrics\NullMeterProvider;
use Rasuvaeff\Yii3Telemetry\NullTracer;
use Testo\Bench;

/**
 * Measures the per-call overhead each interceptor adds on top of a bare tool
 * handler (Null tracing/metrics backends, so only the bridge's own work —
 * masking, attribute building, label sets — is on the clock).
 */
final class InterceptorsBench
{
    private TracingToolCallInterceptor $tracing;

    private MetricsToolCallInterceptor $metrics;

    private ToolCallContext $context;

    public function __construct()
    {
        $this->tracing = new TracingToolCallInterceptor(NullTracer::instance());
        $this->metrics = new MetricsToolCallInterceptor(new MetricRegistry(new NullMeterProvider()));
        $this->context = new ToolCallContext(
            toolName: 'order.status',
            arguments: ['orderId' => '42', 'password' => 'p@ss', 'locale' => 'en'],
        );
    }

    #[Bench(
        callables: [
            'bare handler' => [self::class, 'bareToolCall'],
        ],
        calls: 100_000,
        iterations: 5,
    )]
    public function tracingInterceptedCall(): mixed
    {
        return $this->tracing->intercept($this->context, static fn (): string => 'paid');
    }

    #[Bench(
        callables: [
            'bare handler' => [self::class, 'bareToolCall'],
        ],
        calls: 100_000,
        iterations: 5,
    )]
    public function metricsInterceptedCall(): mixed
    {
        return $this->metrics->intercept($this->context, static fn (): string => 'paid');
    }

    public static function bareToolCall(): string
    {
        return 'paid';
    }
}
