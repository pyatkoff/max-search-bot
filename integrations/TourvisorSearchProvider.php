<?php
require_once __DIR__ . '/../contracts/SearchProviderInterface.php';
require_once __DIR__ . '/../services/SearchRequestBuilder.php';
require_once __DIR__ . '/../services/ProjectConfig.php';

class TourvisorSearchProvider implements SearchProviderInterface
{
    public function build(array $request, array $context = []): array
    {
        return [
            'provider' => 'tourvisor',
            'project_id' => ProjectConfig::projectId(),
            'base_domain' => ProjectConfig::baseDomain(),
            'ready' => SearchRequestBuilder::isReady($request),
            'missing' => SearchRequestBuilder::missingRequired($request),
            'request' => $request,
            'context' => $context,
        ];
    }
}
