"""Offline compiler only. metadata -> journal-metadata.php; read -> read.php.
Run metadata PHP only in authorized read-only workflow after W13 terminal.
Then pass resulting journal-metadata.json to `read` to bind the unchanged helper.
No SSH/provider executions in this generator. --ops points to carrier operations.
"""
import argparse,base64,hashlib,json,re
from pathlib import Path
ROOT=Path(__file__).resolve().parent
APP='bd8e248f7138ea75cb9fda2dcf44beb631c633a5'
READ='commercial-w11-approved10-caption-read-20261118-v1'
META='commercial-w11-approved10-journal-metadata-20261118-v1'
# PINS_LITERAL
PINS={'integrations/ChannelPostApiTransport.php': '300634d77cb9fddf51adf9869974f956cca6e3f9', 'migrations/033_channel_post_context.sql': '0530458030a4bfe6b6cc55d1e5e441745c29b947', 'migrations/036_channel_post_member_delivery.sql': '1bda463e52b33130ad12752ddef4ee5c92699c05', 'migrations/040_channel_post_reconciliation.sql': '0f24b838eacd45333c5ade9a2991d060488c6035', 'services/ChannelPostAdminService.php': 'b80eefc801054bfe6fdb58ec345ecdf687b2e89a', 'services/ChannelPostAutomationService.php': 'c6386e155d42bc83970edc95f35b3ebeb5140533', 'services/ChannelPostButtonTransport.php': '7b6711f1de63000346d8e5789f2dbb7f725535b8', 'services/ChannelPostEntryService.php': '1982ff7368763edf8b7238c3142e3cc28579e991', 'services/ChannelPostMaxAlbumRecovery.php': 'de266cd068587cc3b2ff83f308a966ea66887a04', 'services/ChannelPostMediaService.php': 'f794efd0f6f24df916e18909ff6281b0bc928433', 'services/ChannelPostMemberDeliveryService.php': '20c1d7984898b2a5996a5f9e20e4d2d8c20b4778', 'services/ChannelPostReconciliationService.php': '1fa260e9720a8421227ecbe740bfbe33a42e398d', 'services/ChannelPostReconciliationState.php': 'd9583ceca80d1424a44ade3561c21282a23a52f0', 'services/ChannelPostRegistryService.php': 'cf42a44257d1a58d1ff516678615cf9d01f45b55', 'services/ChannelPostRuntime.php': '2f1dd9b77474fdbaa92f0402f3b0aa170c595474', 'services/ChannelPostTelegramAlbum.php': 'faa1a14bc279a4af33936282126bf0d46e343040', 'services/ChannelPostWebhookConfigService.php': 'd5fdcb056088f1e3a541b4046325d97eef6417d9', 'services/ConversationDb.php': 'bb6281faf55aca7beeae7f8a57389d19751936f7', 'services/MaxTlsConfig.php': '032c0211ee3f39c6f0d27c669abd5b467e321dea', 'services/MaxTransport.php': '2cb4f8786b4704a8e62a3a9c40dc2cc73dad6159', 'services/ProjectConfig.php': '63c17c8103412f8cff8cdcc4bbb062e744106af9', 'services/ProjectConfiguration.php': '07019d473c588e3386ace42977db93092e8112e3'}
# SOURCE_HASHES_LITERAL
SOURCE_HASHES={'commercial-caption-review73-20261113-read-scope.php': '5158f1e4157984cce98acc085329a0227f6b78c93829bd5984d247e8b629c95e', 'commercial-w9-78929-price-journal-20261118.php': '859ba9d5fde7fa88e3c019a82d0550da22efcbbd66096e2f98e11ed4a3b62263'}
def raw(x):return (json.dumps(x,ensure_ascii=False,separators=(',',':'))+'\n').encode()
def sha(x):return hashlib.sha256(x).hexdigest()
def bind(s,defs):
 assert s.count('declare(strict_types=1);')==1
 declarations='\n'.join('define('+json.dumps(k)+','+json.dumps(v,ensure_ascii=False)+');' for k,v in defs.items())
 return s.replace('declare(strict_types=1);','declare(strict_types=1);\n'+declarations,1)
def pin_surface(s):
 old=dict(re.findall(r'"([^"\n]+)"\s*=>\s*"([a-f0-9]{40})"',s))
 assert set(old)==set(PINS) and len(PINS)==22
 for path,previous in old.items():
  expression=r'("'+re.escape(path)+r'"\s*=>\s*")'+previous+'"'
  s,n=re.subn(expression,lambda m:m[1]+PINS[path]+'"',s);assert n==1
 return s

def source(ops,name):
 b=(ops/name).read_bytes();assert sha(b)==SOURCE_HASHES[name],name+' template changed';return b.decode()

def scope():
 approved=json.loads((ROOT/'approved-texts.json').read_text());bound=json.loads((ROOT/'bound-w11-posts.json').read_text())
 assert len(approved)==10 and len(bound)==20
 rows=[]
 for item in approved:
  pair={p['platform']:p for p in bound if p['post_url'] in [item['max_url'],item['telegram_url']]}
  assert set(pair)=={'max','telegram'} and pair['max']['hotel_id']==pair['telegram']['hotel_id']
  assert all(p['body_photo_button_verified'] and p['state']=='sent' for p in pair.values())
  rows.append(dict(hotel_id=pair['max']['hotel_id'],max_publication_id=pair['max']['publication_id'],max_url=item['max_url'],telegram_publication_id=pair['telegram']['publication_id'],telegram_message_id=int(pair['telegram']['message_id']),telegram_url=item['telegram_url']))
 assert [r['hotel_id'] for r in rows]==[7899,159358,81690,129941,134103,104056,772,4355,2822,8033]
 return {'operation':READ,'rows':rows}

METADATA_BODY=r'''
$out=['operation'=>JOURNAL_METADATA_OPERATION,'ok'=>false,'installed_sha'=>EXPECTED_INSTALLED_SHA,'post_write_attempts'=>0,'provider_calls'=>0,'db_writes'=>0];$lock=null;
try {
 requireAudit(PHP_SAPI==='cli','cli_only');installedGuard();acceptedSurface();
 $path='/var/www/anytoour/data/channel-post-operations/monitor-price-after-collection-v1.jsonl';
 requireAudit(is_file($path)&&!is_link($path)&&realpath($path)===$path,'monitor_journal_unavailable');
 $lock=fopen($path,'r');requireAudit(is_resource($lock)&&flock($lock,LOCK_SH|LOCK_NB),'monitor_already_running');
 $keys=array_flip(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']);
 $stat=fstat($lock);clearstatcache(true,$path);$pathStat=lstat($path);
 requireAudit(is_array($stat)&&is_array($pathStat)&&($stat['mode']&0170000)===0100000&&array_intersect_key($stat,$keys)===array_intersect_key($pathStat,$keys),'monitor_journal_identity_changed');
 requireAudit($stat['size']>0&&$stat['size']<=2147483648,'monitor_journal_size');
 $hash=hash_init('sha256');$bytes=0;$lines=0;$last='';
 while(($line=fgets($lock,8388609))!==false){
  requireAudit(str_ends_with($line,"\n")&&trim($line)!=='','monitor_journal_record_incomplete');
  $bytes+=strlen($line);++$lines;requireAudit($bytes<=$stat['size'],'monitor_journal_grew');hash_update($hash,$line);$last=$line;
 }
 clearstatcache(true,$path);$after=fstat($lock);$pathAfter=lstat($path);
 requireAudit(feof($lock)&&$bytes===$stat['size']&&array_intersect_key($stat,$keys)===array_intersect_key($after,$keys)&&array_intersect_key($stat,$keys)===array_intersect_key($pathAfter,$keys)&&!is_link($path)&&realpath($path)===$path,'monitor_journal_changed');
 $latest=json_decode($last,true,64,JSON_THROW_ON_ERROR);
 requireAudit(is_array($latest)&&array_key_exists('pending',$latest)&&$latest['pending']===null,'monitor_pending_operation_stop');
 $cycle=$latest['nightly_cycle']??null;
 requireAudit(is_array($cycle)&&is_string($cycle['collector_run_id']??null)&&is_string($cycle['state']??null),'monitor_cycle_missing');
 requireAudit(!str_contains(strtolower($cycle['state']),'unknown'),'monitor_unknown_operation_stop');
 $out['journal_pin']=['sha256'=>hash_final($hash),'bytes'=>$bytes,'lines'=>$lines,'collector_run_id'=>$cycle['collector_run_id'],'cycle_state'=>$cycle['state']];
 $out['observed_at_utc']=gmdate('c');$out['monitor_pending']=false;$out['ok']=true;
} catch(Throwable $e) { $msg=$e->getMessage();$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$msg)?$msg:'journal_metadata_read_failed'; }
if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo jsonValue($out),PHP_EOL;exit($out['ok']?0:2);
'''

def compile_metadata(ops,out):
 s=source(ops,'commercial-caption-review73-20261113-read-scope.php')
 s=s[:s.index('function photoSignature(')]
 s=pin_surface(s)+METADATA_BODY
 (out/'journal-metadata.php').write_text(bind(s,{'EXPECTED_INSTALLED_SHA':APP,'JOURNAL_METADATA_OPERATION':META}))

def compile_read(ops,out,journal):
 d=json.loads(journal.read_bytes())
 assert d['ok'] is True and d['operation']==META and d['installed_sha']==APP and d['monitor_pending'] is False
 assert all(d[k]==0 for k in ['post_write_attempts','provider_calls','db_writes'])
 pin=d['journal_pin'];assert re.fullmatch('[a-f0-9]{64}',pin['sha256']) and 0<pin['bytes']<=2147483648 and pin['lines']>0
 assert re.fullmatch('[0-9]+',pin['collector_run_id']) and isinstance(pin['cycle_state'],str) and 'unknown' not in pin['cycle_state'].lower()
 s=source(ops,'commercial-caption-review73-20261113-read-scope.php');s=pin_surface(s)
 s=s.replace('commercial-caption-review73-20261113-read-v1',READ).replace("count($scope['rows']??[])===73","count($scope['rows']??[])===10").replace("count($out['posts'])===146","count($out['posts'])===20").replace("$out['provider_calls']===73","$out['provider_calls']===10")
 sc=scope();s,n=re.subn(r"array_column\(\$scope\['rows'\],'hotel_id'\)===\[[0-9,]+\]","array_column($scope['rows'],'hotel_id')==="+json.dumps([r['hotel_id'] for r in sc['rows']],separators=(',',':')),s);assert n==1
 begin='    $monitorRaw=stream_get_contents($monitorLock);';end="$out['monitor_journal_sha256']=hash('sha256',$monitorRaw);"
 a=s.index(begin);b=s.index(end,a)+len(end)
 s=s[:a]+"    $monitorProof=verifiedCaptionPriceJournal($monitorPath,$monitorLock,json_decode(CAPTION_PRICE_PIN,true,16,JSON_THROW_ON_ERROR));\n    $out['monitor_journal_sha256']=$monitorProof['sha256'];\n    $out['journal_pin']=json_decode(CAPTION_PRICE_PIN,true,16,JSON_THROW_ON_ERROR);"+s[b:]
 helper=source(ops,'commercial-w9-78929-price-journal-20261118.php');header='<?php\ndeclare(strict_types=1);\n';assert helper.startswith(header)
 s=s.replace('declare(strict_types=1);','declare(strict_types=1);\n'+helper[len(header):],1)
 sb=raw(sc)
 s=bind(s,{'EXPECTED_INSTALLED_SHA':APP,'APPROVED_MANIFEST_SHA256':sha(sb),'MANIFEST_BASE64':base64.b64encode(sb).decode(),'CAPTION_PRICE_PIN':json.dumps(pin,separators=(',',':'))})
 (out/'read.php').write_text(s);(out/'read-scope.json').write_bytes(sb)

if __name__=='__main__':
 p=argparse.ArgumentParser(description=__doc__);p.add_argument('stage',choices=['metadata','read']);p.add_argument('--ops',type=Path,default=Path('/workspace/shared/content-wave11-work/tools/direct_demand/operations'));p.add_argument('--out',type=Path,default=ROOT/'read-stage');p.add_argument('--journal-meta',type=Path,default=ROOT/'read-stage/journal-metadata.json');a=p.parse_args();a.out.mkdir(parents=True,exist_ok=True)
 if a.stage=='metadata':compile_metadata(a.ops,a.out)
 else:compile_read(a.ops,a.out,a.journal_meta)
 print(json.dumps({'state':'OFFLINE_COMPILED_NOT_EXECUTED','stage':a.stage,'output':str(a.out)}))
