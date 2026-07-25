# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.1 — 2026-07-25

- Treat `sessionBudget = 0` as unlimited so the bridge matches yii3-mcp's
  default configuration.
- Verify real OpenTelemetry parent/child context and isolation across MCP SDK
  Fibers with an opt-in integration suite that also runs in CI.

## 1.1.0 — 2026-07-24

- Outcomes adopt yii3-mcp's shared `CallOutcome` vocabulary: a
  client-visible refusal (`ToolCallException` — rate limit, RBAC, session
  budget) now lands as `rejected` in the `mcp.outcome` span attribute and
  the counter's `outcome` label; unexpected failures stay `error`. Requires
  `rasuvaeff/yii3-mcp` `^1.6`.

## 1.0.0 — 2026-07-24

- Initial release: `TracingToolCallInterceptor` (span `mcp.tool <name>` per
  tools/call with per-argument masked/stringified/truncated attributes,
  client identity, session id and optional session-budget remainder) and
  `MetricsToolCallInterceptor` (`mcp_tool_calls_total{tool,outcome}` +
  `mcp_tool_call_duration_seconds{tool}`).
