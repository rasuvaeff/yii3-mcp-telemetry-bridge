# Upgrade guide

## 1.5.x → 2.0.0

Upgrade `rasuvaeff/yii3-mcp` to `^6.0` in the same step:

```bash
composer require rasuvaeff/yii3-mcp:^6.0 rasuvaeff/yii3-mcp-telemetry-bridge:^2.0
```

Code that names the interceptor types follows the `yii3-mcp` upgrade guide:
`Rasuvaeff\Yii3Mcp\Interceptor\*` is now `Rasuvaeff\Mcp\Interceptor\*`. The
bridge's own classes and configuration are unchanged.
