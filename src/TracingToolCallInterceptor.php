<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3McpTelemetryBridge;

use InvalidArgumentException;
use Rasuvaeff\Yii3Mcp\Interceptor\ArgumentMasker;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Throwable;

/**
 * Wraps every MCP tools/call in a `mcp.tool <name>` span: the tool name,
 * masked arguments, the client identity (endpoint secret id and initialize
 * handshake info), the session id and — when the session budget is
 * configured — how many calls remain.
 *
 * The span follows the frozen trace() contract of rasuvaeff/yii3-telemetry:
 * a tool exception is recorded on the span, the span status becomes Error
 * and the ORIGINAL exception is rethrown, so the MCP error envelope the
 * agent sees is unchanged. The `mcp.outcome` attribute mirrors the result
 * (`success`/`error`) for backends that filter by attribute rather than by
 * span status.
 *
 * @api
 */
final readonly class TracingToolCallInterceptor implements ToolCallInterceptorInterface
{
    private const string SPAN_PREFIX = 'mcp.tool ';

    /**
     * Mirrors the private per-session counter key of yii3-mcp's
     * SessionBudgetInterceptor; a key drift only drops the budget
     * attributes, it never breaks the call.
     */
    private const string BUDGET_COUNTER_KEY = 'rasuvaeff.yii3-mcp.tool-calls';

    /**
     * @param ?int $sessionBudget the `session.budget` value configured for yii3-mcp;
     *        enables the `mcp.session.budget_remaining` attribute
     */
    public function __construct(
        private TracerInterface $tracer,
        private ArgumentMasker $argumentMasker = new ArgumentMasker(),
        private ?int $sessionBudget = null,
    ) {
        if ($sessionBudget !== null && $sessionBudget < 1) {
            throw new InvalidArgumentException(sprintf('Session budget must be at least 1, %d given', $sessionBudget));
        }
    }

    #[\Override]
    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        return $this->tracer->trace(
            self::SPAN_PREFIX . $context->toolName,
            static function (SpanInterface $span) use ($next): mixed {
                try {
                    /** @var mixed $result */
                    $result = $next();
                } catch (Throwable $exception) {
                    $span->setAttribute('mcp.outcome', 'error');

                    throw $exception;
                }

                $span->setAttribute('mcp.outcome', 'success');

                return $result;
            },
            attributes: $this->attributes($context),
        );
    }

    /**
     * @return array<string, bool|int|float|string|array|null>
     */
    private function attributes(ToolCallContext $context): array
    {
        $attributes = [
            'mcp.tool' => $context->toolName,
            'mcp.tool.arguments' => $this->argumentMasker->mask($context->arguments),
        ];

        if ($context->clientId !== null) {
            $attributes['mcp.client.id'] = $context->clientId;
        }

        $info = $context->getClientInfo();
        $name = $info['name'] ?? null;

        if (is_string($name) && $name !== '') {
            $attributes['mcp.client.name'] = $name;
        }

        /** @var mixed $version */
        $version = $info['version'] ?? null;

        if (is_string($version) && $version !== '') {
            $attributes['mcp.client.version'] = $version;
        }

        $session = $context->session;

        if ($session === null) {
            return $attributes;
        }

        $attributes['mcp.session.id'] = $session->getId()->toRfc4122();

        /** @var mixed $used */
        $used = $session->get(self::BUDGET_COUNTER_KEY);

        if (is_int($used)) {
            $attributes['mcp.session.calls_used'] = $used;

            if ($this->sessionBudget !== null) {
                $attributes['mcp.session.budget_remaining'] = max(0, $this->sessionBudget - $used);
            }
        }

        return $attributes;
    }
}
