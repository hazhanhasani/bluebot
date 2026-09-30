<?php

declare(strict_types=1);

/**
 * Normalized virtual-number lifecycle used by BlueBot.
 *
 * Provider adapters keep provider-specific HTTP shapes out of checkout and
 * reconciliation code. Every adapter returns BlueBot-normalized arrays and
 * keeps raw responses under "response" for administrator diagnostics.
 */
interface BluebotVirtualNumberProviderInterface
{
    public function requestNumber(array $product): array;

    public function status(string $reference, array $product): array;

    public function cancel(string $reference, array $product): array;

    public function reportBanned(string $reference, array $product): array;
}
