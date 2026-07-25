<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3McpTelemetryBridge\Tests\Integration;

use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use OpenTelemetry\Context\ZendObserverFiber;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3McpTelemetryBridge\Tests\Support\OrderTool;
use Rasuvaeff\Yii3McpTelemetryBridge\TracingToolCallInterceptor;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[CoversNothing]
final class OtelFiberIntegrationTest
{
    private bool $enabled = false;

    /** @var \ArrayObject<int, ImmutableSpan> */
    private \ArrayObject $spans;

    private TracerInterface $tracer;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->enabled = getenv('MCP_OTEL_FIBER_TEST') === '1';

        if (!$this->enabled) {
            return;
        }

        $_SERVER['OTEL_PHP_FIBERS_ENABLED'] = 'true';

        if (!ZendObserverFiber::init()) {
            throw new \RuntimeException('OTel automatic Fiber propagation requires NTS PHP with ext-ffi');
        }

        $this->spans = new \ArrayObject();
        $exporter = new InMemoryExporter($this->spans);
        $provider = (new OtelTracerProviderFactory(serviceName: 'mcp-fiber-test', batch: false))->create($exporter);
        $this->tracer = (new OtelTracerProvider($provider))->getTracer();
    }

    public function realToolCallInSdkFiberKeepsHttpParentWithoutWarnings(): void
    {
        if (!$this->enabled) {
            return;
        }

        $psr17 = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([OrderTool::class => new OrderTool()]),
            sessionStore: new InMemorySessionStore(),
        ))->create(
            [OrderTool::class],
            interceptors: [new TracingToolCallInterceptor($this->tracer)],
        );
        $tester = new McpTester($server, $psr17, $psr17, $psr17);
        set_error_handler(
            static fn(int $severity, string $message): never => throw new \ErrorException($message, severity: $severity),
        );

        try {
            $result = $this->tracer->trace(
                'HTTP POST /rest/mcp',
                static fn(): array => $tester->callTool('order.status', ['orderId' => '42', 'password' => 'secret']),
            );
        } finally {
            restore_error_handler();
        }

        Assert::same($result['content'][0]['text'], 'paid:42');
        $parent = $this->span('HTTP POST /rest/mcp');
        $tool = $this->span('mcp.tool order.status');
        Assert::same($tool->getContext()->getTraceId(), $parent->getContext()->getTraceId());
        Assert::same($tool->getParentContext()->getSpanId(), $parent->getContext()->getSpanId());
    }

    public function alternatingFibersKeepTheirOwnParents(): void
    {
        if (!$this->enabled) {
            return;
        }

        $fiberA = $this->fiber('a');
        $fiberB = $this->fiber('b');

        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();
        $fiberB->resume();

        foreach (['a', 'b'] as $id) {
            $parent = $this->span('parent.' . $id);
            $child = $this->span('child.' . $id);
            Assert::same($child->getContext()->getTraceId(), $parent->getContext()->getTraceId());
            Assert::same($child->getParentContext()->getSpanId(), $parent->getContext()->getSpanId());
        }

        Assert::false($this->span('child.a')->getContext()->getTraceId() === $this->span('child.b')->getContext()->getTraceId());
    }

    private function fiber(string $id): \Fiber
    {
        return new \Fiber(fn(): mixed => $this->tracer->trace(
            'parent.' . $id,
            function () use ($id): void {
                \Fiber::suspend();
                $this->tracer->trace('child.' . $id, static fn(): null => null);
            },
        ));
    }

    private function span(string $name): ImmutableSpan
    {
        foreach ($this->spans as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        throw new \LogicException(sprintf('Span "%s" was not exported', $name));
    }
}
