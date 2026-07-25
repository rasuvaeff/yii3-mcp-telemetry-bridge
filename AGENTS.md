# AGENTS.md — yii3-mcp-telemetry-bridge

Guidance for AI agents working on this package. Read before changing code.

## What this is

Bridge between `rasuvaeff/yii3-mcp` and the Yii3 observability stack
(namespace `Rasuvaeff\Yii3McpTelemetryBridge`): two independent
`ToolCallInterceptorInterface` implementations. `TracingToolCallInterceptor`
wraps every MCP `tools/call` in a `mcp.tool <name>` span via
`rasuvaeff/yii3-telemetry` (per-argument masked/stringified/truncated
attributes, client identity, session id, outcome, optional session-budget
remainder). `MetricsToolCallInterceptor`
records `mcp_tool_calls_total{tool,outcome}` and
`mcp_tool_call_duration_seconds{tool}` via `rasuvaeff/yii3-metrics`.

Public API: `TracingToolCallInterceptor`, `MetricsToolCallInterceptor`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Never swallow or reshape tool failures, never unmask arguments.** Both
   interceptors rethrow the ORIGINAL exception — the MCP error envelope the
   agent sees must be byte-identical with and without this bridge. Arguments
   reach the span only through yii3-mcp's `ArgumentMasker` (same semantics
   as the audit bridge — the two must never drift apart).
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`,
`make test-coverage`, `make mutation`, `make release-check`.

All three runtime dependencies are on Packagist — a plain `composer install`
works, no path repos needed.

## Invariants & gotchas

- **Metric names are Prometheus-strict** (underscores, no dots) — the
  yii3-metrics name regex rejects dotted names even in `NullMeter`. Span
  names/attributes are dotted (OTel style). Don't "unify" them.
- **Arguments are flattened to `mcp.tool.argument.<name>` SCALARS** —
  masked, stringified (arrays as JSON), truncated at 200 bytes. Never
  revert to a single array attribute: the OTel attribute model accepts
  only primitives/homogeneous lists, so an OTel backend drops nested
  assoc arrays and strips keys from flat ones. Mirrors OtelMiddleware's
  `http.request.param.<name>` in yii3-telemetry-otel.
- **Telemetry blind spots are documented, not bugs**: budget rejections
  happen outside this bridge (core auto-adds SessionBudgetInterceptor
  outermost — no span, no metric). Outcomes follow yii3-mcp's `CallOutcome`
  (`success`/`rejected`/`error`): ToolCallException => `rejected`; never
  reclassify locally — the audit bridge uses the same vocabulary. Keep the
  README "What the telemetry does NOT see" section in sync.
- The duration histogram deliberately has NO `outcome` label (cardinality);
  errors are distinguished by the counter. Document, don't "fix".
- `TracingToolCallInterceptor::BUDGET_COUNTER_KEY` mirrors the PRIVATE
  counter key of yii3-mcp's `SessionBudgetInterceptor`
  (`rasuvaeff.yii3-mcp.tool-calls`). A key drift only drops the budget span
  attributes — never breaks the call. Re-check on yii3-mcp major bumps.
- `sessionBudget` is observability-only: `null` and `0` mean unlimited and omit
  the remaining attribute; negative values are invalid. Enforcement stays in the core's
  `SessionBudgetInterceptor`. The remaining-budget attribute is computed
  AFTER the budget interceptor incremented the counter (it runs outermost),
  so it reflects the state including the current call.
- The span follows yii3-telemetry's frozen `trace()` contract: exception →
  `recordException` + `Error` status + rethrow; success → status stays
  `Unset` (never auto-`Ok`). `RecordingTracer` in tests mimics exactly that
  contract — keep it in sync if the contract ever gains fields.
- `tests/Support/OrderTool.php` keeps an unused `$password` parameter on
  purpose (it defines the tool input schema for the masking test);
  `rector.php` skips `RemoveUnusedPublicMethodParameterRector` for that file.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit;
  and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
