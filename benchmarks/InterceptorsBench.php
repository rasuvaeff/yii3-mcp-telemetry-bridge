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

    #[Bench]
    public function tracingInterceptSuccessfulCall(): void
    {
        $this->tracing->intercept($this->context, static fn (): string => 'paid');
    }

    #[Bench]
    public function metricsInterceptSuccessfulCall(): void
    {
        $this->metrics->intercept($this->context, static fn (): string => 'paid');
    }
}
