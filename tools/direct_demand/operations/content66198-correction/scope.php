<?php
/** Exact operational binding inserted into the existing once-only caption editor. */
function captionQualityScope(array $payload): void
{
    $approved=json_decode(base64_decode(REPAIR_ROWS_BASE64,true),true,128,JSON_THROW_ON_ERROR);
    need(isApprovedCaptionQuality($payload)
        && ($payload['quality_authorization']??null)===REPAIR_AUTHORIZATION
        && ($payload['quality_cohort_sha256']??null)===REPAIR_COHORT_SHA256
        && ($payload['operation']??null)===APPROVED_UPDATE_OPERATION
        && ($payload['batch_index']??null)===0 && ($payload['batch_kind']??null)==='canary'
        && array_key_exists('predecessor',$payload) && $payload['predecessor']===null
        && ($payload['rows']??null)===$approved && count($approved)===2,
        'waldorf_exact_approved_scope_changed');
    need(array_column($approved,'platform')===['telegram','max'], 'waldorf_exact_pair_changed');
    $stamp=strtotime((string)($payload['snapshot']['observed_at_utc']??''));
    need($stamp!==false && $stamp<=time()+60 && $stamp>=time()-900, 'waldorf_native_read_expired');
    foreach($approved as $row){
        need(($row['hotel_id']??null)===66198
            && $row['post_url']===($row['platform']==='telegram'?'https://t.me/any_toour/678':'https://max.ru/channel_anytour/AaHo8ukNYB8')
            && ($row['platform']!=='telegram'||$row['message_id']==='678'), 'waldorf_exact_target_changed');
        $old=$row['old_text'];$new=$row['new_text'];
        $before='Одиннадцать ресторанов';$after='Десять ресторанов, баров и лаунжей';
        $same=$old===$new;
        need(is_bool($row['same_caption_skip']??null) && $row['same_caption_skip']===$same
            && ($same?(substr_count($old,$before)===0&&substr_count($old,$after)===1)
                :(substr_count($old,$before)===1&&substr_count($old,$after)===0&&$new===str_replace($before,$after,$old))),
            'waldorf_only_approved_phrase_allowed');
    }
}

/** Retain every native span; adjust only UTF-16 offsets crossing the approved delta. */
function captionQualityProviderText(string $platform,string $oldText,string $newText,?array $oldMarkup=null): array
{
    $rows=json_decode(base64_decode(REPAIR_ROWS_BASE64,true),true,128,JSON_THROW_ON_ERROR);$matches=[];
    foreach($rows as $row)if($row['platform']===$platform&&$row['old_text']===$oldText&&$row['new_text']===$newText)$matches[]=$row;
    need(count($matches)===1, 'waldorf_caption_not_exact_allowlist');$row=$matches[0];
    need(in_array($platform,['telegram','max'],true)&&mb_check_encoding($oldText,'UTF-8')&&mb_check_encoding($newText,'UTF-8')
        && !str_contains($newText,"\r")&&!str_contains($newText,"\0"), 'waldorf_caption_bytes_invalid');
    $bound=$row['old_markup'];$oldMarkup=$oldMarkup??$bound;
    need(is_array($bound)&&array_is_list($bound)&&array_is_list($oldMarkup)&&count($oldMarkup)<=100
        &&shortTitleMarkupEqual($bound,$oldMarkup), 'waldorf_native_markup_changed');
    $old16=mb_convert_encoding($oldText,'UTF-16LE','UTF-8');$new16=mb_convert_encoding($newText,'UTF-16LE','UTF-8');
    need(strlen($new16)/2<=1024, 'waldorf_caption_length_hold');
    $before='Одиннадцать ресторанов';$after='Десять ресторанов, баров и лаунжей';
    $same=$oldText===$newText;$cutStart=0;$cutEnd=0;$delta=0;
    if(!$same){
        need(substr_count($oldText,$before)===1&&substr_count($oldText,$after)===0
            &&$newText===str_replace($before,$after,$oldText), 'waldorf_only_approved_phrase_allowed');
        $byte=strpos($oldText,$before);
        $cutStart=intdiv(strlen(mb_convert_encoding(substr($oldText,0,$byte),'UTF-16LE','UTF-8')),2);
        $cutEnd=$cutStart+intdiv(strlen(mb_convert_encoding($before,'UTF-16LE','UTF-8')),2);
        $delta=intdiv(strlen($new16)-strlen($old16),2);
    }
    $pos=$platform==='max'?'from':'offset';$adjusted=[];
    foreach($oldMarkup as $span){
        need(is_array($span)&&!array_is_list($span), 'markup_span_invalid');shortTitleMarkupFields($span,$platform);
        $a=$span[$pos]??null;$length=$span['length']??null;
        need(is_int($a)&&is_int($length)&&$a>=0&&$length>0&&($a+$length)*2<=strlen($old16), 'markup_range_invalid');
        $b=$a+$length;
        need(shortTitleUtf16Boundary($old16,$a)&&shortTitleUtf16Boundary($old16,$b), 'markup_splits_surrogate');
        if(!$same){
            if($b<=$cutStart){} // Preceding spans remain byte-for-byte identical.
            elseif($a>=$cutEnd){$span[$pos]+=$delta;}
            elseif($a<=$cutStart&&$b>=$cutEnd){$span['length']+=$delta;}
            else need(false, 'waldorf_markup_crosses_replacement_boundary');
        }
        need(shortTitleUtf16Boundary($new16,$span[$pos])&&shortTitleUtf16Boundary($new16,$span[$pos]+$span['length']), 'markup_splits_surrogate');
        $adjusted[]=$span;
    }
    if($platform==='telegram')return ['markup'=>$adjusted,'max_html'=>null];
    usort($adjusted,static fn(array $a,array $b):int=>($a['from']<=>$b['from'])?:($a['length']<=>$b['length']));
    $tags=['strong'=>'b','emphasized'=>'i','underline'=>'u','strikethrough'=>'s','monospaced'=>'code','link'=>'a'];$html='';$cursor=0;
    foreach($adjusted as $span){
        need($span['from']>=$cursor, 'max_overlapping_markup_unsupported');
        $html.=htmlspecialchars(shortTitleUtf16Slice($new16,$cursor,$span['from']-$cursor),ENT_QUOTES,'UTF-8');
        $tag=$tags[$span['type']];$attribute=$span['type']==='link'?' href="'.htmlspecialchars($span['url'],ENT_QUOTES,'UTF-8').'"':'';
        $html.='<'.$tag.$attribute.'>'.htmlspecialchars(shortTitleUtf16Slice($new16,$span['from'],$span['length']),ENT_QUOTES,'UTF-8').'</'.$tag.'>';
        $cursor=$span['from']+$span['length'];
    }
    $html.=htmlspecialchars(shortTitleUtf16Slice($new16,$cursor,intdiv(strlen($new16),2)-$cursor),ENT_QUOTES,'UTF-8');
    return ['markup'=>$adjusted,'max_html'=>$html];
}
