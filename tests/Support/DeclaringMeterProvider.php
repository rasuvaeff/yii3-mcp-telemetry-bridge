<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3McpTelemetryBridge\Tests\Support;

use Rasuvaeff\Yii3Metrics\CounterInterface;
use Rasuvaeff\Yii3Metrics\GaugeInterface;
use Rasuvaeff\Yii3Metrics\HistogramInterface;
use Rasuvaeff\Yii3Metrics\InMemoryMeter;
use Rasuvaeff\Yii3Metrics\MeterInterface;
use Rasuvaeff\Yii3Metrics\MeterProviderInterface;
use Rasuvaeff\Yii3Metrics\UpDownCounterInterface;

/**
 * Records instrument declarations (label names, help, buckets) that
 * InMemoryMeter accepts but does not expose.
 */
final class DeclaringMeterProvider implements MeterProviderInterface, MeterInterface
{
    /** @var array<string, array{labelNames: list<string>, help: string, buckets: list<float>}> */
    public array $declarations = [];

    private readonly InMemoryMeter $inner;

    public function __construct()
    {
        $this->inner = new InMemoryMeter();
    }

    #[\Override]
    public function getMeter(?string $name = null): MeterInterface
    {
        return $this;
    }

    #[\Override]
    public function counter(string $name, string $help = '', array $labelNames = []): CounterInterface
    {
        $this->declarations[$name] = ['labelNames' => $labelNames, 'help' => $help, 'buckets' => []];

        return $this->inner->counter($name, $help, $labelNames);
    }

    #[\Override]
    public function gauge(string $name, string $help = '', array $labelNames = []): GaugeInterface
    {
        return $this->inner->gauge($name, $help, $labelNames);
    }

    #[\Override]
    public function upDownCounter(string $name, string $help = '', array $labelNames = []): UpDownCounterInterface
    {
        return $this->inner->upDownCounter($name, $help, $labelNames);
    }

    #[\Override]
    public function histogram(
        string $name,
        string $help = '',
        array $labelNames = [],
        array $buckets = [],
    ): HistogramInterface {
        $this->declarations[$name] = ['labelNames' => $labelNames, 'help' => $help, 'buckets' => $buckets];

        return $this->inner->histogram($name, $help, $labelNames, $buckets);
    }
}
