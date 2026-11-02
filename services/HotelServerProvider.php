<?php
declare(strict_types=1);

/** Private adapters implement real research and independent review. No default transport. */
interface HotelServerProvider
{
    /** Return true only when the configured model/transport supports this bounded role. */
    public function ready(string $role, string $model): bool;
    /** A separate reviewer identity is required in addition to a fresh invocation ID. */
    public function principal(string $role): string;
    /** Must make at most one external send; UNKNOWN is already durable when called. */
    public function send(array $immutableRequest): array;
}

/** Optional local-only preparation, executed before any attempt or budget reservation. */
interface HotelServerPreparedProvider extends HotelServerProvider
{
    public function preflight(array $immutableRequest): void;
}
