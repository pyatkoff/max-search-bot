"""Offline compile only. Requires successful authorized fresh read; never performs I/O to providers."""
import argparse,base64,hashlib,importlib.util,json,re
from pathlib import Path
ROOT=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('read_stage',ROOT/'prepare-read-stage.py');r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
raw=r.raw;sha=r.sha
PREFIX='commercial-w11-approved10-caption-edit-20261118'
EXECUTOR='commercial-caption-review73-20261113-executor.php'
EXECUTOR_SHA='cb30dbd22432224bed30d0e8c0121b58a393ffcbd3263a09614b7d083fa88c2e'
AUTH='user_20261118_182654_approved10_exact_captions'
PRICE=re.compile(r'(?m)^💰 От [^\r\n]+ ₽ за двоих\n✈️ Вылет из Москвы [^\r\n]+\n👥 2 взрослых\n🛏 [^\r\n]+\n🍽 [^\r\n]+\nЦена найдена мониторингом \d{2}\.\d{2}\.\d{4}, \d{2}:\d{2} МСК\. Стоимость и наличие могут измениться\.')
def units(s):return len(s.encode('utf-16-le'))//2

def price_block(x):
 old=x['caption'];record=x['monitor_price_record_id'];hits=list(PRICE.finditer(old))
 if record is None:
  assert not hits and '💰' not in old and 'Цена найдена мониторингом' not in old,'unbound_price_HOLD'
  return '',0
 assert re.fullmatch('[a-f0-9]{64}',record) and len(hits)==1 and old.count('💰')==1,'price_layout_HOLD'
 prior=x['prior_monitor_receipt'];assert prior['complete'] is True and prior['operation']==f"commercial-monitor-price-h{x['hotel_id']}-{record}"
 receipts=[p['receipt'] for p in prior['posts'].values() if p['receipt']['platform']==x['platform']]
 assert len(receipts)==1;p=receipts[0]
 assert p['publication_id']==x['publication_id'] and p['message_id']==x['message_id'] and p['post_url']==x['post_url'] and p['monitor_price']['record_id']==record
 assert sha(p['text'].encode())==p['text_sha256'] and p['body_photo_button_verified'] is True and p['existing_message_reused'] is True
 block=hits[0].group();assert p['text'].count(block)==1 and p['text'].count('💰')==1,'price_lineage_HOLD'
 return block,units(old[:hits[0].start()])

SCOPE=r'''
function captionQualityScope(array $payload): void {
 need(isApprovedCaptionQuality($payload) && ($payload['quality_authorization']??'')===REPAIR_AUTHORIZATION
  && ($payload['quality_cohort_sha256']??'')===REPAIR_COHORT_SHA256
  && ($payload['batch_index']??null)===(int)REPAIR_BATCH_INDEX
  && ($payload['operation']??'')===APPROVED_UPDATE_OPERATION,'approved_exact_scope_changed');
 $approved=json_decode(base64_decode(REPAIR_ROWS_BASE64,true),true,128,JSON_THROW_ON_ERROR);
 need(($payload['rows']??null)===$approved,'exact_approved_rows_changed');
 $pred=json_decode(base64_decode(REPAIR_PREDECESSOR_BASE64,true),true,16,JSON_THROW_ON_ERROR);
 need(($payload['predecessor']??null)===$pred && ($payload['batch_kind']??'')===((int)REPAIR_BATCH_INDEX===0?'canary':'batch'),'exact_predecessor_changed');
 need(count($approved)===((int)REPAIR_BATCH_INDEX===0?4:16),'exact_pair_scope_changed');
}
'''
FORMAT=r'''
/** Scoped approved whole captions only; preserve current price markup in UTF-16 units. */
function captionQualityProviderText(string $platform,string $oldText,string $newText,?array $oldMarkup=null): array {
 $rows=json_decode(base64_decode(REPAIR_ROWS_BASE64,true),true,128,JSON_THROW_ON_ERROR);$matches=[];
 foreach($rows as $row) if($row['platform']===$platform && $row['old_text']===$oldText && $row['new_text']===$newText)$matches[]=$row;
 need(count($matches)===1,'caption_not_exact_allowlist');$row=$matches[0];
 need(mb_check_encoding($newText,'UTF-8')&&!str_contains($newText,"\r")&&!str_contains($newText,"\0")
  && substr_count($newText,'Нажмите кнопку под постом')===1
  && strlen(mb_convert_encoding($newText,'UTF-16LE','UTF-8'))/2<=1024,'approved_caption_invalid');
 $new16=mb_convert_encoding($newText,'UTF-16LE','UTF-8');$old16=mb_convert_encoding($oldText,'UTF-16LE','UTF-8');
 $pos=$platform==='max'?'from':'offset';$headline=explode("\n",$newText,2)[0];
 $adjusted=[['type'=>$platform==='max'?'strong':'bold',$pos=>0,'length'=>intdiv(strlen(mb_convert_encoding($headline,'UTF-16LE','UTF-8')),2)]];
 if($oldMarkup!==null){
  need(array_is_list($oldMarkup)&&count($oldMarkup)<=100,'markup_list_invalid');
  $start=$row['old_price_offset_utf16'];$end=$start+$row['price_length_utf16'];
  foreach($oldMarkup as $span){
   need(is_array($span)&&!array_is_list($span),'markup_span_invalid');shortTitleMarkupFields($span,$platform);
   $a=$span[$pos]??null;$length=$span['length']??null;
   need(is_int($a)&&is_int($length)&&$a>=0&&$length>0&&($a+$length)*2<=strlen($old16),'markup_range_invalid');$b=$a+$length;
   need(shortTitleUtf16Boundary($old16,$a)&&shortTitleUtf16Boundary($old16,$b),'markup_splits_surrogate');
   if($row['price_length_utf16']===0 || $b<=$start || $a>=$end)continue;
   need($a>=$start&&$b<=$end,'price_markup_crosses_boundary');
   $span[$pos]=$row['new_price_offset_utf16']+$a-$start;
   need(shortTitleUtf16Slice($old16,$a,$length)===shortTitleUtf16Slice($new16,$span[$pos],$length),'price_markup_relink_changed');$adjusted[]=$span;
  }
  if($oldText===$newText)$adjusted=$oldMarkup;
 }
 if($platform==='telegram')return ['markup'=>$adjusted,'max_html'=>null];
 usort($adjusted,static fn(array $a,array $b):int=>($a['from']<=>$b['from'])?:($a['length']<=>$b['length']));
 $tags=['strong'=>'b','emphasized'=>'i','underline'=>'u','strikethrough'=>'s','monospaced'=>'code','link'=>'a'];$html='';$cursor=0;
 foreach($adjusted as $span){
  need($span['from']>=$cursor,'max_overlapping_markup_unsupported');
  $html.=htmlspecialchars(shortTitleUtf16Slice($new16,$cursor,$span['from']-$cursor),ENT_QUOTES,'UTF-8');
  $tag=$tags[$span['type']];$attribute=$span['type']==='link'?' href="'.htmlspecialchars($span['url'],ENT_QUOTES,'UTF-8').'"':'';
  $html.='<'.$tag.$attribute.'>'.htmlspecialchars(shortTitleUtf16Slice($new16,$span['from'],$span['length']),ENT_QUOTES,'UTF-8').'</'.$tag.'>';$cursor=$span['from']+$span['length'];
 }
 $html.=htmlspecialchars(shortTitleUtf16Slice($new16,$cursor,intdiv(strlen($new16),2)-$cursor),ENT_QUOTES,'UTF-8');
 return ['markup'=>$adjusted,'max_html'=>$html];
}
'''

def compile_edit(ops,snapshot,journal_meta,out):
 sb=snapshot.read_bytes();d=json.loads(sb);meta=json.loads(journal_meta.read_bytes());pin=d['journal_pin']
 assert d['ok'] is True and d['operation']==r.READ and d['installed_sha']==r.APP and len(d['posts'])==20 and d['post_write_attempts']==0 and d['provider_calls']==10
 assert d['monitor_pending'] is False and d['monitor_journal_sha256']==pin['sha256']
 assert meta['ok'] is True and meta['operation']==r.META and meta['installed_sha']==r.APP and meta['monitor_pending'] is False and meta['journal_pin']==pin
 assert all(meta[k]==0 for k in ['provider_calls','post_write_attempts','db_writes'])
 assert re.fullmatch('[a-f0-9]{64}',pin['sha256']) and 0<pin['bytes']<=2147483648 and pin['lines']>0 and 'unknown' not in pin['cycle_state'].lower()
 approved=json.loads((ROOT/'approved-texts.json').read_bytes());bound=json.loads((ROOT/'bound-w11-posts.json').read_bytes());r.scope()
 by={x['post_url']:x for x in d['posts']};assert len(by)==20
 rows=[]
 for a in approved:
  text=a['text'];assert text.count('Нажмите кнопку под постом')==1 and text.split('\n\n')[-1].startswith('Нажмите кнопку под постом') and '💰' not in text
  for platform in ['telegram','max']:
   url=a[platform+'_url'];x=by[url];original=next(p for p in bound if p['post_url']==url)
   assert all(x[k]==original[k] for k in ['hotel_id','platform','publication_id','message_id','post_url','source_key'])
   assert x['status']=='sent' and x['button_resolves_to_current_post'] is True and x['media_count']==1 and (platform!='max' or x['provider_live_verified'] is True)
   assert sha(x['caption'].encode())==x['caption_sha256'] and x['button_url_sha256']==sha(original['button_url'].encode())
   assert isinstance(x['photo_signature'],str) and re.fullmatch('[a-f0-9]{64}',x['attachments_sha256'])
   price,offset=price_block(x)
   body,cta=text.rsplit('\n\n',1);new=body+'\n\n'+price+'\n\n'+cta if price else text
   assert units(new)<=1024 and new.count('Нажмите кнопку под постом')==1,'caption_length_or_cta_HOLD'
   row={k:x[k] for k in ['hotel_id','platform','publication_id','version_id','message_id','post_url','photo_signature','attachments_sha256','button_url_sha256','config_version','source_key','button_observed_in_snapshot','monitor_price_record_id']}
   row.update(old_text=x['caption'],new_text=new,old_text_sha256=x['caption_sha256'],new_text_sha256=sha(new.encode()),approved_copy_sha256=sha(text.encode()),price_block_sha256=sha(price.encode()),old_price_offset_utf16=offset,new_price_offset_utf16=units(body+'\n\n') if price else 0,price_length_utf16=units(price),same_caption_skip=x['caption']==new)
   rows.append(row)
 assert [x['hotel_id'] for x in rows[::2]]==[7899,159358,81690,129941,134103,104056,772,4355,2822,8033]
 source=(ops/EXECUTOR).read_bytes();assert sha(source)==EXECUTOR_SHA
 predecessor=None;outputs=[]
 for index,(label,part) in enumerate([('canary',rows[:4]),('batch',rows[4:])]):
  op=PREFIX+('-canary-v1' if index==0 else '-batch001-v1');cohort=sha(raw(part))
  manifest=dict(schema_version=1,operation=op,expected_installed_sha=r.APP,update_kind='approved_existing_caption_quality_revision',button_text='✈️ Узнать стоимость тура',throttle_microseconds=3200000,snapshot=dict(operation=r.READ,installed_sha=r.APP,post_count=20,sha256=sha(sb),observed_at_utc=d['observed_at_utc']),quality_authorization=AUTH,quality_cohort_sha256=cohort,batch_index=index,batch_kind='canary' if index==0 else 'batch',predecessor=predecessor,row_count=len(part),rows=part)
  mb=raw(manifest);s=r.pin_surface(source.decode());start=s.index('function captionQualityScope(');end=s.index('function validateManifest(',start);s=s[:start]+SCOPE+'\n'+FORMAT+'\n'+s[end:]
  s=s.replace('commercial-caption-review73-20261113-read-v1',r.READ).replace("($source['post_count'] ?? null) === 146","($source['post_count'] ?? null) === 20")
  a=s.index('    $monitorRaw=stream_get_contents($monitorLock);');terminal="need(array_key_exists('pending',$monitorState) && $monitorState['pending']===null,'monitor_pending_operation_stop');";b=s.index(terminal,a)+len(terminal)
  s=s[:a]+"    $monitorProof=verifiedCaptionPriceJournal($monitorJournal,$monitorLock,json_decode(CAPTION_PRICE_PIN,true,16,JSON_THROW_ON_ERROR));"+s[b:]
  helper=r.source(ops,'commercial-w9-78929-price-journal-20261118.php');header='<?php\ndeclare(strict_types=1);\n';assert helper.startswith(header)
  s=s.replace('declare(strict_types=1);','declare(strict_types=1);\n'+helper[len(header):],1)
  needle="captionQualityProviderText($platform, $row['old_text'], $row['new_text'])";assert s.count(needle)==1;s=s.replace(needle,"captionQualityProviderText($platform, $row['old_text'], $row['new_text'], $plan['old_markup'])")
  # Same-content snapshots retain identity/version/markup and receive a durable verified no-op receipt.
  start=s.index('        $requestStart = (int)floor(');end=s.index("        $p = $registry->publication($plan['publication_id']);",start)
  s=s[:start]+"        if($plan['old_text']!==$plan['new_text']) {\n"+s[start:end]+"        }\n"+s[end:]
  needle="        need(is_array($p) && $p['state'] === 'sent' && $p['platform'] === $platform"
  s=s.replace(needle,"        need($plan['old_text']!==$plan['new_text'] || (is_array($p) && (int)$p['version_id']===$plan['version_id']),'same_caption_version_changed');\n"+needle)
  s=s.replace("$out[$quality ? 'caption_updates' : 'title_updates'] = count($out['posts']);","$out[$quality ? 'caption_updates' : 'title_updates'] = count(array_filter($out['posts'],static fn(array $p):bool=>!$p['same_caption_skipped'])); $out['verified_posts']=count($out['posts']);")
  s=s.replace("'existing_message_reused' => true, 'existing_token_resolves_to_current_version' => true]","'existing_message_reused' => true, 'existing_token_resolves_to_current_version' => true, 'same_caption_skipped'=>$plan['old_text']===$plan['new_text']]")
  needle="        $state['posts'][$key]['state'] = 'confirmed';";s=s.replace(needle,"        if(!isset($state['posts'][$key]))$state['posts'][$key]=['plan'=>$plan];\n"+needle)
  defs={'EXPECTED_INSTALLED_SHA':r.APP,'APPROVED_UPDATE_OPERATION':op,'APPROVED_MANIFEST_SHA256':sha(mb),'MANIFEST_BASE64':base64.b64encode(mb).decode(),'CAPTION_PRICE_PIN':json.dumps(pin,separators=(',',':')),'REPAIR_AUTHORIZATION':AUTH,'REPAIR_COHORT_SHA256':cohort,'REPAIR_BATCH_INDEX':str(index),'REPAIR_ROWS_BASE64':base64.b64encode(raw(part)).decode(),'REPAIR_PREDECESSOR_BASE64':base64.b64encode(raw(predecessor)).decode()}
  s=r.bind(s,defs)
  tests=s[:s.index('$out = [')]+"\n$good=json_decode(base64_decode(MANIFEST_BASE64),true);validateManifest($good);\n"
  for mutation in ["$bad['rows'][0]['hotel_id']++;","$bad['rows'][0]['publication_id']++;","$bad['rows'][0]['new_text'].='x';","$bad['rows'][0]['new_text_sha256']=str_repeat('0',64);","$bad['rows'][0]['price_length_utf16']++;","$bad['predecessor']=['operation'=>'wrong'];","array_pop($bad['rows']);"]:
   tests+="$bad=$good;"+mutation+"$rejected=false;try{validateManifest($bad);}catch(Throwable $e){$rejected=true;}need($rejected,'negative_scope_test_failed');\n"
  tests+="echo 'APPROVED10_EXACT_SCOPE_8_TESTS_PASS',PHP_EOL;\n"
  (out/(label+'-manifest.json')).write_bytes(mb);(out/(label+'-edit.php')).write_text(s);(out/(label+'-scope-tests.php')).write_text(tests)
  outputs.append(dict(label=label,operation=op,manifest_sha256=sha(mb),row_count=len(part),expected_edits=sum(not x['same_caption_skip'] for x in part),hotel_ids=[x['hotel_id'] for x in part[::2]]))
  predecessor={'operation':op,'manifest_sha256':sha(mb)}
 receipt=dict(state='OFFLINE_COMPILED_NOT_EXECUTED',installed_sha=r.APP,snapshot_sha256=sha(sb),journal_pin=pin,approved_texts_sha256=sha((ROOT/'approved-texts.json').read_bytes()),batches=outputs)
 (out/'compile-receipt.json').write_bytes(raw(receipt));print(json.dumps(receipt))
if __name__=='__main__':
 p=argparse.ArgumentParser(description=__doc__);p.add_argument('--ops',type=Path,required=True);p.add_argument('--snapshot',type=Path,required=True);p.add_argument('--journal-meta',type=Path,required=True);p.add_argument('--out',type=Path,required=True);a=p.parse_args();a.out.mkdir(parents=True,exist_ok=True);compile_edit(a.ops,a.snapshot,a.journal_meta,a.out)
