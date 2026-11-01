<?php
declare(strict_types=1);
require_once __DIR__.'/HotelCollectionCanonicalDocument.php';

/** Caller owns transaction and authenticates/locks queue before every call.
 * No Library loader, DDL, enrollment or dispatch. Original bytes are immutable.
 */
final class HotelWorkingSnapshot
{
    public function __construct(private PDO $pdo) {}
    public function import(array $queue,string $bytes,string $expectedSha,string $request): array
    {
        $this->transaction();
        if(strlen($bytes)>67108864||!hash_equals($expectedSha,hash('sha256',$bytes)))throw new DomainException('working_import_digest_mismatch');
        $doc=HotelCollectionCanonicalDocument::decodeObject($bytes);
        foreach(['knowledge','hotel_index','working_acceptances','country_import_ledger','coordination','criteria_updates','scoped_question_results']as$key)
            if(!isset($doc[$key])||!is_array($doc[$key]))throw new InvalidArgumentException('working_import_shape');
        foreach(['claims','sources_including_unclaimed','source_read_updates']as$key)
            if(!isset($doc['knowledge'][$key])||!is_array($doc['knowledge'][$key]))throw new InvalidArgumentException('working_import_knowledge_shape');
        $pins=['queue_id'=>$queue['queue_id'],'queue_origin_baseline_sha256'=>$queue['baseline_sha256'],
            'plan_sha256'=>$queue['plan_sha256'],'stage_index'=>(int)$queue['stage_index'],
            'accepted_batches'=>(int)$queue['accepted_batches'],'phase_counter'=>(int)$queue['phase_counter'],
            'imported_data_snapshot_sha256'=>$expectedSha];
        $pinBytes=self::json($pins);$input=hash('sha256',$pinBytes);
        $old=$this->one('SELECT * FROM hotel_working_imports WHERE queue_id=?',[$queue['queue_id']]);
        if($old){if($old['request_id']!==$request||$old['input_sha256']!==$input)throw new DomainException('working_import_conflict');return json_decode($old['receipt_bytes'],true,32,JSON_THROW_ON_ERROR);}
        // Retain opaque bytes including all variants, origins, reserves and history.
        $receipt=$pins+['import_credit'=>0,'native_run_verified'=>false,'business_accepted'=>false];
        $this->exec('INSERT INTO hotel_working_imports(queue_id,request_id,input_sha256,data_sha256,origin_sha256,body_bytes,receipt_bytes) VALUES(?,?,?,?,?,?,?)',
            [$queue['queue_id'],$request,$input,$expectedSha,$queue['baseline_sha256'],$bytes,self::json($receipt)]);
        return $receipt;
    }
    /** Exact JSON pointer, immutable snapshot pin and byte cursor. No truncation.
     * CONTROL reader only; worker adapters must enforce assignment pointer scope.
     */
    public function read(string $queue,string $sha,string $pointer,int $offset=0,int $limit=16384):array
    {
        $this->transaction();if($offset<0||$limit<1||$limit>16384)throw new InvalidArgumentException('working_read_limit');
        $row=$this->one('SELECT * FROM hotel_working_imports WHERE queue_id=?',[$queue]);
        if(!$row||!hash_equals($sha,$row['data_sha256'])||!hash_equals($sha,hash('sha256',$row['body_bytes'])))throw new DomainException('working_snapshot_unavailable');
        $value=HotelCollectionCanonicalDocument::decodeObject($row['body_bytes']);
        if($pointer!==''){
            if(!str_starts_with($pointer,'/')||preg_match('/~(?![01])/',$pointer))throw new InvalidArgumentException('working_pointer_invalid');
            foreach(explode('/',substr($pointer,1))as$part){$part=str_replace(['~1','~0'],['/','~'],$part);if(!is_array($value)||!array_key_exists($part,$value))throw new DomainException('working_pointer_missing');$value=$value[$part];}
        }
        // Root returns the original bytes, not a JSON reserialization.
        $bytes=$pointer===''?$row['body_bytes']:self::json($value);
        if($offset>strlen($bytes))throw new InvalidArgumentException('working_offset_invalid');
        $chunk=substr($bytes,$offset,$limit);$next=$offset+strlen($chunk);
        return ['data_snapshot_sha256'=>$sha,'queue_origin_baseline_sha256'=>$row['origin_sha256'],'pointer'=>$pointer,
            'value_sha256'=>hash('sha256',$bytes),'total_bytes'=>strlen($bytes),'offset'=>$offset,'chunk_base64'=>base64_encode($chunk),
            'next_offset'=>$next<strlen($bytes)?$next:null,'complete'=>$next===strlen($bytes),'business_accepted'=>false];
    }
    private function transaction():void{if(!$this->pdo->inTransaction())throw new LogicException('working_requires_locked_transaction');}
    private function one(string$s,array$a):?array{$q=$this->pdo->prepare($s);$q->execute($a);return$q->fetch(PDO::FETCH_ASSOC)?:null;}
    private function exec(string$s,array$a):void{$q=$this->pdo->prepare($s);$q->execute($a);}
    private static function json(mixed$v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
