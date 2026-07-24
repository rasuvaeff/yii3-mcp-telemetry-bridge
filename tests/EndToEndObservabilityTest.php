<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3McpTelemetryBridge\Tests;

use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3McpTelemetryBridge\MetricsToolCallInterceptor;
use Rasuvaeff\Yii3McpTelemetryBridge\Tests\Support\OrderTool;
use Rasuvaeff\Yii3McpTelemetryBridge\Tests\Support\RecordingTracer;
use Rasuvaeff\Yii3McpTelemetryBridge\TracingToolCallInterceptor;
use Rasuvaeff\Yii3Metrics\InMemoryMeterProvider;
use Rasuvaeff\Yii3Metrics\MetricRegistry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

/**
 * Both interceptors wired into a REAL yii3-mcp server, exercised through the
 * MCP protocol via McpTester — the same path an agent takes.
 */
#[Test]
#[Covers(TracingToolCallInterceptor::class)]
#[Covers(MetricsToolCallInterceptor::class)]
final class EndToEndObservabilityTest
{
    private RecordingTracer $tracer;

    private InMemoryMeterProvider $provider;

    private McpTester $tester;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tracer = new RecordingTracer();
        $this->provider = new InMemoryMeterProvider();

        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([OrderTool::class => new OrderTool()]),
            sessionStore: new InMemorySessionStore(),
            name: 'telemetry-test',
            version: '1.0.0',
        ))->create([OrderTool::class], [], [
            new TracingToolCallInterceptor($this->tracer),
            new MetricsToolCallInterceptor(new MetricRegistry($this->provider)),
        ]);

        $this->tester = new McpTester($server, $factory, $factory, $factory);
    }

    public function toolCallProducesSpanAndMetricsWithMaskedArguments(): void
    {
        $result = $this->tester->callTool('order.status', ['orderId' => '42', 'password' => 'p@ss']);

        Assert::same($result['content'][0]['text'], 'paid:42');

        $span = $this->tracer->spans[0];

        Assert::same($span->name, 'mcp.tool order.status');
        Assert::same($span->attributes['mcp.outcome'], 'success');
        Assert::same($span->attributes['mcp.tool.argument.orderId'], '42');
        Assert::same($span->attributes['mcp.tool.argument.password'], '***');
        Assert::same($span->attributes['mcp.client.name'], 'mcp-tester');
        Assert::true($span->ended);

        $names = array_map(
            static fn($snapshot): string => $snapshot->name,
            $this->provider->snapshots(),
        );
        sort($names);

        Assert::same($names, ['mcp_tool_call_duration_seconds', 'mcp_tool_calls_total']);
    }

    public function everyCallAccumulatesMetrics(): void
    {
        $this->tester->callTool('order.status', ['orderId' => '1', 'password' => 'x']);
        $this->tester->callTool('order.status', ['orderId' => '2', 'password' => 'x']);

        foreach ($this->provider->snapshots() as $snapshot) {
            if ($snapshot->name === 'mcp_tool_calls_total') {
                Assert::same($snapshot->samples[0]->value, 2.0);

                return;
            }
        }

        Assert::true(false);
    }
}
