<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3McpTelemetryBridge\Tests\Support;

use Mcp\Capability\Attribute\McpTool;

final readonly class OrderTool
{
    /**
     * Returns the status of an order.
     */
    #[McpTool(name: 'order.status')]
    public function status(string $orderId, string $password): string
    {
        return 'paid:' . $orderId;
    }
}
