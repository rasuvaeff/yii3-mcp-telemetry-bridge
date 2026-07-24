<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3McpTelemetryBridge;

use Rasuvaeff\Yii3Mcp\Interceptor\CallOutcome;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Yii3Metrics\CounterInterface;
use Rasuvaeff\Yii3Metrics\HistogramInterface;
use Rasuvaeff\Yii3Metrics\LabelSet;
use Rasuvaeff\Yii3Metrics\MetricRegistry;
use Throwable;

/**
 * Counts every MCP tools/call into `mcp_tool_calls_total{tool,outcome}` and
 * samples its duration into `mcp_tool_call_duration_seconds{tool}`. Duration
 * is the wall time of the wrapped chain measured with hrtime(); outcomes are
 * distinguished by the counter's `outcome` label, not by a separate duration
 * series, to keep histogram cardinality low.
 *
 * The `outcome` label follows the core's shared {@see CallOutcome}
 * vocabulary: `success`, `rejected` (a client-visible refusal — rate limit,
 * RBAC, budget) or `error` (an unexpected failure) — so alert thresholds on
 * error rate are not polluted by expected rejections. A tool exception is
 * recorded and rethrown unchanged, so the MCP error envelope the agent sees
 * is byte-identical with and without this bridge.
 *
 * @api
 */
final readonly class MetricsToolCallInterceptor implements ToolCallInterceptorInterface
{
    private const array DEFAULT_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    private CounterInterface $calls;

    private HistogramInterface $duration;

    /**
     * @param list<float> $durationBuckets finite upper bounds in seconds; `+Inf` is appended implicitly
     */
    public function __construct(MetricRegistry $metrics, array $durationBuckets = self::DEFAULT_BUCKETS)
    {
        $this->calls = $metrics->counter(
            name: 'mcp_tool_calls_total',
            help: 'Total MCP tools/call requests',
            labelNames: ['tool', 'outcome'],
        );
        $this->duration = $metrics->histogram(
            name: 'mcp_tool_call_duration_seconds',
            help: 'MCP tools/call duration in seconds',
            labelNames: ['tool'],
            buckets: $durationBuckets,
        );
    }

    #[\Override]
    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        $startedAt = hrtime(true);

        try {
            /** @var mixed $result */
            $result = $next();
        } catch (Throwable $exception) {
            $this->record($context->toolName, $startedAt, CallOutcome::fromThrowable($exception));

            throw $exception;
        }

        $this->record($context->toolName, $startedAt, CallOutcome::Success);

        return $result;
    }

    private function record(string $tool, int $startedAt, CallOutcome $outcome): void
    {
        $this->calls->inc(labels: new LabelSet(['tool' => $tool, 'outcome' => $outcome->value]));
        $this->duration->observe((float) (hrtime(true) - $startedAt) / 1e9, new LabelSet(['tool' => $tool]));
    }
}
