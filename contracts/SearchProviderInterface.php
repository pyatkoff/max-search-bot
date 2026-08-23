<?php

interface SearchProviderInterface
{
    /**
     * Build a provider-specific search plan from the neutral SearchRequest.
     * Must not send messages or mutate dialogue state.
     */
    public function build(array $request, array $context = []): array;
}
