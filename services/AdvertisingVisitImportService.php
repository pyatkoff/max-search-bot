<?php
declare(strict_types=1);
require_once __DIR__.'/AdvertisingVisitContract.php';
require_once __DIR__.'/AdvertisingVisitRepository.php';

/** Trusted-source boundary. A JSON field cannot authenticate a user or sender. */
final class AdvertisingVisitImportService
{
    private AdvertisingVisitRepository $repository;
    private array $scope;
    public function __construct(AdvertisingVisitRepository $repository, array $trustedScope)
    {
        $this->repository = $repository;
        $this->scope = AdvertisingVisitContract::scope($trustedScope);
    }

    public function import(array $page, int $now): array
    {
        $normalized = AdvertisingVisitContract::page($page, $this->scope, $now);
        return $this->repository->importPage($normalized, $now);
    }
}
