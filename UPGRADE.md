# Upgrade guide

## 1.5.x → 2.0.0

Upgrade `rasuvaeff/yii3-mcp` to `^6.0` in the same step:

```bash
composer require rasuvaeff/yii3-mcp:^6.0 rasuvaeff/yii3-mcp-telemetry-bridge:^2.0
```

Code that names the interceptor types follows the `yii3-mcp` upgrade guide:
`Rasuvaeff\Yii3Mcp\Interceptor\*` is now `Rasuvaeff\Mcp\Interceptor\*`, from
`rasuvaeff/mcp`, which such code should require directly. The
bridge's own class names, behaviour and configuration are unchanged; their signatures use the new types,
including `Rasuvaeff\Mcp\Interceptor\ArgumentMasker` in the `TracingToolCallInterceptor`
constructor.
