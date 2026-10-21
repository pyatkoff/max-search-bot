<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0');ini_set('log_errors','0');

// Public photo-source GETs only. No application config, credentials or MAX API.
$out=['state'=>'blocked','requests'=>0,'photos'=>[]];
if (count($argv)!==1||getcwd()!=='/var/www/anytoour/data/www/app.anytoour.ru') {
    echo json_encode($out)."\n";exit;
}
$urls=[
    'https://www.delphinhotel.com/main_pics/pages/medium/2996.png',
    'https://www.delphinhotel.com/main_pics/pages/medium/1823.jpg',
    'https://www.delphinhotel.com/main_pics/pages/medium/709.png',
];
$out['state']='complete';
foreach ($urls as $index=>$url) {
    $bytes='';$overflow=false;$ch=null;$out['requests']++;
    try {
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_CUSTOMREQUEST=>'GET',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_USERAGENT=>'AnyTourOwnerPreview/1.0',
            CURLOPT_WRITEFUNCTION=>static function($unused,string $part)use(&$bytes,&$overflow):int{
                if(strlen($bytes)+strlen($part)>5*1024*1024){$overflow=true;return 0;}
                $bytes.=$part;return strlen($part);
            },
        ]);
        curl_exec($ch);
        $signature=str_starts_with($bytes,"\x89PNG\r\n\x1a\n")?'png':(str_starts_with($bytes,"\xff\xd8\xff")?'jpeg':'other');
        $dimensions=$signature!=='other'?@getimagesizefromstring($bytes):false;
        $mime=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
        $mime=strtolower(trim(explode(';',$mime,2)[0]));
        $out['photos'][]=['index'=>$index+1,'http_status'=>max(0,min(599,(int)curl_getinfo($ch,CURLINFO_HTTP_CODE))),
            'curl_code'=>max(0,min(999,curl_errno($ch))),'tls_verified'=>(int)curl_getinfo($ch,CURLINFO_SSL_VERIFYRESULT)===0,
            'complete'=>curl_errno($ch)===0&&!$overflow,'mime'=>in_array($mime,['image/png','image/jpeg'],true)?$mime:'other',
            'signature'=>$signature,'width'=>is_array($dimensions)?(int)$dimensions[0]:null,'height'=>is_array($dimensions)?(int)$dimensions[1]:null,
            'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
    } catch(Throwable $e) {
        $out['state']='inconclusive';
        $out['photos'][]=['index'=>$index+1,'http_status'=>0,'curl_code'=>0,'tls_verified'=>false,'complete'=>false,
            'mime'=>'other','signature'=>'other','width'=>null,'height'=>null,'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
    } finally { if($ch!==null)curl_close($ch); }
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
