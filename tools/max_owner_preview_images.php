<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }

function ownerPreviewUploadUrl($value): bool
{
    if (!is_string($value)||strlen($value)>8192||preg_match('/[\x00-\x20\x7f]/',$value)) return false;
    $url=parse_url($value);
    return is_array($url)&&($url['scheme']??null)==='https'&&strtolower($url['host']??'')==='iu.oneme.ru'
        &&($url['path']??null)==='/uploadImage'&&(!isset($url['port'])||$url['port']===443)
        &&!isset($url['user'])&&!isset($url['pass'])&&!isset($url['fragment'])&&!empty($url['query']);
}

function ownerPreviewImageToken($value): bool
{
    $json=is_string($value)?json_encode($value):false;
    return is_string($value)&&$value!==''&&strlen($value)<=4096&&is_string($json)&&strlen($json)<=8192;
}

function ownerPreviewUploadedToken(?array $data): ?string
{
    $photos=$data['photos']??null;
    if (!is_array($photos)||count($photos)!==1) return null;
    $photo=reset($photos);$token=is_array($photo)?($photo['token']??null):null;
    return ownerPreviewImageToken($token)?$token:null;
}

/** Fixed official sources only; no bot credentials, redirects or MAX CA overrides. */
function ownerPreviewDownloadImage(int $index,string $directory,bool $comparison=false): ?array
{
    if (!in_array($index,[0,1,2],true)) return null;
    $url=($comparison?ownerPreviewComparisonBody():ownerPreviewBody())['attachments'][$index]['payload']['url'];
    return ownerPreviewDownloadOfficialImage($url,$index,$directory);
}

/** Shared bounded downloader; callers select literal official gallery sources, never CLI URLs. */
function ownerPreviewDownloadOfficialImage(string $url,int $index,string $directory): ?array
{
    if (!in_array($index,[0,1,2],true)||!preg_match('~\Ahttps://www\.delphinhotel\.com/main_pics/pages/(?:medium|big)/[0-9]+\.(?:png|jpg)\z~D',$url)) return null;
    $path=$directory.'/.owner-preview-image-'.$index.'-'.bin2hex(random_bytes(8)).'.tmp';
    $old=umask(0077);$file=@fopen($path,'x+');umask($old);
    if ($file===false) return null;
    $keep=false;$bytes=0;
    try {
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'AnyTourOwnerPreview/1.0',
            CURLOPT_WRITEFUNCTION=>static function($ch,string $part) use ($file,&$bytes): int {
                if ($bytes+strlen($part)>5242880) return 0;
                $written=fwrite($file,$part);$bytes+=(int)$written;return $written===strlen($part)?$written:0;
            }]);
        curl_exec($ch);$ok=curl_errno($ch)===0&&(int)curl_getinfo($ch,CURLINFO_SSL_VERIFYRESULT)===0&&(int)curl_getinfo($ch,CURLINFO_HTTP_CODE)===200;
        curl_close($ch);fflush($file);
        $image=$ok&&$bytes>0?@getimagesize($path):false;
        $mime=$image['mime']??null;
        if (!is_array($image)||!in_array($mime,['image/png','image/jpeg'],true)||$image[0]<1||$image[1]<1||$image[0]>7680||$image[1]>7680||((fstat($file)['mode']??0)&0777)!==0600) return null;
        $keep=true;return ['path'=>$path,'mime'=>$mime];
    } finally { fclose($file);if (!$keep) @unlink($path); }
}

/** Signed image-upload URL carries its own auth. Never forward the bot Authorization. */
function ownerPreviewUploadImage(string $url,array $image): array
{
    if (!ownerPreviewUploadUrl($url)) return ownerPreviewDecode('',0,0,0);
    $bytes='';$overflow=false;$ch=curl_init($url);
    curl_setopt_array($ch,MaxTlsConfig::strictCurlOptions()+[
        CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['data'=>new CURLFile($image['path'],$image['mime'],'owner-demo.'.($image['mime']==='image/png'?'png':'jpg'))],
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION=>static function($ch,string $part) use (&$bytes,&$overflow): int {
            if (strlen($bytes)+strlen($part)>65536) { $overflow=true;return 0; }
            $bytes.=$part;return strlen($part);
        }]);
    curl_exec($ch);$response=ownerPreviewDecode($bytes,(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),curl_errno($ch),(int)curl_getinfo($ch,CURLINFO_SSL_VERIFYRESULT),$overflow);
    curl_close($ch);return $response;
}

/** Called only after exact known-MID owner/demo proof. Persist each successful token before PUT. */
function ownerPreviewPrepareImages(array &$receipt,&$file,string $journal,callable $request,string $token,bool $atomic,array &$out,?array $transfers=null,bool $comparison=false): ?array
{
    $tokens=$receipt['image_tokens']??[];
    if (!is_array($tokens)||count($tokens)>3) return null;
    foreach ($tokens as $index=>$value) if (!in_array($index,[0,1,2],true)||!ownerPreviewImageToken($value)) return null;
    $body=$comparison?ownerPreviewComparisonBody():ownerPreviewBody();
    $sources=$receipt['image_sources']??[];
    if ($comparison) {
        if (!is_array($sources)||array_keys($sources)!==array_keys($tokens)) return null;
        foreach ($tokens as $index=>$value) if (($sources[$index]??null)!==hash('sha256',$body['attachments'][$index]['payload']['url'])) return null;
    }
    $download=$transfers['download']??'ownerPreviewDownloadImage';$upload=$transfers['upload']??'ownerPreviewUploadImage';
    foreach ([0,1,2] as $index) {
        if (isset($tokens[$index])) continue;
        $out['image_stage']='download';$image=$download($index,dirname($journal),$comparison);
        if (!is_array($image)||!is_string($image['path']??null)||dirname($image['path'])!==dirname($journal)
            ||!preg_match('/\A\.owner-preview-image-[012]-[a-f0-9]{16}\.tmp\z/D',basename($image['path']))) return null;
        try {
            if (is_link($image['path'])||!is_file($image['path'])||(fileperms($image['path'])&0777)!==0600||!in_array($image['mime']??null,['image/png','image/jpeg'],true)) return null;
            $out['image_stage']='reserve';$out['provider_requests']++;
            $response=$request('POST','/uploads',['type'=>'image'],null,$token);$out['image_diagnostic']=ownerPreviewDiagnostic($response);
            $data=ownerPreviewData($response);$url=$data['url']??$data['upload_url']??null;
            if (!ownerPreviewUploadUrl($url)) { $out['image_stage']='upload_url_blocked';return null; }
            $out['image_stage']='upload';$out['provider_requests']++;
            $response=$upload($url,$image);$out['image_diagnostic']=ownerPreviewDiagnostic($response);
            $uploaded=ownerPreviewUploadedToken(ownerPreviewData($response));
            if ($uploaded===null) return null;
            $tokens[$index]=$uploaded;$receipt['image_tokens']=$tokens;$out['image_stage']='persist';
            if ($comparison) { $sources[$index]=hash('sha256',$body['attachments'][$index]['payload']['url']);$receipt['image_sources']=$sources; }
            if (!ownerPreviewRecoveryWrite($file,$journal,array_replace($receipt,['state'=>'images_prepared']),$atomic)) return null;
        } finally { @unlink($image['path']); }
    }
    foreach ([0,1,2] as $index) $body['attachments'][$index]['payload']=['token'=>$tokens[$index]];
    $out['image_stage']='ready';$out['images_prepared']=3;return $body;
}
