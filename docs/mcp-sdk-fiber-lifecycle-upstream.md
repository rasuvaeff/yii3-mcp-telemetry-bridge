# Upstream proposal: context-aware request Fiber factory for `mcp/sdk`

Target: `modelcontextprotocol/php-sdk` after `v0.6.0`.

## Problem

`Mcp\Server\Protocol::handleRequest()` creates a request Fiber internally. A
context library cannot capture the parent before that Fiber exists or initialize
Fiber-local storage before the handler reads it. Wrapping a Yii/MCP interceptor
is too late and does not own suspend/resume transitions.

## Minimal SDK contract

Add an optional factory owned by `Builder`/`Protocol`:

```php
namespace Mcp\Server;

interface RequestFiberFactoryInterface
{
    /**
     * The factory is called in the parent execution context. Implementations
     * may capture that context and wrap the callback before creating Fiber.
     *
     * @param callable(): mixed $callback
     */
    public function create(callable $callback): \Fiber;
}

final readonly class RequestFiberFactory implements RequestFiberFactoryInterface
{
    public function create(callable $callback): \Fiber
    {
        return new \Fiber($callback);
    }
}
```

`Builder::setRequestFiberFactory()` stores the optional factory and passes it to
`Protocol`. Replace only the construction site:

```php
$fiber = $this->requestFiberFactory->create(
    static fn () => $handler->handle($request, $session),
);
```

The default preserves behavior and adds no OpenTelemetry dependency.

## OTel adapter outside core SDK

An OTel integration captures the parent while `create()` runs and activates it
at the very start of the Fiber callback:

```php
public function create(callable $callback): Fiber
{
    $parent = Context::getCurrent();

    return new Fiber(static function () use ($callback, $parent): mixed {
        $scope = $parent->activate();

        try {
            return $callback();
        } finally {
            $scope->detach();
        }
    });
}
```

`FiberBoundContextStorage` then owns a distinct head per Fiber object, so
suspend/resume does not require interceptor-level hooks and alternating Fibers
cannot share active contexts.

## Required upstream tests

1. Default factory preserves request success and suspension behavior.
2. Custom factory is called once per handled request before the callback.
3. Captured parent is visible inside the handler.
4. Two alternating request Fibers retain different parents after resume.
5. Factory/callback exceptions follow the existing JSON-RPC error path.

The bridge's `OtelFiberIntegrationTest` already supplies the end-to-end MCP/OTel
regression: one HTTP parent, one child tool span, warnings promoted to
exceptions, and alternating-Fiber isolation.
