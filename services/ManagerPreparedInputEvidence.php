<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerAiDraftInputEvidence.php';

/** The exact server packet, not proof of receipt/use or the full external model input. No I/O. */
final class ManagerPreparedInputEvidence
{
    public const MAX_BYTES=393216;
    private const FLAGS=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
    private function __construct(private string $serialized,private array $value,private ManagerAiDraftInputEvidence $input) {}

    public static function capture(array $packet,array $images=[]): ?self
    {
        try{
        $input=ManagerAiDraftInputEvidence::capture($packet['context']??[],$packet['instructions']??'',$images);
        if(!$input)return null;
        $v=json_decode($input->json(),true,64,JSON_THROW_ON_ERROR);
        return self::fromJson(json_encode(['schema_version'=>1,'provenance'=>'server_issued_context',
            'packet'=>$packet,'coverage'=>$v['coverage'],'images'=>$v['images'],'external_model_input'=>'unknown'],self::FLAGS,64));}
        catch(Throwable $ignored){return null;}
    }

    public static function fromJson(string $json): ?self
    {
        if($json===''||strlen($json)>self::MAX_BYTES)return null;
        try{
            $v=json_decode($json,true,64,JSON_THROW_ON_ERROR);$p=$v['packet']??null;
            if(!is_array($v)||array_keys($v)!==['schema_version','provenance','packet','coverage','images','external_model_input']
                ||$v['schema_version']!==1||$v['provenance']!=='server_issued_context'||$v['external_model_input']!=='unknown'
                ||!is_array($p)||array_keys($p)!==['ok','job_id','revision','preparation_scope','latest_message_id','context','company_knowledge','instructions','instruction_revision']
                ||$p['ok']!==true||!self::digest($p['job_id'])||!self::digest($p['revision'])||!self::digest($p['instruction_revision'])
                ||!in_array($p['preparation_scope'],['opened_chat','unassigned_waiting','assigned_manager'],true)
                ||!is_int($p['latest_message_id'])||!is_array($p['context'])||$p['latest_message_id']!==($p['context']['as_of_message_id']??null)
                ||!is_array($p['company_knowledge'])||array_keys($p['company_knowledge'])!==['facts','revision']
                ||!is_array($p['company_knowledge']['facts'])||!self::digest($p['company_knowledge']['revision'])
                ||!is_string($p['instructions'])||json_encode($v,self::FLAGS,64)!==$json)return null;
            $input=ManagerAiDraftInputEvidence::fromJson(json_encode(['schema_version'=>1,'context'=>$p['context'],
                'instructions'=>$p['instructions'],'instructions_sha256'=>hash('sha256',$p['instructions']),
                'coverage'=>$v['coverage'],'images'=>$v['images']],self::FLAGS,64));
            return $input?new self($json,$v,$input):null;
        }catch(Throwable $ignored){return null;}
    }
    public function json(): string{return $this->serialized;}
    public function hash(): string{return hash('sha256',$this->serialized);}
    public function packet(): array{return $this->value['packet'];}
    public function input(): ManagerAiDraftInputEvidence{return $this->input;}
    private static function digest(mixed $v): bool{return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;}
}
