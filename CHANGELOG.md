# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.2.0 — 2026-07-27

- Compatibility with yii3-mcp 2.0: the `rasuvaeff/yii3-mcp` constraint is
  widened to `^1.6 || ^2.0`. No code changes — the consumed interceptor API
  is unchanged in 2.0; see the core's
  [UPGRADE.md](https://github.com/rasuvaeff/yii3-mcp/blob/master/UPGRADE.md)
  for the breaking changes on the core side.
- Document the session-ownership blind zone: on yii3-mcp `^2.0` a call
  rejected because the session belongs to another MCP client is refused
  before the interceptor chain, so it produces no span and no metric.

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
