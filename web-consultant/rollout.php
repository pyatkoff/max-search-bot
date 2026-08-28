<?php
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: private, no-store');

$percent = defined('WEBSITE_ROLLOUT_PERCENT') ? (int) WEBSITE_ROLLOUT_PERCENT : 0;
$percent = max(0, min(100, $percent));
$customWidgetUrl = defined('WEBSITE_WIDGET_URL') && trim((string) WEBSITE_WIDGET_URL) !== '';
$widgetUrl = $customWidgetUrl
    ? (string) WEBSITE_WIDGET_URL
    : '/max-search/web-consultant/widget.js?v=' . rawurlencode((string) (@filemtime(__DIR__ . '/widget.js') ?: time()));
$enhancerUrl = '/max-search/web-consultant/widget-a11y.js?v=' . rawurlencode((string) (@filemtime(__DIR__ . '/widget-a11y.js') ?: time()));
$contextUrl = '/max-search/web-consultant/widget-context.js?v=' . rawurlencode((string) (@filemtime(__DIR__ . '/widget-context.js') ?: time()));

$encodedUrl = json_encode($widgetUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$encodedEnhancerUrl = json_encode($enhancerUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$encodedContextUrl = json_encode($contextUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$encodedPercent = json_encode($percent);

$loader = <<<'JS'
(function(){
  var percent=__PERCENT__;
  if(percent<=0)return;

  var key='anytour_webchat_rollout_bucket_v1';
  var bucket=null;
  var readCookie=function(){
    var match=document.cookie.match(new RegExp('(?:^|; )'+key.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'=([0-9]{1,2})(?:;|$)'));
    return match?parseInt(match[1],10):null;
  };
  var saveCookie=function(value){
    var secure=location.protocol==='https:'?'; Secure':'';
    document.cookie=key+'='+value+'; Path=/; Max-Age=31536000; SameSite=Lax'+secure;
  };
  var createBucket=function(){
    try{
      if(window.crypto&&window.crypto.getRandomValues){
        var a=new Uint32Array(1);window.crypto.getRandomValues(a);return a[0]%100;
      }
    }catch(e){}
    return Math.floor(Math.random()*100);
  };

  try{
    var raw=window.localStorage.getItem(key);
    if(raw!==null&&/^\d{1,2}$/.test(raw))bucket=parseInt(raw,10);
  }catch(e){}
  if(bucket===null||bucket<0||bucket>99){
    try{bucket=readCookie()}catch(e){}
  }
  if(bucket===null||bucket<0||bucket>99){
    bucket=createBucket();
    try{window.localStorage.setItem(key,String(bucket))}catch(e){try{saveCookie(bucket)}catch(ignore){}}
  }

  if(percent<100&&bucket>=percent)return;
  var ensureExtras=function(){
    if(!document.querySelector('script[data-anytour-webchat-a11y]')){
      var a=document.createElement('script');
      a.src=__ENHANCER_URL__;
      a.async=true;
      a.setAttribute('data-anytour-webchat-a11y','1');
      document.head.appendChild(a);
    }
    if(!document.querySelector('script[data-anytour-webchat-context]')){
      var c=document.createElement('script');
      c.src=__CONTEXT_URL__;
      c.async=true;
      c.setAttribute('data-anytour-webchat-context','1');
      document.head.appendChild(c);
    }
  };
  if(document.querySelector('script[data-anytour-webchat]')){ensureExtras();return;}
  var s=document.createElement('script');
  s.src=__WIDGET_URL__;
  s.async=true;
  s.setAttribute('data-anytour-webchat','1');
  s.onload=ensureExtras;
  document.head.appendChild(s);
}());
JS;

$loader = str_replace(
    ['__PERCENT__', '__WIDGET_URL__', '__ENHANCER_URL__', '__CONTEXT_URL__'],
    [$encodedPercent, $encodedUrl, $encodedEnhancerUrl, $encodedContextUrl],
    $loader
);
echo $loader . "\n";
