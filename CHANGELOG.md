# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- Initial implementation: `TracingToolCallInterceptor` (span `mcp.tool <name>`
  per tools/call with masked arguments, client identity, session id and
  optional session-budget remainder) and `MetricsToolCallInterceptor`
  (`mcp_tool_calls_total{tool,outcome}` +
  `mcp_tool_call_duration_seconds{tool}`).
