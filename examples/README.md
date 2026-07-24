# Examples

| Script | Shows | Needs server? |
|--------|-------|:-------------:|
| [`tool-call-observability.php`](tool-call-observability.php) | A tool call through a real MCP server producing a span (logged via `LogTracer`) and metrics (in-memory snapshot); the `password` argument reaches the span masked | no |

## Running

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/tool-call-observability.php
```

No external services required: the tracer logs spans to stdout and the
metrics land in an in-memory snapshot. In an application you bind the real
backends (`yii3-telemetry-otel`, `yii3-metrics-prometheus`) instead.
