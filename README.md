# rasuvaeff/yii3-mcp-telemetry-bridge

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-mcp-telemetry-bridge?label=stable&sort_semver=1)](https://packagist.org/packages/rasuvaeff/yii3-mcp-telemetry-bridge)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-mcp-telemetry-bridge)](https://packagist.org/packages/rasuvaeff/yii3-mcp-telemetry-bridge)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-mcp-telemetry-bridge/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-mcp-telemetry-bridge/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-mcp-telemetry-bridge/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-mcp-telemetry-bridge/actions)
[![Psalm level](https://img.shields.io/badge/psalm-level%201-141F48?logo=psalm&logoColor=white)](https://github.com/rasuvaeff/yii3-mcp-telemetry-bridge/blob/master/psalm.xml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-mcp-telemetry-bridge/php)](https://packagist.org/packages/rasuvaeff/yii3-mcp-telemetry-bridge)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-mcp-telemetry-bridge)](LICENSE.md)
[Русская версия](README.ru.md)

Observability for MCP servers: a trace span and RED-style metrics for every
[rasuvaeff/yii3-mcp](https://github.com/rasuvaeff/yii3-mcp) `tools/call`, via
[rasuvaeff/yii3-telemetry](https://github.com/rasuvaeff/yii3-telemetry) and
[rasuvaeff/yii3-metrics](https://github.com/rasuvaeff/yii3-metrics).

> **Using an AI coding assistant?** [llms.txt](llms.txt) contains a compact
> API reference you can share with the model. Contributors: see [AGENTS.md](AGENTS.md).

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.3 – 8.5 |
| `rasuvaeff/yii3-mcp` | `^1.4` |
| `rasuvaeff/yii3-telemetry` | `^1.0` |
| `rasuvaeff/yii3-metrics` | `^1.0` |

Both observability cores are vendor-neutral; wire a backend
(`yii3-telemetry-otel`, `yii3-metrics-prometheus`) or the `Null*` providers.

## Installation

```bash
composer require rasuvaeff/yii3-mcp-telemetry-bridge
```

## Usage

One params line — the interceptors are resolved through the DI container
(the telemetry/metrics facades must be wired, which their configs do):

```php
// config/params.php
use Rasuvaeff\Yii3McpTelemetryBridge\MetricsToolCallInterceptor;
use Rasuvaeff\Yii3McpTelemetryBridge\TracingToolCallInterceptor;

'rasuvaeff/yii3-mcp' => [
    'interceptors' => [
        TracingToolCallInterceptor::class,
        MetricsToolCallInterceptor::class,
    ],
],
```

Use either interceptor alone if you only run one of the two stacks.

### Tracing: `TracingToolCallInterceptor`

Every `tools/call` — attribute tools, OpenAPI-bridged operations,
configurator-registered handlers — becomes one span:

| Span field | Value |
|---|---|
| name | `mcp.tool <tool name>` (e.g. `mcp.tool order.status`) |
| `mcp.tool` | tool name |
| `mcp.tool.arguments` | arguments with sensitive keys masked (`***`) |
| `mcp.outcome` | `success` / `error` |
| `mcp.client.id` | identity from the endpoint secret (multi-secret setups); absent on stdio |
| `mcp.client.name` / `mcp.client.version` | client from the initialize handshake |
| `mcp.session.id` | MCP session UUID |
| `mcp.session.calls_used` | tools/call count in this session (when the session budget is on) |
| `mcp.session.budget_remaining` | remaining budget (when `sessionBudget` is configured, see below) |
| status | `Error` + recorded exception on failure; `Unset` on success |

A tool exception is recorded on the span and **rethrown** — the MCP error
envelope the agent sees is unchanged.

Manual wiring:

```php
$interceptor = new TracingToolCallInterceptor(
    tracer: $tracer,                          // Rasuvaeff\Yii3Telemetry\TracerInterface
    argumentMasker: new ArgumentMasker(),     // default key list: password, secret, token, api_key, credit_card
    sessionBudget: 50,                        // optional: mirror your `session.budget` param
);
```

`sessionBudget` only feeds the `mcp.session.budget_remaining` attribute — the
budget itself is enforced by yii3-mcp's `SessionBudgetInterceptor`.

### Metrics: `MetricsToolCallInterceptor`

| Metric | Type | Labels |
|---|---|---|
| `mcp_tool_calls_total` | counter | `tool`, `outcome` (`success`/`error`) |
| `mcp_tool_call_duration_seconds` | histogram | `tool` |

Duration is the wall time of the wrapped chain (`hrtime()`), observed on both
success and failure. The histogram carries no `outcome` label to keep
cardinality low — errors are counted in the counter.

Manual wiring:

```php
$interceptor = new MetricsToolCallInterceptor(
    metrics: $registry,                        // Rasuvaeff\Yii3Metrics\MetricRegistry
    durationBuckets: [0.05, 0.1, 0.5, 1.0],    // optional; Prometheus-style defaults otherwise
);
```

### Interceptor order

Put tracing outermost so the span covers the whole chain (rate limits,
RBAC, audit) and other interceptors' failures land on the span:

```php
'interceptors' => [
    TracingToolCallInterceptor::class,   // outermost
    MetricsToolCallInterceptor::class,
    // ... RBAC / audit / rate-limit interceptors
],
```

### stdio mode (`mcp:serve`)

The stdio worker is long-running: make sure your tracing backend exports
spans per call rather than only on process shutdown (for
`yii3-telemetry-otel`, use a batch processor with a scheduled delay or a
simple processor), otherwise spans buffer until the agent disconnects.

## Security

- Arguments land on the span **masked by field name** (case-insensitive, at
  every nesting level) via yii3-mcp's `ArgumentMasker` — the same semantics
  as the audit bridge, so the two never disagree. Extend the key list via
  the constructor if your tools take other sensitive fields.
- Span attributes leave the process through your tracing backend — treat
  trace storage with the same care as the data the tools access, or mask
  more aggressively.
- The interceptors add no failure mode to tool execution: tool exceptions
  are rethrown unchanged.

## Examples

See [examples/](examples/) — runs offline.

| Script | Shows | Needs server? |
|--------|-------|:-------------:|
| [`tool-call-observability.php`](examples/tool-call-observability.php) | A tool call producing a span (logged via `LogTracer`) and metrics (in-memory snapshot), with a masked `password` argument | no |

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
