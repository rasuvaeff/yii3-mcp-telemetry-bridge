# rasuvaeff/yii3-mcp-telemetry-bridge

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-mcp-telemetry-bridge?label=stable&sort_semver=1)](https://packagist.org/packages/rasuvaeff/yii3-mcp-telemetry-bridge)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-mcp-telemetry-bridge)](https://packagist.org/packages/rasuvaeff/yii3-mcp-telemetry-bridge)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-mcp-telemetry-bridge/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-mcp-telemetry-bridge/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-mcp-telemetry-bridge/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-mcp-telemetry-bridge/actions)
[![Psalm level](https://img.shields.io/badge/psalm-level%201-141F48?logo=psalm&logoColor=white)](https://github.com/rasuvaeff/yii3-mcp-telemetry-bridge/blob/master/psalm.xml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-mcp-telemetry-bridge/php)](https://packagist.org/packages/rasuvaeff/yii3-mcp-telemetry-bridge)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-mcp-telemetry-bridge)](LICENSE.md)
[English version](README.md)

Наблюдаемость для MCP-серверов: trace-span и RED-метрики на каждый
`tools/call` [rasuvaeff/yii3-mcp](https://github.com/rasuvaeff/yii3-mcp) —
через [rasuvaeff/yii3-telemetry](https://github.com/rasuvaeff/yii3-telemetry)
и [rasuvaeff/yii3-metrics](https://github.com/rasuvaeff/yii3-metrics).

> **Используете AI-ассистента?** [llms.txt](llms.txt) содержит компактный
> API-справочник для модели. Контрибьюторам: см. [AGENTS.md](AGENTS.md).

## Требования

| Требование | Версия |
|-------------|---------|
| PHP | 8.3 – 8.5 |
| `rasuvaeff/yii3-mcp` | `^1.6 \|\| ^2.0` |
| `rasuvaeff/yii3-telemetry` | `^1.0` |
| `rasuvaeff/yii3-metrics` | `^1.0` |

Оба observability-ядра vendor-neutral: подключите backend
(`yii3-telemetry-otel`, `yii3-metrics-prometheus`) или `Null*`-провайдеры.

## Установка

```bash
composer require rasuvaeff/yii3-mcp-telemetry-bridge
```

## Использование

Одна строка в params — интерцепторы резолвятся через DI-контейнер
(фасады telemetry/metrics должны быть сконфигурированы — их конфиги это делают):

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

Можно использовать любой из интерцепторов отдельно, если работает только
один из двух стеков.

### Трейсинг: `TracingToolCallInterceptor`

Каждый `tools/call` — атрибутные tools, OpenAPI-мост,
configurator-регистрации — становится одним span'ом:

| Поле span | Значение |
|---|---|
| name | `mcp.tool <имя tool>` (например `mcp.tool order.status`) |
| `mcp.tool` | имя tool |
| `mcp.tool.argument.<name>` | по скалярному атрибуту на аргумент: маскирование (`***`), stringify (массивы — JSON), усечение до 200 байт |
| `mcp.outcome` | `success` / `rejected` / `error` (единый `CallOutcome` из yii3-mcp) |
| `mcp.client.id` | identity из endpoint-секрета (при нескольких секретах); отсутствует в stdio |
| `mcp.client.name` / `mcp.client.version` | клиент из initialize handshake |
| `mcp.session.id` | UUID MCP-сессии |
| `mcp.session.calls_used` | число tools/call в сессии (при включённом session budget) |
| `mcp.session.budget_remaining` | остаток бюджета (при заданном `sessionBudget`, см. ниже) |
| status | `Error` + записанное исключение при сбое; `Unset` при успехе |

Исключение tool'а записывается на span и **перебрасывается** — MCP
error envelope, который видит агент, не меняется.

Аргументы намеренно разложены в отдельные скалярные атрибуты: модель
атрибутов OTel принимает только примитивы и гомогенные списки, поэтому
один вложенный массив-атрибут OTel-backend молча отбросил бы.

Ручная сборка:

```php
$interceptor = new TracingToolCallInterceptor(
    tracer: $tracer,                          // Rasuvaeff\Yii3Telemetry\TracerInterface
    argumentMasker: new ArgumentMasker(),     // ключи по умолчанию: password, secret, token, api_key, credit_card
    sessionBudget: 50,                        // опционально: зеркало параметра `session.budget`
);
```

`sessionBudget` влияет только на атрибут `mcp.session.budget_remaining` —
сам бюджет enforce'ит `SessionBudgetInterceptor` из yii3-mcp. `int` не
автовайрится — зеркальте параметр `session.budget` в DI-фабрике:

```php
// config/common/di/mcp-telemetry.php
use Rasuvaeff\Yii3McpTelemetryBridge\TracingToolCallInterceptor;
use Rasuvaeff\Yii3Telemetry\TracerInterface;

return [
    TracingToolCallInterceptor::class => static function (TracerInterface $tracer) use ($params) {
        $budget = $params['rasuvaeff/yii3-mcp']['session']['budget'] ?? 0;

        return new TracingToolCallInterceptor($tracer, sessionBudget: $budget);
    },
];
```

`null` и core default `0` означают unlimited и не публикуют
`mcp.session.budget_remaining`; любое положительное значение публикует его.
Отрицательные значения отклоняются.

### OpenTelemetry и Fiber из SDK

`mcp/sdk` запускает request handlers в Fiber. Поддерживаемый concurrent path:
NTS PHP, `ext-ffi`, `OTEL_PHP_FIBERS_ENABLED=true` и, если FPM подключает
observer слишком поздно, preload `vendor/autoload.php`. Integration suite
проверяет один HTTP parent, дочерний `mcp.tool ...` span и изоляцию контекстов
чередующихся Fiber:

```bash
MCP_OTEL_FIBER_TEST=1 vendor/bin/testo --suite=Integration
```

Только для строго последовательного php-fpm допустим ограниченный fallback:
`Context::setStorage(new ContextStorage())` до начала tracing. Он использует
один current context и небезопасен для event loop или конкурентных Fiber с
suspend/resume. Нельзя добавлять `fork()/switch()` только вокруг
`TracingToolCallInterceptor::$next`: Fiber SDK уже создан, а interceptor не
видит все lifecycle transitions.

### Метрики: `MetricsToolCallInterceptor`

| Метрика | Тип | Лейблы |
|---|---|---|
| `mcp_tool_calls_total` | counter | `tool`, `outcome` (`success`/`rejected`/`error`) |
| `mcp_tool_call_duration_seconds` | histogram | `tool` |

Длительность — wall time всей обёрнутой цепочки (`hrtime()`), наблюдается и
при успехе, и при ошибке. У гистограммы нет лейбла `outcome` — чтобы не
раздувать кардинальность; ошибки считает counter.

Ручная сборка:

```php
$interceptor = new MetricsToolCallInterceptor(
    metrics: $registry,                        // Rasuvaeff\Yii3Metrics\MetricRegistry
    durationBuckets: [0.05, 0.1, 0.5, 1.0],    // опционально; иначе Prometheus-дефолты
);
```

### Порядок интерцепторов

Трейсинг — самым внешним, чтобы span покрывал всю цепочку (rate limit,
RBAC, audit) и сбои остальных интерцепторов попадали на span:

```php
'interceptors' => [
    TracingToolCallInterceptor::class,   // внешний
    MetricsToolCallInterceptor::class,
    // ... RBAC / audit / rate-limit интерцепторы
],
```

### Чего телеметрия НЕ видит

- **Отказы по бюджету невидимы.** yii3-mcp добавляет свой
  `SessionBudgetInterceptor` самым внешним — снаружи любых интерцепторов
  из вашего списка. Вызов, отбитый session budget, не создаёт ни span,
  ни метрику: исчерпанный бюджет выглядит как падение трафика в ноль.
  Следите за атрибутом `mcp.session.budget_remaining` на проходящих вызовах.
- **Отказы по владению сессией невидимы** (yii3-mcp `^2.0`). Ядро привязывает
  каждую сессию к создавшему её MCP-клиенту и отклоняет вызов к чужой или
  безвладельческой сессии (SDK-шейп 404 из `McpAction`,
  `SessionOwnershipException` из `InterceptingReferenceHandler`) **до**
  запуска цепочки интерцепторов. Отклонённый по владению вызов не создаёт ни
  span, ни инкремент `mcp_tool_calls_total` — попытки hijack'а видны только в
  логах приложения/веб-сервера, а не в телеметрии.
- **Отказы — это `rejected`, не `error`.** `ToolCallException` из
  внутреннего интерцептора (RBAC, rate limit, session budget) или самого
  tool'а классифицируется через `CallOutcome` ядра: counter
  `outcome="rejected"`, атрибут span'а `mcp.outcome=rejected` (статус
  span'а по-прежнему `Error` с записанным исключением — контракт трейсинга
  не менялся). Алертитесь на `outcome="error"` — это только падения.

### stdio-режим (`mcp:serve`)

stdio-воркер долгоживущий: убедитесь, что tracing-backend экспортирует
span'ы после каждого вызова, а не только при завершении процесса (для
`yii3-telemetry-otel` — batch-процессор с scheduled delay или simple
processor), иначе span'ы копятся до отключения агента.

## Безопасность

- Аргументы попадают на span **замаскированными по имени поля**
  (без учёта регистра, на любой глубине) через `ArgumentMasker` из
  yii3-mcp — та же семантика, что у audit-бриджа, поэтому они никогда
  не разойдутся. Список ключей расширяется через конструктор.
- Атрибуты span уходят из процесса через tracing-backend — относитесь к
  хранилищу трейсов так же, как к данным, к которым имеют доступ tools,
  либо маскируйте агрессивнее.
- Интерцепторы не добавляют tool'ам новых точек отказа: исключения
  перебрасываются без изменений.

## Примеры

См. [examples/](examples/) — работают офлайн.

| Скрипт | Показывает | Нужен сервер? |
|--------|-------|:-------------:|
| [`tool-call-observability.php`](examples/tool-call-observability.php) | Вызов tool'а со span'ом (через `LogTracer`) и метриками (in-memory snapshot), аргумент `password` замаскирован | нет |

### Анализаторы зависимостей

Это leaf-пакет, который root-приложение выбирает через config-plugin, поэтому в
autoloaded source может законно не быть прямой ссылки на его классы. Сохраняйте
direct dependency: backend или bridge выбирает приложение, а не core-пакет.
Исключение Composer Dependency Analyser должно быть ограничено этим пакетом:

```php
use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())->ignoreErrorsOnPackage(
    'rasuvaeff/yii3-mcp-telemetry-bridge',
    [ErrorType::UNUSED_DEPENDENCY],
);
```

`composer-require-checker` ищет используемые, но не объявленные symbols, а не
unused packages, поэтому для такой config-only зависимости suppression ему не
нужен.

## Разработка

PHP/Composer на хосте нет — всё через Docker (образ `composer:2`):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Или через Make: `make build`, `make cs-fix`, `make psalm`, `make test`.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
