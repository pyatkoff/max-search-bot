<?php
declare(strict_types=1);
// Exact saved hotel content only. No collector, publisher, imports or supplier calls.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
function content_require(bool $ok,string $code):void{if(!$ok)throw new RuntimeException($code);}
function content_requests(array $rows):array{
    content_require(array_is_list($rows)&&count($rows)===96,'request_count_invalid');$ids=[];
    foreach($rows as $r){
        content_require(is_array($r)&&array_keys($r)===['work_id','tourvisor_id','name','country_id'],'request_schema_invalid');
        content_require(is_string($r['work_id'])&&preg_match('/\AEXP[0-9]{3}\z/D',$r['work_id'])===1&&is_int($r['tourvisor_id'])&&$r['tourvisor_id']>0&&is_string($r['name'])&&$r['name']!==''&&is_int($r['country_id'])&&$r['country_id']>0,'request_identity_invalid');
        content_require(!isset($ids[$r['tourvisor_id']]),'duplicate_id');$ids[$r['tourvisor_id']]=$r;
    }return $ids;
}
function content_summary(array $profile):array{
    $images=array_values(array_filter($profile['images']??[],static fn($x)=>is_string($x)&&str_starts_with($x,'https://')));
    return ['name'=>$profile['name']??null,'country'=>$profile['country']??null,'region'=>$profile['region']??null,
        'description_present'=>is_string($profile['description']??null)&&trim($profile['description'])!=='',
        'image_urls'=>count(array_unique($images)),'images_visually_reviewed'=>false,
        'details_available'=>$profile['detailsAvailable']??null,'content_state'=>$profile['contentState']??null,
        'source_fetched_at'=>$profile['detailsFetchedAt']??null,'source_date_is_current_verification'=>false];
}
if(($argv[1]??'')==='--self-test'){
    $r=[];for($i=1;$i<=96;$i++)$r[]=['work_id'=>sprintf('EXP%03d',$i),'tourvisor_id'=>$i,'name'=>'Fixture '.$i,'country_id'=>8];
    content_require(count(content_requests($r))===96,'test_valid_scope');$checks=1;
    foreach([array_slice($r,1),array_merge($r,[$r[0]]),array_replace($r,[1=>$r[0]]),array_replace($r,[0=>array_replace($r[0],['tourvisor_id'=>'1'])])] as $bad){$rejected=false;try{content_requests($bad);}catch(RuntimeException){$rejected=true;}content_require($rejected,'test_invalid_scope');$checks++;}
    $s=content_summary(['description'=>' text ','images'=>['https://example.org/a.jpg','https://example.org/a.jpg','http://example.org/b.jpg']]);
    content_require($s['description_present']&&$s['image_urls']===1&&!$s['images_visually_reviewed'],'test_summary');$checks++;
    echo 'EXPANSION96_READ_TESTS_OK checks='.$checks.' server_calls=0 writes=0',PHP_EOL;exit;
}
$out=['ok'=>false,'mode'=>'read_saved_expansion96_content','checked_at'=>gmdate('c'),'supplier_calls'=>0,'db_writes'=>0,'post_writes'=>0,'feed_writes'=>0,'journal_writes'=>0,'site_writes'=>0,'new_ai_admissions'=>0,'new_posts'=>0];
$pdo=null;
try{
    content_require(defined('EXPANSION96_REQUESTS_B64'),'request_manifest_missing');
    $requests=content_requests(json_decode(base64_decode(EXPANSION96_REQUESTS_B64,true),true,32,JSON_THROW_ON_ERROR));
    $root='/var/www/anytoour/data/www/anytoour.ru/data';
    $pins=['db-v1.php'=>'c7e13f41be4a317087fc063da0db6f5dcf769e29','local-tv-catalog-v1.php'=>'b4ac8e188122e6a3df78eae64f6a4cb41a182f32','hotel-details-v1.php'=>'a8eb0b30b4033e7ee8faa6645db2f25a70d8d78c'];
    foreach($pins as $name=>$blob){$path=$root.'/'.$name;content_require(is_file($path)&&!is_link($path)&&realpath($path)===$path&&filesize($path)<200000,'source_path_invalid');$raw=file_get_contents($path);content_require(is_string($raw)&&hash('sha1','blob '.strlen($raw)."\0".$raw)===$blob,'source_changed');}
    require_once $root.'/db-v1.php';require_once $root.'/local-tv-catalog-v1.php';
    $pdo=v2_data_db();content_require($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql','database_driver_changed');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    $ids=array_keys($requests);$read=(new LocalTvCatalogV1($pdo))->read($ids);$local=[];
    foreach($read['items'] as $profile){content_require(isset($requests[$profile['id']]),'reader_identity_outside_scope');$local[$profile['id']]=$profile;}
    $q=$pdo->prepare('SELECT hotel_id,status,source_hash,fetched_at,raw_json FROM catalog_hotel_details WHERE hotel_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$retained=[];
    while($row=$q->fetch(PDO::FETCH_ASSOC)){
        $id=(int)$row['hotel_id'];content_require(isset($requests[$id]),'retained_identity_outside_scope');
        $e=['hotel_id'=>$id,'status'=>$row['status'],'fetched_at'=>$row['fetched_at'],'source_sha256'=>$row['source_hash'],'valid_source'=>false];
        try{
            content_require($row['status']==='success'&&is_string($row['raw_json'])&&strlen($row['raw_json'])<=1048576&&is_string($row['source_hash'])&&hash_equals($row['source_hash'],hash('sha256',$row['raw_json'])),'retained_source_not_verified');
            $payload=json_decode($row['raw_json'],true,128,JSON_THROW_ON_ERROR);content_require(is_array($payload),'retained_json_invalid');
            $normal=LocalTvCatalogV1::normalize($payload,$id);$e['normalized']=$normal;$e['valid_source']=true;
        }catch(Throwable $e0){$e['reason']=preg_match('/\A[a-z_]+\z/D',$e0->getMessage())?$e0->getMessage():'retained_source_invalid';}
        $retained[$id]=$e;
    }
    $pdo->rollBack();$out['source_blobs']=$pins;$out['requested_ids']=$ids;$out['local_missing_ids']=$read['missingIds'];$out['rows']=[];
    foreach($requests as $id=>$request){$p=$local[$id]??null;$s=$retained[$id]??null;$out['rows'][]=$request+['local_profile'=>$p,'local_summary'=>$p===null?null:content_summary($p),'retained_source'=>$s,'native_admission'=>false,'publication_allowed'=>false];}
    $out['summary']=['requested'=>96,'local_found'=>count($local),'local_missing'=>count($read['missingIds']),'retained_found'=>count($retained),'retained_valid'=>count(array_filter($retained,static fn($x)=>$x['valid_source'])),'local_with_description'=>count(array_filter($local,static fn($x)=>content_summary($x)['description_present'])),'local_with_three_photo_urls'=>count(array_filter($local,static fn($x)=>content_summary($x)['image_urls']>=3))];
    $out['ok']=true;
}catch(Throwable $e){if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();$out['error']=preg_match('/\A[a-z_]+\z/D',$e->getMessage())?$e->getMessage():'bounded_content_read_failed';}
$encoded=json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
if(strlen($encoded)>12582912){$encoded=json_encode(['ok'=>false,'error'=>'content_output_bound','writes'=>0,'supplier_calls'=>0]);$out['ok']=false;}
echo $encoded,PHP_EOL;exit($out['ok']?0:2);
