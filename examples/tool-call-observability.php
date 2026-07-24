<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\AbstractLogger;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3McpTelemetryBridge\MetricsToolCallInterceptor;
use Rasuvaeff\Yii3McpTelemetryBridge\TracingToolCallInterceptor;
use Rasuvaeff\Yii3Metrics\InMemoryMeterProvider;
use Rasuvaeff\Yii3Metrics\MetricRegistry;
use Rasuvaeff\Yii3Telemetry\LogTracer;
use Yiisoft\Test\Support\Container\SimpleContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class BillingTool
{
    /**
     * Charges an order.
     */
    #[McpTool(name: 'billing.charge')]
    public function charge(string $orderId, string $password): string
    {
        return "charged {$orderId}";
    }
}

final class EchoLogger extends AbstractLogger
{
    #[\Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        echo sprintf(
            "[%s] %s %s\n",
            (string) $level,
            (string) $message,
            json_encode($context['attributes'] ?? [], JSON_UNESCAPED_SLASHES),
        );
    }
}

// In an application everything below is DI + one params line:
// 'rasuvaeff/yii3-mcp' => ['interceptors' => [
//     TracingToolCallInterceptor::class,
//     MetricsToolCallInterceptor::class,
// ]]
$tracer = new LogTracer(new EchoLogger());
$provider = new InMemoryMeterProvider();

$factory = new Psr17Factory();
$server = (new McpServerFactory(
    container: new SimpleContainer([BillingTool::class => new BillingTool()]),
    sessionStore: new InMemorySessionStore(),
    name: 'telemetry-example',
    version: '1.0.0',
))->create([BillingTool::class], [], [
    new TracingToolCallInterceptor($tracer),
    new MetricsToolCallInterceptor(new MetricRegistry($provider)),
]);

$tester = new McpTester($server, $factory, $factory, $factory);
$result = $tester->callTool('billing.charge', ['orderId' => '42', 'password' => 'p@ss']);
echo 'tool result: ' . $result['content'][0]['text'] . "\n\n";

foreach ($provider->snapshots() as $snapshot) {
    foreach ($snapshot->samples as $sample) {
        echo sprintf("%s{%s} = %s\n", $snapshot->name, $sample->labels->key(), $sample->value);
    }
}
