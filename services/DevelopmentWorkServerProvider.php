<?php
declare(strict_types=1);
require_once __DIR__.'/DevelopmentWorkServerContract.php';

/** Existing configured transport, new bounded purpose. Test sender is never an MCP argument. */
final class DevelopmentWorkServerProvider
{
    public function __construct(private $sender=null)
    {
        if($sender!==null&&!is_callable($sender))throw new InvalidArgumentException('development_server_sender');
    }
    public function ready(): bool
    {
        return $this->sender!==null||ManagerDraftClient::developmentTransportAvailable();
    }
    public function prepare(array $job): string
    {
        if(($job['execution_kind']??null)!=='server_model'||!in_array($job['role']??null,['producer','reviewer'],true)
            ||!in_array($job['kind']??null,['commissioning','readiness'],true))
            throw new DomainException('development_server_job');
        $payload=['input'=>$job['input'],'facts'=>$job['facts'],
            'evidence'=>$job['evidence'],'candidate'=>$job['candidate'],'producer_evidence'=>$job['producer_evidence']];
        $body=ManagerDraftClient::developmentRequestBody($job['role'],
            DevelopmentWorkServerContract::instructions($job['role']),$payload,
            DevelopmentWorkServerContract::schema($job['role'],$job['kind']));
        $wire=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(strlen($wire)>DevelopmentWorkServerContract::MAX_REQUEST_BYTES)
            throw new DomainException('development_server_request_budget');
        return $wire;
    }
    /** Exactly one original send. The captured evidence is from transport, never model JSON. */
    public function send(string $wire): array
    {
        $meta=null;
        $capture=static function(array $m)use(&$meta):void {$meta=$m;};
        $result=$this->sender!==null?($this->sender)($wire,$capture)
            :ManagerDraftClient::requestDevelopmentPreparedJson($wire,$capture);
        return ['report'=>$result,'transport'=>$meta];
    }
}
