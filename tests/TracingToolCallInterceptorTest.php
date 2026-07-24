<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3McpTelemetryBridge\Tests;

use InvalidArgumentException;
use Mcp\Exception\ToolCallException;
use Rasuvaeff\Yii3Mcp\Interceptor\ArgumentMasker;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3McpTelemetryBridge\Tests\Support\FakeSession;
use Rasuvaeff\Yii3McpTelemetryBridge\Tests\Support\RecordingTracer;
use Rasuvaeff\Yii3McpTelemetryBridge\TracingToolCallInterceptor;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(TracingToolCallInterceptor::class)]
final class TracingToolCallInterceptorTest
{
    private RecordingTracer $tracer;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tracer = new RecordingTracer();
    }

    public function spanIsNamedAfterTheTool(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer);

        $result = $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: []),
            static fn(): string => 'paid',
        );

        Assert::same($result, 'paid');
        Assert::same($this->tracer->spans[0]->name, 'mcp.tool order.status');
    }

    public function successSetsOutcomeAttributeAndLeavesStatusUnset(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer);

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: []),
            static fn(): string => 'paid',
        );

        $span = $this->tracer->spans[0];

        Assert::same($span->attributes['mcp.tool'], 'order.status');
        Assert::same($span->attributes['mcp.outcome'], 'success');
        Assert::same($span->statusCode, SpanStatusCode::Unset);
        Assert::true($span->ended);
    }

    public function argumentsAreMaskedOnTheSpan(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer);

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: ['orderId' => '42', 'password' => 'p@ss']),
            static fn(): string => 'paid',
        );

        $attributes = $this->tracer->spans[0]->attributes;

        Assert::same($attributes['mcp.tool.argument.orderId'], '42');
        Assert::same($attributes['mcp.tool.argument.password'], '***');
    }

    public function customMaskerIsHonored(): void
    {
        $interceptor = new TracingToolCallInterceptor(
            $this->tracer,
            argumentMasker: new ArgumentMasker(['orderId']),
        );

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: ['orderId' => '42', 'password' => 'p@ss']),
            static fn(): string => 'paid',
        );

        $attributes = $this->tracer->spans[0]->attributes;

        Assert::same($attributes['mcp.tool.argument.orderId'], '***');
        Assert::same($attributes['mcp.tool.argument.password'], 'p@ss');
    }

    public function everyArgumentBecomesItsOwnScalarAttribute(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer);

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: [
                'count' => 7,
                'ratio' => 0.5,
                'dryRun' => true,
                'note' => null,
                'filter' => ['status' => 'paid', 'password' => 'p@ss'],
            ]),
            static fn(): string => 'paid',
        );

        $attributes = $this->tracer->spans[0]->attributes;

        Assert::same($attributes['mcp.tool.argument.count'], '7');
        Assert::same($attributes['mcp.tool.argument.ratio'], '0.5');
        Assert::same($attributes['mcp.tool.argument.dryRun'], 'true');
        Assert::same($attributes['mcp.tool.argument.note'], 'null');
        Assert::same($attributes['mcp.tool.argument.filter'], '{"status":"paid","password":"***"}');
    }

    public function longArgumentValuesAreTruncatedFromTheStart(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer);

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: ['blob' => 'HEAD' . str_repeat('x', 300)]),
            static fn(): string => 'paid',
        );

        Assert::same(
            $this->tracer->spans[0]->attributes['mcp.tool.argument.blob'],
            'HEAD' . str_repeat('x', 196) . '…',
        );
    }

    public function argumentValueOfExactlyTheLimitIsNotTruncated(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer);
        $value = str_repeat('x', 200);

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: ['blob' => $value]),
            static fn(): string => 'paid',
        );

        Assert::same($this->tracer->spans[0]->attributes['mcp.tool.argument.blob'], $value);
    }

    public function failureMarksOutcomeErrorAndRethrowsTheOriginalException(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer);
        $boom = new RuntimeException('boom');

        try {
            $interceptor->intercept(
                new ToolCallContext(toolName: 'order.status', arguments: []),
                static fn(): string => throw $boom,
            );

            Assert::true(false);
        } catch (RuntimeException $caught) {
            Assert::same($caught, $boom);
        }

        $span = $this->tracer->spans[0];

        Assert::same($span->attributes['mcp.outcome'], 'error');
        Assert::same($span->statusCode, SpanStatusCode::Error);
        Assert::same($span->recordedException, $boom);
        Assert::true($span->ended);
    }

    public function clientVisibleRejectionMarksOutcomeRejected(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer);
        $rejection = new ToolCallException('rate limit exceeded');

        try {
            $interceptor->intercept(
                new ToolCallContext(toolName: 'order.status', arguments: []),
                static fn(): string => throw $rejection,
            );

            Assert::true(false);
        } catch (ToolCallException $caught) {
            Assert::same($caught, $rejection);
        }

        $span = $this->tracer->spans[0];

        Assert::same($span->attributes['mcp.outcome'], 'rejected');
        Assert::same($span->recordedException, $rejection);
    }

    public function clientIdentityAttributesComeFromContextAndHandshake(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer);
        $session = new FakeSession(['client_info' => ['name' => 'claude', 'version' => '1.2']]);

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: [], session: $session, clientId: 'ci-bot'),
            static fn(): string => 'paid',
        );

        $attributes = $this->tracer->spans[0]->attributes;

        Assert::same($attributes['mcp.client.id'], 'ci-bot');
        Assert::same($attributes['mcp.client.name'], 'claude');
        Assert::same($attributes['mcp.client.version'], '1.2');
        Assert::true(is_string($attributes['mcp.session.id']));
    }

    public function withoutSessionNoSessionOrClientAttributesAppear(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer);

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: []),
            static fn(): string => 'paid',
        );

        $attributes = $this->tracer->spans[0]->attributes;

        Assert::false(array_key_exists('mcp.client.id', $attributes));
        Assert::false(array_key_exists('mcp.client.name', $attributes));
        Assert::false(array_key_exists('mcp.session.id', $attributes));
        Assert::false(array_key_exists('mcp.session.calls_used', $attributes));
    }

    public function sessionBudgetAttributesReflectTheCounter(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer, sessionBudget: 10);
        $session = new FakeSession(['rasuvaeff.yii3-mcp.tool-calls' => 3]);

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: [], session: $session),
            static fn(): string => 'paid',
        );

        $attributes = $this->tracer->spans[0]->attributes;

        Assert::same($attributes['mcp.session.calls_used'], 3);
        Assert::same($attributes['mcp.session.budget_remaining'], 7);
    }

    public function budgetRemainingNeverGoesNegative(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer, sessionBudget: 2);
        $session = new FakeSession(['rasuvaeff.yii3-mcp.tool-calls' => 5]);

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: [], session: $session),
            static fn(): string => 'paid',
        );

        Assert::same($this->tracer->spans[0]->attributes['mcp.session.budget_remaining'], 0);
    }

    public function withoutBudgetCounterOnlyCallsUsedIsOmitted(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer, sessionBudget: 10);
        $session = new FakeSession();

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: [], session: $session),
            static fn(): string => 'paid',
        );

        $attributes = $this->tracer->spans[0]->attributes;

        Assert::true(is_string($attributes['mcp.session.id']));
        Assert::false(array_key_exists('mcp.session.calls_used', $attributes));
        Assert::false(array_key_exists('mcp.session.budget_remaining', $attributes));
    }

    public function clientVersionIsOmittedWhenTheHandshakeCarriesNone(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer);
        $session = new FakeSession(['client_info' => ['name' => 'claude']]);

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: [], session: $session),
            static fn(): string => 'paid',
        );

        $attributes = $this->tracer->spans[0]->attributes;

        Assert::same($attributes['mcp.client.name'], 'claude');
        Assert::false(array_key_exists('mcp.client.version', $attributes));
    }

    public function sessionBudgetOfOneIsAccepted(): void
    {
        $interceptor = new TracingToolCallInterceptor($this->tracer, sessionBudget: 1);
        $session = new FakeSession(['rasuvaeff.yii3-mcp.tool-calls' => 1]);

        $interceptor->intercept(
            new ToolCallContext(toolName: 'order.status', arguments: [], session: $session),
            static fn(): string => 'paid',
        );

        Assert::same($this->tracer->spans[0]->attributes['mcp.session.budget_remaining'], 0);
    }

    public function throwsOnNonPositiveSessionBudget(): void
    {
        try {
            new TracingToolCallInterceptor($this->tracer, sessionBudget: 0);

            Assert::true(false);
        } catch (InvalidArgumentException $exception) {
            Assert::string($exception->getMessage())->contains('at least 1');
        }
    }
}
