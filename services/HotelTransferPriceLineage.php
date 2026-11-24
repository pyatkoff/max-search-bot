<?php
declare(strict_types=1);

/**
 * Source-only candidate for future explicitly approved, unstarted TG transfers.
 * NOT a runtime admission, reservation or writer. The future owner must read
 * every intervening registry version and original journal under the existing
 * common lock, authenticate the installed PRICE consumer/renderer, verify live
 * public/registry identity, absence of any claim/operation and target CAS.
 * This pure validator never converts an old v1 plan or authorizes an APPLY.
 */
require_once __DIR__.'/../integrations/ChannelPostIncomingAdapter.php';
require_once __DIR__.'/../tools/direct_demand/operations/monitor-price-hook-contract.php';

final class HotelTransferPriceLineage
{
    private static function need(bool $ok, string $reason): void
    {
        if (!$ok) throw new RuntimeException('transfer_intent_v2_'.$reason);
    }

    private static function sha(string $bytes): string { return hash('sha256', $bytes); }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    /** A normalized historical registry version, not a public-page projection. */
    private static function version(array $v, array $row, array $intent): array
    {
        $s = $v['snapshot'] ?? [];
        $media = $s['attachments'] ?? [];
        $m = $media[0] ?? [];
        self::need(($v['project_key']??null)==='anytour' && ($v['platform']??null)==='telegram'
            && ($v['bot_key']??null)==='anyru_bot' && ($v['channel_id']??null)==='-1002262666616'
            && ($v['message_id']??null)===$row['source_message_id']
            && ($v['publication_id']??null)===$row['source_publication_id']
            && is_int($v['version_id']??null) && $v['version_id']>0
            && is_int($v['version_number']??null) && $v['version_number']>0
            && ($v['state']??null)==='sent'
            && array_key_exists('claim_token',$v) && $v['claim_token']===null
            && array_key_exists('claimed_at',$v) && $v['claimed_at']===null
            && ($v['start_payload']??null)===$intent['source_start_payload']
            && ($s['post_url']??null)==='https://t.me/any_toour/'.$row['source_message_id']
            && ($s['published_at']??null)===$intent['source_published_at']
            && ($s['format']??null)==='native' && ($s['metadata']['capability']??null)==='supported'
            && empty($s['metadata']['media_group_id'])
            && (string)($s['metadata']['hotel_catalog_id']??'')===(string)$row['hotel_id']
            && ($s['metadata']['link']??null)===$intent['source_link']
            && ($s['metadata']['logical_id']??null)===$intent['source_logical_id']
            && is_string($s['text']??null) && count($media)===1
            && ($m['type']??null)==='image' && ($m['provider']??null)==='telegram'
            && is_string($m['telegram_file_id']??null) && is_string($m['file_unique_id']??null)
            && self::sha($m['telegram_file_id'])===$row['source_file_id_sha256']
            && self::sha($m['file_unique_id'])===$row['source_file_unique_id_sha256']
            && self::onlyButton($s,'https://t.me/anyru_bot?start='.$intent['source_start_payload'])
            && ($v['content_hash']??null)===ChannelPostIncomingAdapter::contentFingerprint($s),
            'source_identity_or_content');
        return $s;
    }

    private static function onlyButton(array $s,string $url):bool
    {
        $keyboard=$s['keyboard']??null;
        if(!is_array($keyboard)||!array_is_list($keyboard)||count($keyboard)!==1
            ||!is_array($keyboard[0])||!array_is_list($keyboard[0])||count($keyboard[0])!==1
            ||!is_array($keyboard[0][0])) return false;
        $button=$keyboard[0][0];
        if(array_key_exists('type',$button)){
            if($button['type']!=='link')return false;
            unset($button['type']);
        }
        ksort($button);
        return $button===['text'=>'✈️ Узнать стоимость тура','url'=>$url];
    }

    /** Require the original durable transition sequence, not a terminal line alone.
     * All stages refer to the same plan, request timestamp and saved response.
     * A confirmed journal can be read; it cannot be used to retry an edit.
     */
    private static function history(string $raw,array $last,string $key):void
    {
        $final=$last['posts'][$key];
        $lines=explode("\n",substr($raw,0,-1));
        self::need(count($lines)>=5 && count($lines)<=1000,'journal_stage_count');
        $stage=0;
        foreach($lines as $n=>$line){
            $e=json_decode($line,true,64,JSON_THROW_ON_ERROR);
            self::need(is_array($e) && ($e['operation']??null)===$last['operation']
                && ($e['manifest_hash']??null)===$last['manifest_hash']
                && ($e['monitor_snapshot']??null)===$last['monitor_snapshot']
                && ($e['feed_binding']??null)===$last['feed_binding']
                && !array_key_exists('terminal',$e)
                && (($e['complete']??false)===($n===count($lines)-1))
                && is_array($e['posts']??null),'journal_history');
            $post=$e['posts'][$key]??null;
            if($post===null){
                self::need($stage===0 && $n<count($lines)-1,'journal_stage_regressed');
                continue;
            }
            self::need(is_array($post) && ($post['plan']??null)===$final['plan']
                && ($post['edit_request_start_ms']??null)===$final['edit_request_start_ms'],'journal_request_changed');
            switch($post['state']??null){
                case 'edit_started':
                    self::need($stage===0 && !array_key_exists('provider_response',$post)
                        && !array_key_exists('receipt',$post),'journal_repeated_start');
                    $stage=1;break;
                case 'provider_response_recorded':
                    self::need(in_array($stage,[1,2],true) && ($post['provider_response']??null)===$final['provider_response']
                        && !array_key_exists('receipt',$post),'journal_response_changed');
                    $stage=2;break;
                case 'confirmed':
                    self::need(in_array($stage,[2,3],true) && $post===$final,'journal_confirmation_changed');
                    $stage=3;break;
                default: self::need(false,'journal_stage_unknown');
            }
        }
        self::need($stage===3,'journal_missing_confirmation');
    }

    /** Check the exact native text_link span, not a stripped caption comparison. */
    private static function entities(string $text, string $url): array
    {
        self::need(preg_match_all('/^💰 (От (?:[0-9]{1,3}(?: [0-9]{3})*|[0-9]+)(?:,[0-9]{1,2})? ₽) за двоих$/mu',
            $text,$matches,PREG_OFFSET_CAPTURE)===1 && substr_count($text,'💰')===1,'price_link_span');
        [$label,$offset]=$matches[1][0];
        return [['type'=>'text_link','offset'=>strlen(mb_convert_encoding(substr($text,0,$offset),'UTF-16LE','UTF-8'))/2,
            'length'=>strlen(mb_convert_encoding($label,'UTF-16LE','UTF-8'))/2,'url'=>$url]];
    }

    private static function step(array $intent,array $row,array $before,array $after,array $s,string $raw): array
    {
        self::need(strlen($raw)<=10000000 && $raw!=='' && str_ends_with($raw,"\n"),'journal_incomplete');
        $record=$s['metadata']['monitor_price_record_id']??null;
        self::need(is_string($record) && preg_match('/\A[a-f0-9]{64}\z/D',$record)===1
            && $after['version_id']>$before['version_id']
            && $after['version_number']===$before['version_number']+1,'version_or_record');
        $op='commercial-monitor-price-h'.$row['hotel_id'].'-'.$record;
        $journal=monitor_hook_parse_journal($raw,$op);
        self::need(($journal['complete']??null)===true && !isset($journal['terminal'])
            && ($journal['feed_binding']??null)===$intent['feed_binding']
            && is_string($journal['manifest_hash']??null)
            && preg_match('/\A[a-f0-9]{64}\z/D',$journal['manifest_hash'])===1,'journal_binding');
        $post=null;$key=null;
        foreach($journal['posts']??[] as $k=>$item) {
            if (($item['receipt']['platform']??null)==='telegram') {
                self::need($post===null && is_string($k) && str_starts_with($k,'telegram:'),'duplicate_telegram_receipt');
                $post=$item;$key=$k;
            }
        }
        self::need(is_string($key) && is_array($post) && is_array($post['plan']??null)
            && is_array($post['provider_response']??null),'journal_telegram_missing');
        self::history($raw,$journal,$key);
        $p=$post['plan']??[]; $r=$post['receipt']??[];
        $ms=$post['edit_request_start_ms']??null;
        self::need(is_int($ms)&&$ms>0,'request_time');
        // Historical evidence is validated at the original provider request time.
        // It never makes that price publishable today.
        $at=intdiv($ms,1000);
        $price=monitor_price_observation($journal['monitor_snapshot'],$row['hotel_id'],$at,true);
        $binding=$intent['feed_binding']+['operation'=>$op,'manifest_hash'=>$journal['manifest_hash']];
        monitor_price_confirmed_journal($journal,$binding,$at);
        $text=monitor_hook_draft($intent['source_price_template'],$price);
        $url='https://t.me/anyru_bot?start='.$intent['source_start_payload'];
        $entities=self::entities($text,$url);
        // Keep exact integral UTF-16 counts canonical for strict comparison.
        $entities[0]['offset']=(int)$entities[0]['offset'];
        $entities[0]['length']=(int)$entities[0]['length'];
        $response=$post['provider_response']??[];
        $native=$response['data']['result']??[];
        $nativeSnapshot=ChannelPostIncomingAdapter::telegramSnapshot($native);
        self::need($record===$price['record_id'] && ($s['metadata']['monitor_price_basis']??null)===$price['price_basis']
            && ($s['metadata']['monitor_price_observed_at_relative']??null)===$price['observed_at']
            && ($post['state']??null)==='confirmed'
            && ($p['platform']??null)==='telegram' && ($p['publication_id']??null)===$row['source_publication_id']
            && ($p['old_version_id']??null)===$before['version_id'] && ($p['message_id']??null)===$row['source_message_id']
            && ($p['logical_id']??null)===$intent['source_logical_id']
            && ($p['post_url']??null)===$s['post_url'] && ($p['token']??null)===$intent['source_start_payload']
            && ($p['text']??null)===$text && ($p['monitor_price']??null)===$price && ($p['update_text']??null)===true
            && ($p['price_link']['url']??null)===$url && ($p['price_link']['telegram_entities']??null)===$entities
            && ($r['channel_id']??null)==='-1002262666616' && ($r['publication_id']??null)===$row['source_publication_id']
            && ($r['previous_version_id']??null)===$before['version_id'] && ($r['version_id']??null)===$after['version_id']
            && ($r['message_id']??null)===$row['source_message_id'] && ($r['post_url']??null)===$s['post_url']
            && ($r['logical_id']??null)===$intent['source_logical_id']
            && ($r['photo_signature']??null)==='telegram:'.$s['attachments'][0]['file_unique_id']
            && ($p['photo_signature']??null)===$r['photo_signature']
            && ($r['button_url']??null)===$url && ($r['button_text']??null)==='✈️ Узнать стоимость тура'
            && ($r['price_link_verified']??null)===true && ($r['price_link_url']??null)===$url
            && ($r['content_updated']??null)===true && ($r['text']??null)===$text && ($r['text_sha256']??null)===self::sha($text)
            && $s['text']===$text && ($s['markup']??null)===$entities
            && ($response['http']??null)===200 && ($response['data']['ok']??null)===true
            && (string)($native['chat']['id']??'')==='-1002262666616'
            && ($native['chat']['type']??null)==='channel' && ($native['chat']['username']??null)==='any_toour'
            && (string)($native['message_id']??'')===$row['source_message_id']
            && is_array($nativeSnapshot) && $nativeSnapshot['text']===$text && $nativeSnapshot['markup']===$entities
            && $nativeSnapshot['attachments']===$s['attachments']
            && $nativeSnapshot['post_url']===$s['post_url'] && $nativeSnapshot['published_at']===$s['published_at']
            && self::onlyButton($nativeSnapshot,$url),
            'price_transition_unproven');
        return ['previous_version_id'=>$before['version_id'],'version_id'=>$after['version_id'],
            'record_id'=>$record,'journal_sha256'=>self::sha($raw),'content_hash'=>$after['content_hash']];
    }

    /**
     * Immutable new v2 intent bytes must be approved separately; versions include
     * the exact approved baseline and EVERY later version up to the live one.
     * Journals are complete original child receipts in the same order. No
     * synthesis from monitor_price_record_id or skip over unproved observers.
     */
    public static function candidate(string $intentBytes,string $approvedDigest,array $versions,array $journals): array
    {
        self::need(strlen($intentBytes)<=64000 && self::sha($intentBytes)===$approvedDigest,'approval_changed');
        $i=json_decode($intentBytes,true,32,JSON_THROW_ON_ERROR);
        self::need(is_array($i) && array_keys($i)===['schema','price_policy','row','source_price_template',
            'source_start_payload','source_published_at','source_logical_id','source_link','feed_binding','target_public_buttons']
            && $i['schema']==='telegram_hotel_transfer_intent_v2'
            && $i['price_policy']==='omit_previous_price_keep_hotel_calculation_button'
            && is_array($i['row']) && is_string($i['source_price_template']) && $i['source_price_template']!==''
            && mb_check_encoding($i['source_price_template'],'UTF-8') && strlen($i['source_price_template'])<=8000
            && is_string($i['source_start_payload']) && preg_match('/\Ap_[A-Za-z0-9_-]{8,60}\z/D',$i['source_start_payload'])===1
            && is_string($i['source_published_at']) && $i['source_published_at']!==''
            && is_string($i['source_logical_id']) && $i['source_logical_id']!==''
            && ($i['source_link']===null || is_string($i['source_link']))
            && self::buttonPins($i['target_public_buttons'])!==null
            && is_array($i['feed_binding']) && !array_key_exists('operation',$i['feed_binding'])
            && !array_key_exists('manifest_hash',$i['feed_binding']),
            'intent_schema');
        $row=$i['row'];
        $keys=['hotel_id','source_message_id','source_publication_id','source_version_id','source_content_hash',
            'source_caption_sha256','source_file_id_sha256','source_file_unique_id_sha256','target_message_id',
            'target_published_unixtime','target_caption_sha256','target_photo_marker_sha256','target_config_version',
            'next_caption','next_caption_sha256','button_text','bot_url','price_template','price_record_id','source_photo_marker_sha256'];
        $actual=array_keys($row);sort($actual);sort($keys);
        self::need($actual===$keys,'intent_row_keys');
        self::need(is_int($row['hotel_id']??null)&&$row['hotel_id']>0
            && is_int($row['source_publication_id']??null)&&$row['source_publication_id']>0
            && is_int($row['source_version_id']??null)&&$row['source_version_id']>0
            && is_string($row['source_message_id']??null)&&preg_match('/\A[1-9][0-9]*\z/D',$row['source_message_id'])===1
            && is_string($row['target_message_id']??null)&&preg_match('/\A[1-9][0-9]*\z/D',$row['target_message_id'])===1
            && is_int($row['target_published_unixtime']??null)&&$row['target_published_unixtime']>0
            && ($row['price_template']??null)==='' && array_key_exists('price_record_id',$row)&&$row['price_record_id']===null
            && is_string($row['next_caption']??null)&&$row['next_caption']!==''&&self::sha($row['next_caption'])===($row['next_caption_sha256']??null)
            && mb_check_encoding($row['next_caption'],'UTF-8')&&strlen(mb_convert_encoding($row['next_caption'],'UTF-16LE','UTF-8'))<=2048
            && ($row['target_config_version']??null)===2 && ($row['button_text']??null)==='✈️ Рассчитать на мои даты'
            && ($row['bot_url']??null)==='https://t.me/anyru_bot'
            && ($i['feed_binding']['tourvisor_hotel_id']??null)===$row['hotel_id']
            && ($i['feed_binding']['posts']['telegram']??null)==='https://t.me/any_toour/'.$row['source_message_id'],
            'intent_row');
        foreach(['source_content_hash','source_caption_sha256','source_file_id_sha256','source_file_unique_id_sha256',
            'source_photo_marker_sha256','target_caption_sha256','target_photo_marker_sha256'] as $key)
            self::need(is_string($row[$key]??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$row[$key])===1,'intent_digest');
        self::need(array_is_list($versions)&&array_is_list($journals)&&count($versions)>=1&&count($versions)<=129
            && count($journals)===count($versions)-1,'lineage_length');
        $s=self::version($versions[0],$row,$i);
        self::need($versions[0]['version_id']===$row['source_version_id']
            && $versions[0]['content_hash']===$row['source_content_hash']
            && self::sha($s['text'])===$row['source_caption_sha256'],'approved_baseline');
        $proof=[]; $seen=[];
        $baselineRecord=$s['metadata']['monitor_price_record_id']??null;
        if($baselineRecord!==null) {
            self::need(is_string($baselineRecord)&&preg_match('/\A[a-f0-9]{64}\z/D',$baselineRecord)===1,'baseline_record');
            $seen[$baselineRecord]=true;
        }
        foreach($journals as $k=>$raw) {
            self::need(is_string($raw),'journal_type');
            $s=self::version($versions[$k+1],$row,$i);
            $step=self::step($i,$row,$versions[$k],$versions[$k+1],$s,$raw);
            self::need(!isset($seen[$step['record_id']]),'duplicate_price_record');
            $seen[$step['record_id']]=true; $proof[]=$step;
        }
        $last=$versions[count($versions)-1];
        self::need($last['state']==='sent','source_not_current_sent');
        $candidate=$row;
        $candidate['source_version_id']=$last['version_id'];
        $candidate['source_content_hash']=$last['content_hash'];
        $candidate['source_caption_sha256']=self::sha($s['text']);
        $execution=['schema'=>'telegram_hotel_transfer_execution_candidate_v2',
            'intent_digest'=>$approvedDigest,'row'=>$candidate,'price_lineage'=>$proof];
        return $execution+['execution_digest'=>self::sha(self::json($execution))];
    }

    /** Only stable, approved button fields. Never trust an omitted keyboard. */
    private static function buttonPins(mixed $buttons):?array
    {
        if(!is_array($buttons)||!array_is_list($buttons)||count($buttons)>20)return null;
        $pins=[];
        foreach($buttons as $button){
            if(!is_array($button)||!is_string($button['text']??null)||$button['text']===''
                ||!is_string($button['url_sha256']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$button['url_sha256'])!==1)
                return null;
            $pins[]=['text'=>$button['text'],'url_sha256'=>$button['url_sha256']];
        }
        return $pins;
    }

    /** Bind the existing public parser's exact observations to approved intent.
     * The caller must actually read the two exact URLs under the existing lease.
     * Raw HTML hashes record the observation, not a future byte-for-byte web
     * page requirement. Stable identity/caption/photo/date/button are the CAS.
     */
    public static function publicPreimages(string $bytes,string $approvedDigest,array $row,array $source,array $target):array
    {
        self::need(strlen($bytes)<=64000&&self::sha($bytes)===$approvedDigest,'public_approval_changed');
        $intent=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
        $approved=$intent['row']??[];
        $fixed=$row;
        foreach(['source_version_id','source_content_hash','source_caption_sha256'] as $key)
            $fixed[$key]=$approved[$key]??null;
        self::need(($intent['schema']??null)==='telegram_hotel_transfer_intent_v2'&&$fixed===$approved
            &&is_int($row['source_version_id']??null)&&$row['source_version_id']>=$approved['source_version_id'],
            'public_intent_changed');
        $url='https://t.me/anyru_bot?start='.$intent['source_start_payload'];
        $sourcePins=self::buttonPins($source['buttons']??null);
        $targetPins=self::buttonPins($target['buttons']??null);
        self::need($sourcePins===[['text'=>'✈️ Узнать стоимость тура','url_sha256'=>self::sha($url)]]
            &&($source['buttons'][0]['hotel_url']??null)===$url
            &&$targetPins!==null&&$targetPins===$intent['target_public_buttons'],'public_keyboard_changed');
        foreach([['source',$source,'https://t.me/any_toour/'.$row['source_message_id'],$row['source_caption_sha256'],
            $row['source_photo_marker_sha256']],['target',$target,'https://t.me/anytour/'.$row['target_message_id'],
            $row['target_caption_sha256'],$row['target_photo_marker_sha256']]] as [$kind,$public,$expectedUrl,$captionHash,$photoHash]){
            self::need(($public['url']??null)===$expectedUrl&&($public['single_photo']??null)===true
                &&is_string($public['caption']??null)&&self::sha($public['caption'])===$captionHash
                &&($public['caption_sha256']??null)===$captionHash&&($public['photo_marker_sha256']??null)===$photoHash
                &&is_int($public['published_unixtime']??null)&&$public['published_unixtime']>0
                &&is_string($public['html_sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$public['html_sha256'])===1,
                'public_'.$kind.'_changed');
        }
        self::need(gmdate('Y-m-d H:i:s',$source['published_unixtime'])===$intent['source_published_at']
            &&$target['published_unixtime']===$row['target_published_unixtime'],'public_date_changed');
        $project=static fn(array $v,array $buttons):array=>[
            'url'=>$v['url'],'caption_sha256'=>$v['caption_sha256'],'photo_marker_sha256'=>$v['photo_marker_sha256'],
            'published_unixtime'=>$v['published_unixtime'],'buttons'=>$buttons,'observed_html_sha256'=>$v['html_sha256']];
        return ['source'=>$project($source,$sourcePins),'target'=>$project($target,$targetPins)];
    }
}
