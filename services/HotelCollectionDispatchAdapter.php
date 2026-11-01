<?php
declare(strict_types=1);

/** Server composition only. No public MCP dispatch or installation API. */
interface HotelCollectionDispatchAdapter
{
    public function assertReady(array $request):void;
    public function __invoke(array $request):array;
}
