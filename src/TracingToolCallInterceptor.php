<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3McpTelemetryBridge;

use InvalidArgumentException;
use Rasuvaeff\Yii3Mcp\Interceptor\ArgumentMasker;
use Rasuvaeff\Yii3Mcp\Interceptor\CallOutcome;
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
 * Arguments are recorded as one scalar attribute per argument
 * (`mcp.tool.argument.<name>`), masked, stringified and truncated — the
 * OTel attribute model only accepts primitives and homogeneous lists of
 * primitives, so a single nested-array attribute would be dropped by the
 * OTel backend (same flattening the ecosystem's OtelMiddleware uses for
 * `http.request.param.<name>`).
 *
 * The span follows the frozen trace() contract of rasuvaeff/yii3-telemetry:
 * a tool exception is recorded on the span, the span status becomes Error
 * and the ORIGINAL exception is rethrown, so the MCP error envelope the
 * agent sees is unchanged. The `mcp.outcome` attribute follows the core's
 * shared {@see CallOutcome} vocabulary: `success`, `rejected` (a
 * client-visible refusal — rate limit, RBAC, budget) or `error` (an
 * unexpected failure).
 *
 * @api
 */
final readonly class TracingToolCallInterceptor implements ToolCallInterceptorInterface
{
    private const string SPAN_PREFIX = 'mcp.tool ';

    private const string ARGUMENT_ATTR_PREFIX = 'mcp.tool.argument.';

    private const int MAX_ARGUMENT_VALUE_LENGTH = 200;

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
                    $span->setAttribute('mcp.outcome', CallOutcome::fromThrowable($exception)->value);

                    throw $exception;
                }

                $span->setAttribute('mcp.outcome', CallOutcome::Success->value);

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
        ];

        /** @var mixed $value */
        foreach ($this->argumentMasker->mask($context->arguments) as $name => $value) {
            $attributes[self::ARGUMENT_ATTR_PREFIX . $name] = $this->stringifyArgument($value);
        }

        if ($context->clientId !== null) {
            $attributes['mcp.client.id'] = $context->clientId;
        }

        $info = $context->getClientInfo();

        /** @var mixed $name */
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

    private function stringifyArgument(mixed $value): string
    {
        if (is_string($value)) {
            $string = $value;
        } elseif (is_bool($value)) {
            $string = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $string = 'null';
        } elseif (is_scalar($value)) {
            $string = (string) $value;
        } else {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
            $string = $encoded === false ? '(unserializable)' : $encoded;
        }

        // Byte-based truncation — may split a multibyte character at the edge,
        // acceptable for debug attributes (no mb_* runtime dependency).
        return strlen($string) > self::MAX_ARGUMENT_VALUE_LENGTH
            ? substr($string, 0, self::MAX_ARGUMENT_VALUE_LENGTH) . '…'
            : $string;
    }
}
