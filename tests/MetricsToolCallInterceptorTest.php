<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3McpTelemetryBridge\Tests;

use Mcp\Exception\ToolCallException;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3McpTelemetryBridge\MetricsToolCallInterceptor;
use Rasuvaeff\Yii3McpTelemetryBridge\Tests\Support\DeclaringMeterProvider;
use Rasuvaeff\Yii3Metrics\InMemoryMeterProvider;
use Rasuvaeff\Yii3Metrics\MetricRegistry;
use Rasuvaeff\Yii3Metrics\MetricSnapshot;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(MetricsToolCallInterceptor::class)]
final class MetricsToolCallInterceptorTest
{
    private InMemoryMeterProvider $provider;

    private MetricsToolCallInterceptor $interceptor;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->provider = new InMemoryMeterProvider();
        $this->interceptor = new MetricsToolCallInterceptor(new MetricRegistry($this->provider));
    }

    public function successfulCallCountsWithSuccessOutcome(): void
    {
        $result = $this->interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: []),
            static fn(): string => 'paid',
        );

        Assert::same($result, 'paid');

        $counter = $this->snapshot('mcp_tool_calls_total');

        Assert::same($counter->samples[0]->labels->labels, ['outcome' => 'success', 'tool' => 'order.status']);
        Assert::same($counter->samples[0]->value, 1.0);
    }

    public function failingCallCountsWithErrorOutcomeAndRethrows(): void
    {
        $boom = new RuntimeException('boom');

        try {
            $this->interceptor->intercept(
                new ToolCallContext(toolName: 'order.status', arguments: []),
                static fn(): string => throw $boom,
            );

            Assert::true(false);
        } catch (RuntimeException $caught) {
            Assert::same($caught, $boom);
        }

        $counter = $this->snapshot('mcp_tool_calls_total');

        Assert::same($counter->samples[0]->labels->labels, ['outcome' => 'error', 'tool' => 'order.status']);
        Assert::same($counter->samples[0]->value, 1.0);
    }

    public function clientVisibleRejectionCountsWithRejectedOutcome(): void
    {
        $rejection = new ToolCallException('rate limit exceeded');

        try {
            $this->interceptor->intercept(
                new ToolCallContext(toolName: 'order.status', arguments: []),
                static fn(): string => throw $rejection,
            );

            Assert::true(false);
        } catch (ToolCallException $caught) {
            Assert::same($caught, $rejection);
        }

        $counter = $this->snapshot('mcp_tool_calls_total');

        Assert::same($counter->samples[0]->labels->labels, ['outcome' => 'rejected', 'tool' => 'order.status']);
        Assert::same($counter->samples[0]->value, 1.0);
    }

    public function durationIsObservedPerToolOnBothPaths(): void
    {
        $this->interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: []),
            static fn(): string => 'paid',
        );

        try {
            $this->interceptor->intercept(
                new ToolCallContext(toolName: 'order.status', arguments: []),
                static fn(): string => throw new RuntimeException('boom'),
            );
        } catch (RuntimeException) {
        }

        $histogram = $this->snapshot('mcp_tool_call_duration_seconds');
        $sample = $histogram->samples[0];

        Assert::same($sample->labels->labels, ['tool' => 'order.status']);
        Assert::same($sample->value, 2.0);
        Assert::true($sample->sum >= 0.0);
        Assert::true($sample->sum < 60.0);
    }

    public function instrumentsDeclareTheirLabelNames(): void
    {
        $provider = new DeclaringMeterProvider();

        new MetricsToolCallInterceptor(new MetricRegistry($provider), durationBuckets: [1.0, 5.0]);

        Assert::same($provider->declarations['mcp_tool_calls_total']['labelNames'], ['tool', 'outcome']);
        Assert::same($provider->declarations['mcp_tool_call_duration_seconds']['labelNames'], ['tool']);
        Assert::same($provider->declarations['mcp_tool_call_duration_seconds']['buckets'], [1.0, 5.0]);
    }

    public function repeatedCallsAccumulateIntoTheSameCounter(): void
    {
        $call = fn(): mixed => $this->interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: []),
            static fn(): string => 'paid',
        );
        $call();
        $call();

        $counter = $this->snapshot('mcp_tool_calls_total');

        Assert::same($counter->samples[0]->value, 2.0);
    }

    public function customBucketsArePassedThrough(): void
    {
        $provider = new InMemoryMeterProvider();
        $interceptor = new MetricsToolCallInterceptor(
            new MetricRegistry($provider),
            durationBuckets: [1.0, 5.0],
        );

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: []),
            static fn(): string => 'paid',
        );

        $histogram = $this->snapshotOf($provider, 'mcp_tool_call_duration_seconds');

        Assert::same(array_keys($histogram->samples[0]->buckets), [1, 5, '+Inf']);
    }

    public function toolsAreDistinguishedByLabel(): void
    {
        foreach (['order.status', 'order.cancel'] as $tool) {
            $this->interceptor->intercept(
                new ToolCallContext(toolName: $tool, arguments: []),
                static fn(): string => 'ok',
            );
        }

        $counter = $this->snapshot('mcp_tool_calls_total');
        $tools = array_map(
            static fn($sample): string => $sample->labels->labels['tool'],
            $counter->samples,
        );
        sort($tools);

        Assert::same($tools, ['order.cancel', 'order.status']);
    }

    private function snapshot(string $name): MetricSnapshot
    {
        return $this->snapshotOf($this->provider, $name);
    }

    private function snapshotOf(InMemoryMeterProvider $provider, string $name): MetricSnapshot
    {
        foreach ($provider->snapshots() as $snapshot) {
            if ($snapshot->name === $name) {
                return $snapshot;
            }
        }

        throw new RuntimeException(sprintf('No snapshot for metric "%s"', $name));
    }
}
