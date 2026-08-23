<?php

interface LeadDestinationInterface
{
    /**
     * Build a destination-specific lead handoff plan from neutral state/context.
     * Execution can remain legacy until the destination adapter is promoted.
     */
    public function plan(array $tripState, array $userContext = []): array;
}
