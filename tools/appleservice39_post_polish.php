<?php
declare(strict_types=1);
ini_set('display_errors','0');
const AS39_POLISH_CTA='Узнать стоимость ремонта';
const AS39_POLISH_ROOT='/var/www/anytoour/data/www/app.anytoour.ru';
const AS39_POLISH_APP='__APPLICATION_SHA__';
const AS39_POLISH_CHANNEL='-80917954947727';
const AS39_POLISH_PIN='mid.ffffb667d20a157101a1bd6d233963da';
function ck(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
function emit(array $data):void{echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;}
function invoke(object $owner,string $method,...$args){return(new ReflectionMethod($owner,$method))->invoke($owner,...$args);}
function canon($value){if(!is_array($value))return $value;if(!array_is_list($value))ksort($value);foreach($value as&$item)$item=canon($item);unset($item);return $value;}
function digest($value):string{return hash('sha256',json_encode(canon($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
function plain(string $text):string{return trim((string)preg_replace('/[\s\p{Z}]+/u',' ',$text));}
function targets():array{return json_decode(<<<'JSON'
{
  "service-display": {
    "mid": "mid.ffffb667d20a157101a1bd6d15311dc8",
    "url": "https://max.ru/channel_appleservice39ru/AaG9bRUxHcg",
    "before_public_plain": "Замена дисплея iPhone в Калининграде\nПолосы, пятна, пропавшее изображение или сбои касаний? Проверим экран и предложим подходящий дисплей для вашей модели iPhone.\nДоступный вариант детали, стоимость и срок согласуем до ремонта. Если повреждено только стекло, мастер проверит возможность сохранить исправный дисплей.\nГарантия на ремонт — 3 месяца.\nКалининград, Ленинский проспект, 18 · 4 этаж, офис 404\nНапишите менеджеру — уточним стоимость и наличие дисплея для вашей модели.\nУслуги и варианты ремонта\niPhone 15\niPhone 15 Plus\niPhone 15 Pro\niPhone 15 Pro Max\niPhone X\nМеню ремонта\nЗамена дисплея iPhone 16",
    "link_fixtures": [
      {
        "label": "iPhone 15",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bM1qJ2I"
      },
      {
        "label": "iPhone 15 Plus",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bNuPPpk"
      },
      {
        "label": "iPhone 15 Pro",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bOnYIio"
      },
      {
        "label": "iPhone 15 Pro Max",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bPfeEVo"
      },
      {
        "label": "iPhone X",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bL7kWf4"
      },
      {
        "label": "Меню ремонта",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bSM5Y9o"
      },
      {
        "label": "Замена дисплея iPhone 16",
        "url": "https://max.ru/channel_appleservice39ru/AaHJ4X2QW-k"
      }
    ]
  },
  "repair-iphone-16-display": {
    "mid": "mid.ffffb667d20a157101a1c9e17d905be9",
    "url": "https://max.ru/channel_appleservice39ru/AaHJ4X2QW-k",
    "before_public_plain": "Замена дисплея iPhone 16 в Калининграде\nНа iPhone 16 появились полосы, пятна или пропало изображение? Проверим экран и подберём подходящий вариант дисплея.\nСтоимость зависит от выбранной детали. Менеджер уточнит вариант и наличие, а цену и срок согласуем до начала работ.\nЕсли изображение и касания исправны, отдельно проверим возможность заменить только повреждённое стекло.\nГарантия на ремонт — 3 месяца.\nКалининград, Ленинский проспект, 18 · 4 этаж, офис 404\nНапишите менеджеру — уточним вариант и наличие дисплея для iPhone 16.\nУслуги и варианты ремонта\nЗамена дисплея iPhone\nРемонт iPhone",
    "link_fixtures": [
      {
        "label": "Замена дисплея iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bRUxHcg"
      },
      {
        "label": "Ремонт iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bSM5Y9o"
      }
    ]
  },
  "service-rear-glass-replacement": {
    "mid": "mid.ffffb667d20a157101a1c9e1954f3989",
    "url": "https://max.ru/channel_appleservice39ru/AaHJ4ZVPOYk",
    "before_public_plain": "Замена заднего стекла iPhone в Калининграде\nТреснуло или разбилось заднее стекло iPhone? Уточним модель и проверим возможность замены повреждённого стекла.\nПодходящую деталь, стоимость и срок согласуем до начала работ. Гарантия на ремонт — 3 месяца.\nКалининград, Ленинский проспект, 18 · 4 этаж, офис 404\nНапишите менеджеру — узнаете стоимость замены для своей модели.\nУслуги и варианты ремонта\nРемонт iPhone",
    "link_fixtures": [
      {
        "label": "Ремонт iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bSM5Y9o"
      }
    ]
  },
  "symptom-not-charging": {
    "mid": "mid.ffffb667d20a157101a1c9e1ae0a09c5",
    "url": "https://max.ru/channel_appleservice39ru/AaHJ4a4KCcU",
    "before_public_plain": "iPhone не заряжается — ремонт в Калининграде\nТелефон не реагирует на кабель или зарядка постоянно прерывается? Проверим причину неисправности и предложим подходящий ремонт.\nПосле проверки объясним, какие работы нужны, и согласуем стоимость и срок до начала ремонта. Гарантия на ремонт — 3 месяца.\nКалининград, Ленинский проспект, 18 · 4 этаж, офис 404\nНапишите менеджеру — уточним симптомы и поможем записаться на проверку.\nУслуги и варианты ремонта\nРемонт iPhone",
    "link_fixtures": [
      {
        "label": "Ремонт iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bSM5Y9o"
      }
    ]
  },
  "service-rear-camera-replacement": {
    "mid": "mid.ffffb667d20a157101a1c9e1c7e50e8d",
    "url": "https://max.ru/channel_appleservice39ru/AaHJ4cflDo0",
    "before_public_plain": "Замена основной камеры iPhone в Калининграде\nОсновная камера не фокусируется, показывает пятна или перестала работать? Проверим причину и возможность замены камеры на вашей модели iPhone.\nПодберём подходящую деталь и согласуем стоимость и срок до начала работ. Гарантия на ремонт — 3 месяца.\nКалининград, Ленинский проспект, 18 · 4 этаж, офис 404\nНапишите менеджеру — уточним стоимость и наличие подходящей камеры.\nУслуги и варианты ремонта\nРемонт iPhone",
    "link_fixtures": [
      {
        "label": "Ремонт iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bSM5Y9o"
      }
    ]
  },
  "service-earpiece-replacement": {
    "mid": "mid.ffffb667d20a157101a1c9e1e3715d38",
    "url": "https://max.ru/channel_appleservice39ru/AaHJ4eNxXTg",
    "before_public_plain": "Ремонт разговорного динамика iPhone в Калининграде\nСобеседника почти не слышно или звук во время звонка искажён? Проверим разговорный динамик и причину неисправности.\nЕсли нужна замена, уточним совместимую деталь и согласуем стоимость и срок до начала работ. Гарантия на ремонт — 3 месяца.\nКалининград, Ленинский проспект, 18 · 4 этаж, офис 404\nНапишите менеджеру — уточним модель и возможность записи.\nУслуги и варианты ремонта\nРемонт iPhone",
    "link_fixtures": [
      {
        "label": "Ремонт iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bSM5Y9o"
      }
    ]
  },
  "model-iphone-17": {
    "mid": "mid.ffffb667d20a157101a1c9e1ffbe5db2",
    "url": "https://max.ru/channel_appleservice39ru/AaHJ4f--XbI",
    "before_public_plain": "Ремонт iPhone 17 в Калининграде\nРемонт iPhone 17, 17 Pro и 17 Pro Max в AppleService39. Поможем с повреждённым экраном, аккумулятором, разъёмом зарядки и другими неисправностями, предусмотренными для вашей модели.\nУточним точную версию iPhone, проверим подходящий вариант ремонта и наличие детали. Стоимость и срок согласуем до начала работ.\nГарантия на ремонт — 3 месяца.\nКалининград, Ленинский проспект, 18 · 4 этаж, офис 404\nНапишите менеджеру — уточним вариант iPhone 17 и возможность записи.\nУслуги и варианты ремонта\nЗамена дисплея iPhone\nЗамена заднего стекла iPhone\nРемонт iPhone",
    "link_fixtures": [
      {
        "label": "Замена дисплея iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bRUxHcg"
      },
      {
        "label": "Замена заднего стекла iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHJ4ZVPOYk"
      },
      {
        "label": "Ремонт iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bSM5Y9o"
      }
    ]
  },
  "models-index": {
    "mid": "mid.ffffb667d20a157101a1d01bf9930f9c",
    "url": "https://max.ru/channel_appleservice39ru/AaHQG_mTD5w",
    "before_public_plain": "Все модели iPhone — ремонт в Калининграде\nВыберите точную модель iPhone, чтобы открыть её карточку и подходящие варианты ремонта.\nСтоимость, срок и наличие нужной детали согласуем до начала работ. Гарантия на согласованный ремонт — 3 месяца.\nAppleService39 · Калининград, Ленинский проспект, 18 · 4 этаж, офис 404\nДругие варианты\niPhone 8\niPhone 8 Plus\niPhone X\niPhone XR\niPhone XS\niPhone XS Max\niPhone 11\niPhone 11 Pro\niPhone 11 Pro Max\niPhone 12\niPhone 12 mini\niPhone 12 Pro\niPhone 12 Pro Max\niPhone 13\niPhone 13 mini\niPhone 13 Pro\niPhone 13 Pro Max\niPhone 14\niPhone 14 Plus\niPhone 14 Pro\niPhone 14 Pro Max\niPhone 15\niPhone 15 Plus\niPhone 15 Pro\niPhone 15 Pro Max\niPhone 16\niPhone 16 Plus\niPhone 16 Pro\niPhone 16 Pro Max\niPhone 17\niPhone 17 Pro\niPhone 17 Pro Max\niPhone Air",
    "link_fixtures": [
      {
        "label": "iPhone 8",
        "url": "https://max.ru/channel_appleservice39ru/AaHQFexcaIE"
      },
      {
        "label": "iPhone 8 Plus",
        "url": "https://max.ru/channel_appleservice39ru/AaHQFiKmEMI"
      },
      {
        "label": "iPhone X",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bPyxCko"
      },
      {
        "label": "iPhone XR",
        "url": "https://max.ru/channel_appleservice39ru/AaHQFkoZJUI"
      },
      {
        "label": "iPhone XS",
        "url": "https://max.ru/channel_appleservice39ru/AaHQFnI-Zt8"
      },
      {
        "label": "iPhone XS Max",
        "url": "https://max.ru/channel_appleservice39ru/AaHQFpsyYr0"
      },
      {
        "label": "iPhone 11",
        "url": "https://max.ru/channel_appleservice39ru/AaHQFsX1EzM"
      },
      {
        "label": "iPhone 11 Pro",
        "url": "https://max.ru/channel_appleservice39ru/AaHQFvHMaZM"
      },
      {
        "label": "iPhone 11 Pro Max",
        "url": "https://max.ru/channel_appleservice39ru/AaHQFx9iOjk"
      },
      {
        "label": "iPhone 12",
        "url": "https://max.ru/channel_appleservice39ru/AaHQF07xcDE"
      },
      {
        "label": "iPhone 12 mini",
        "url": "https://max.ru/channel_appleservice39ru/AaHQF3_Qcgo"
      },
      {
        "label": "iPhone 12 Pro",
        "url": "https://max.ru/channel_appleservice39ru/AaHQF7HkLDM"
      },
      {
        "label": "iPhone 12 Pro Max",
        "url": "https://max.ru/channel_appleservice39ru/AaHQF-WEP1U"
      },
      {
        "label": "iPhone 13",
        "url": "https://max.ru/channel_appleservice39ru/AaHQGB0LL0E"
      },
      {
        "label": "iPhone 13 mini",
        "url": "https://max.ru/channel_appleservice39ru/AaHQGFStO-8"
      },
      {
        "label": "iPhone 13 Pro",
        "url": "https://max.ru/channel_appleservice39ru/AaHQGIzpHx0"
      },
      {
        "label": "iPhone 13 Pro Max",
        "url": "https://max.ru/channel_appleservice39ru/AaHQGMXhcII"
      },
      {
        "label": "iPhone 14",
        "url": "https://max.ru/channel_appleservice39ru/AaHQGQFGezQ"
      },
      {
        "label": "iPhone 14 Plus",
        "url": "https://max.ru/channel_appleservice39ru/AaHQGUjEPzQ"
      },
      {
        "label": "iPhone 14 Pro",
        "url": "https://max.ru/channel_appleservice39ru/AaHQGYXQK2s"
      },
      {
        "label": "iPhone 14 Pro Max",
        "url": "https://max.ru/channel_appleservice39ru/AaHQGcUyAlc"
      },
      {
        "label": "iPhone 15",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bQF9NzI"
      },
      {
        "label": "iPhone 15 Plus",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bQZoK2k"
      },
      {
        "label": "iPhone 15 Pro",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bQuJT50"
      },
      {
        "label": "iPhone 15 Pro Max",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bRBScVc"
      },
      {
        "label": "iPhone 16",
        "url": "https://max.ru/channel_appleservice39ru/AaHQGgaTAqc"
      },
      {
        "label": "iPhone 16 Plus",
        "url": "https://max.ru/channel_appleservice39ru/AaHQGklDUXA"
      },
      {
        "label": "iPhone 16 Pro",
        "url": "https://max.ru/channel_appleservice39ru/AaHQGoyGFx4"
      },
      {
        "label": "iPhone 16 Pro Max",
        "url": "https://max.ru/channel_appleservice39ru/AaHQGtE3PTs"
      },
      {
        "label": "iPhone 17",
        "url": "https://max.ru/channel_appleservice39ru/AaHJ4f--XbI"
      },
      {
        "label": "iPhone 17 Pro",
        "url": "https://max.ru/channel_appleservice39ru/AaHQGxgkAH4"
      },
      {
        "label": "iPhone 17 Pro Max",
        "url": "https://max.ru/channel_appleservice39ru/AaHQG2Cud_c"
      },
      {
        "label": "iPhone Air",
        "url": "https://max.ru/channel_appleservice39ru/AaHQG6ozVgI"
      }
    ]
  },
  "services-index": {
    "mid": "mid.ffffb667d20a157101a1d01fb10d287b",
    "url": "https://max.ru/channel_appleservice39ru/AaHQH7ENKHs",
    "before_public_plain": "Основные услуги ремонта iPhone\nВыберите услугу или симптом. Если причина неизвестна, начните с диагностики: похожие проявления могут требовать разного ремонта.\nСтоимость, срок и наличие нужной детали согласуем до начала работ. Гарантия на согласованный ремонт — 3 месяца.\nAppleService39 · Калининград, Ленинский проспект, 18 · 4 этаж, офис 404\nДругие варианты\nЗамена дисплея iPhone\nЗамена стекла iPhone: цены по моделям\nЗамена аккумулятора iPhone: цены по моделям\nЗамена заднего стекла iPhone\nЗамена основной камеры iPhone\nРемонт разговорного динамика iPhone\nЗамена разъёма зарядки iPhone\nРемонт микрофона iPhone\nРемонт внешнего динамика iPhone\nРемонт передней камеры iPhone\nРемонт кнопки Home iPhone\nРемонт кнопки включения iPhone\nРемонт антенн и связи iPhone\nВосстановление ПО iPhone\nДиагностика iPhone\nРемонт и замена корпуса iPhone\niPhone не заряжается\nВсе модели iPhone",
    "link_fixtures": [
      {
        "label": "Замена дисплея iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bRUxHcg"
      },
      {
        "label": "Замена стекла iPhone: цены по моделям",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bRnSVwU"
      },
      {
        "label": "Замена аккумулятора iPhone: цены по моделям",
        "url": "https://max.ru/channel_appleservice39ru/AaG9bR5vbus"
      },
      {
        "label": "Замена заднего стекла iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHJ4ZVPOYk"
      },
      {
        "label": "Замена основной камеры iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHJ4cflDo0"
      },
      {
        "label": "Ремонт разговорного динамика iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHJ4eNxXTg"
      },
      {
        "label": "Замена разъёма зарядки iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHQHEeGWP4"
      },
      {
        "label": "Ремонт микрофона iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHQHJl-VYk"
      },
      {
        "label": "Ремонт внешнего динамика iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHQHOyFaZs"
      },
      {
        "label": "Ремонт передней камеры iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHQHT0rWDo"
      },
      {
        "label": "Ремонт кнопки Home iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHQHZFiJM4"
      },
      {
        "label": "Ремонт кнопки включения iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHQHegTfM8"
      },
      {
        "label": "Ремонт антенн и связи iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHQHj-Oa2o"
      },
      {
        "label": "Восстановление ПО iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHQHpg4KMw"
      },
      {
        "label": "Диагностика iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHQHvI3c4s"
      },
      {
        "label": "Ремонт и замена корпуса iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHQH0vYVVs"
      },
      {
        "label": "iPhone не заряжается",
        "url": "https://max.ru/channel_appleservice39ru/AaHJ4a4KCcU"
      },
      {
        "label": "Все модели iPhone",
        "url": "https://max.ru/channel_appleservice39ru/AaHQG_mTD5w"
      }
    ]
  }
}
JSON,true,16,JSON_THROW_ON_ERROR);}
function gitRead(array $args):string{
 $process=proc_open(array_merge(['git','-C',AS39_POLISH_ROOT],$args),[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
 ck(is_resource($process),'checkout_unavailable');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);
 ck(proc_close($process)===0,'checkout_unavailable');return trim($out);
}
function htmlPlan(string $text,array $markup):array{
 ck(preg_match('/[\x{10000}-\x{10ffff}]/u',$text)===0,'unsupported_native_character');
 usort($markup,static fn($a,$b)=>$a['from']<=>$b['from']);$html='';$offset=0;
 $escape=static fn($s)=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 foreach($markup as $span){
  $from=$span['from']??null;$length=$span['length']??null;
  ck(is_int($from)&&is_int($length)&&$from>=$offset&&$length>0&&$from+$length<=mb_strlen($text,'UTF-8'),'native_markup_invalid');
  $html.=$escape(mb_substr($text,$offset,$from-$offset,'UTF-8'));$label=$escape(mb_substr($text,$from,$length,'UTF-8'));
  if($span['type']==='strong')$html.='<b>'.$label.'</b>';
  elseif($span['type']==='link'){
   $url=$span['url']??'';$pair=false;
   if(preg_match('~\Ahttps://max\.ru/id390521129062_bot\?start=(as39_pair_[A-Za-z0-9_-]+)\z~D',$url,$m)===1){
    $entry=AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot',$m[1]);$pair=is_array($entry)&&$entry['scope']==='model_service';
   }
   ck($pair||preg_match('~\Ahttps://(?:max\.ru/channel_appleservice39ru/[A-Za-z0-9_-]+|yandex\.ru/maps/org/apple_service39/1731550627/reviews/)\z~D',$url)===1,'native_link_refused');
   $html.='<a href="'.$escape($url).'">'.$label.'</a>';
  }else throw new RuntimeException('unsupported_native_markup');
  $offset=$from+$length;
 }
 $html.=$escape(mb_substr($text,$offset,null,'UTF-8'));ck(mb_strlen($html,'UTF-8')<=4000,'edited_caption_too_long');
 return ['body'=>['text'=>$html,'format'=>'html'],'native_text'=>$text,'native_markup'=>$markup];
}
function replaceRange(string $text,array $markup,int $at,int $length,string $replacement):array{
 $delta=mb_strlen($replacement,'UTF-8')-$length;
 foreach($markup as&$span){
  if($span['from']+$span['length']<=$at)continue;
  if($span['from']>=$at+$length){$span['from']+=$delta;continue;}
  ck($length>0&&$span['from']===$at&&$span['length']===$length&&$span['type']==='strong','edit_overlaps_native_markup');
  $span['length']=mb_strlen($replacement,'UTF-8');
 }unset($span);
 return [mb_substr($text,0,$at,'UTF-8').$replacement.mb_substr($text,$at+$length,null,'UTF-8'),$markup];
}
function replaceOnce(string $text,array $markup,string $old,string $next):array{
 $at=mb_strpos($text,$old,0,'UTF-8');ck($at!==false&&mb_strpos($text,$old,$at+1,'UTF-8')===false,'caption_phrase_changed');
 return replaceRange($text,$markup,$at,mb_strlen($old,'UTF-8'),$next);
}
function plan(string $id,string $text,array $markup,string $displayUrl):array{
 ck(isset(targets()[$id]),'unreviewed_target');
 if(in_array($id,['models-index','services-index'],true)){
  $at=mb_strpos($text,'Другие варианты',0,'UTF-8');
  ck($at!==false&&mb_strpos($text,AS39_POLISH_CTA,0,'UTF-8')===false,'navigation_caption_changed');
  [$text,$markup]=replaceRange($text,$markup,$at,0,'Нажмите «'.AS39_POLISH_CTA.'», если нужна помощь с выбором.'."\n\n");
 }else{
  [$text,$markup]=replaceOnce($text,$markup,'Напишите менеджеру —','Нажмите «'.AS39_POLISH_CTA.'» —');
 }
 $choices=[];
 if($id==='model-iphone-17'){
  [$text,$markup]=replaceOnce($text,$markup,'Услуги и варианты ремонта','Выберите ремонт iPhone 17');
  $display=null;
  foreach($markup as$i=>$span)if($span['type']==='link'&&($span['url']??'')===$displayUrl){ck($display===null,'display_choice_ambiguous');$display=$i;}
  ck($display!==null,'display_choice_missing');
  foreach(['service-battery-replacement','service-front-glass-replacement','service-display']as$service){
   $payload=AppleService39PilotCatalog::modelServicePayload($id,$service);
   $entry=is_string($payload)?AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot',$payload):null;
   ck(is_string($payload)&&strlen($payload)<=64&&$entry!==null&&$entry['post_id']===$id&&$entry['scope']==='model_service'&&$entry['model']==='iPhone 17','iphone17_service_code_not_installed');
   $choices[$service]='https://max.ru/id390521129062_bot?start='.$payload;
  }
  $insert=$markup[$display]['from'];
  $markup[$display]['url']=$choices['service-display'];
  $labels=['Замена аккумулятора','Замена стекла без дисплея'];
  [$text,$markup]=replaceRange($text,$markup,$insert,0,implode("\n",$labels)."\n");
  $offset=$insert;
  foreach(array_slice(array_keys($choices),0,2)as$i=>$service){
   $markup[]=['type'=>'link','from'=>$offset,'length'=>mb_strlen($labels[$i],'UTF-8'),'url'=>$choices[$service]];
   $offset+=mb_strlen($labels[$i],'UTF-8')+1;
  }
 }
 $result=htmlPlan($text,$markup);
 ck(substr_count($result['native_text'],'«'.AS39_POLISH_CTA.'»')===1,'one_caption_cta_required');
 ck(!isset($result['body']['attachments'])&&!isset($result['body']['notify']),'text_only_edit_required');
 $result['choices']=$choices;return $result;
}
function selfTest():void{
 require_once dirname(__DIR__).'/services/AppleService39PilotCatalog.php';
 $checks=0;$expect=static function(bool $ok)use(&$checks):void{ck($ok,'plan_self_test_failed');$checks++;};
 $display='https://max.ru/channel_appleservice39ru/AaG9bRUxHcg';
 foreach(targets()as$id=>$target){
  $text=$target['before_public_plain'];$markup=[];
  $first=strpos($text,"\n");$markup[]=['type'=>'strong','from'=>0,'length'=>mb_strlen(substr($text,0,$first),'UTF-8')];
  $heading=in_array($id,['models-index','services-index'],true)?'Другие варианты':'Услуги и варианты ремонта';
  $at=mb_strpos($text,$heading,0,'UTF-8');ck($at!==false,'fixture_heading_missing');
  $markup[]=['type'=>'strong','from'=>$at,'length'=>mb_strlen($heading,'UTF-8')];
  $cursor=$at+mb_strlen($heading,'UTF-8');
  foreach($target['link_fixtures']as$link){
   $from=mb_strpos($text,$link['label'],$cursor,'UTF-8');ck($from!==false,'fixture_label_missing');
   $length=mb_strlen($link['label'],'UTF-8');$markup[]=['type'=>'link','from'=>$from,'length'=>$length,'url'=>$link['url']];$cursor=$from+$length;
  }
  $before=htmlPlan($text,$markup);$next=plan($id,$text,$markup,$display);
  $expect(str_contains($next['native_text'],'«'.AS39_POLISH_CTA.'»')&&!str_contains($next['native_text'],'Напишите менеджеру —'));
  $expect(array_keys($next['body'])===['text','format']&&str_contains($next['native_text'],'3 месяца'));
  if($id==='model-iphone-17'){
   $expect(count($next['choices'])===3&&str_contains($next['native_text'],'Замена аккумулятора')&&str_contains($next['native_text'],'Замена стекла без дисплея'));
   foreach($next['choices']as$url)$expect(count(array_filter($next['native_markup'],static fn($span)=>($span['url']??null)===$url))===1);
  }else{
   $oldLinks=array_values(array_filter($before['native_markup'],static fn($s)=>$s['type']==='link'));
   $newLinks=array_values(array_filter($next['native_markup'],static fn($s)=>$s['type']==='link'));
   $oldIdentity=array_map(static fn($span)=>[$span['url'],mb_substr($before['native_text'],$span['from'],$span['length'],'UTF-8')],$oldLinks);
   $newIdentity=array_map(static fn($span)=>[$span['url'],mb_substr($next['native_text'],$span['from'],$span['length'],'UTF-8')],$newLinks);
   $expect($oldIdentity===$newIdentity);
  }
 }
 $text='Первая строка. Напишите менеджеру — вопрос.';$pos=mb_strpos($text,'Напишите',0,'UTF-8');
 try{plan('service-display',$text,[['type'=>'link','from'=>$pos,'length'=>20,'url'=>$display]],$display);throw new RuntimeException('overlap_was_accepted');}catch(RuntimeException$e){$expect($e->getMessage()==='edit_overlaps_native_markup');}
 try{plan('service-display','Напишите менеджеру — и Напишите менеджеру —',[],$display);throw new RuntimeException('ambiguity_was_accepted');}catch(RuntimeException$e){$expect($e->getMessage()==='caption_phrase_changed');}
 try{plan('unreviewed','Напишите менеджеру —',[],$display);throw new RuntimeException('target_was_accepted');}catch(RuntimeException$e){$expect($e->getMessage()==='unreviewed_target');}
 emit(['ok'=>true,'state'=>'post_polish_plan_checks_passed','checks'=>$checks,'targets'=>9,'iphone17_service_choices'=>3,'provider_writes'=>0,'database_writes'=>0]);
}
if(($argv[1]??'')==='--self-test'){selfTest();exit;}
$store=null;$active=null;
try{
 ck(PHP_SAPI==='cli'&&preg_match('/\A[0-9a-f]{40}\z/D',AS39_POLISH_APP)===1,'fixed_cli_target_required');
 $config='/var/www/anytoour/data/config/max-search.php';
 ck(realpath(AS39_POLISH_ROOT)===AS39_POLISH_ROOT&&is_file($config)&&!is_link($config),'fixed_cli_target_required');
 $configHash=hash_file('sha256',$config);
 $safe=static fn():bool=>gitRead(['rev-parse','HEAD'])===AS39_POLISH_APP&&gitRead(['status','--porcelain','--untracked-files=no'])===''&&is_file($config)&&!is_link($config)&&hash_file('sha256',$config)===$configHash;
 ck($safe(),'checkout_changed');
 require_once AS39_POLISH_ROOT.'/config.php';require_once AS39_POLISH_ROOT.'/services/ConversationDb.php';require_once AS39_POLISH_ROOT.'/services/AppleService39PilotPublisher.php';
 $pdo=ConversationDb::connection();ck(!$pdo->inTransaction(),'autocommit_required');$store=new AppleService39PilotPublicationStore($pdo);
 $original=$store->rows();ck(count($original)===292&&count(array_filter($original,static fn($row)=>$row['state']!=='verified'))===0,'original_operations_changed');
 $manifest=AppleService39PilotManifest::installed();
 $pub=new AppleService39PilotPublisher($pdo,$manifest,AS39_POLISH_CHANNEL,$safe,null,null,new AppleService39CatalogueRevision($manifest),new AppleService39SearchIntentRevision($manifest));
 $store->assertBinding(invoke($pub,'preflight'));ck(invoke($pub,'pinnedMid')===AS39_POLISH_PIN,'pin_changed');
 $connection=ProjectMaxConnection::resolve('appleservice39');ck($connection!==null,'connection_unavailable');
 $transport=new AppleService39ChannelTransport($connection,AS39_POLISH_CHANNEL);
 $get=static function(string $mid)use($transport):array{
  $response=$transport->message($mid);ck(AppleService39ChannelTransport::accepted($response),'native_read_failed');
  return $response['data']['message']??$response['data'];
 };
 $creates=[];foreach($original as$row)if($row['operation']==='create')$creates[$row['post_key']]=$row;
 ck(count($creates)===69,'native_post_inventory_changed');
 $observe=static function(string $id)use($get,$creates,$original,$pub):array{
  $create=$creates[$id]??null;ck(is_array($create)&&$create['state']==='verified','original_publication_missing');
  $mid=$create['external_message_id'];$message=$get($mid);$sourceAttachments=null;$photoReceipt=null;
  foreach($original as$row)if($row['post_key']===$id||($row['request']['target_post']??null)===$id){
   if(isset($row['request']['body']['attachments']))$sourceAttachments=$row['request']['body']['attachments'];
   if(isset($row['outcome']['image_receipt']['photo_id']))$photoReceipt=$row['outcome']['image_receipt']['photo_id'];
  }
  ck(is_array($sourceAttachments)&&is_string($photoReceipt),'original_attachment_binding_missing');
  $before=htmlPlan($message['body']['text'],$message['body']['markup']??[]);
  $attachments=$message['body']['attachments']??null;
  ck(is_array($attachments)&&array_is_list($attachments)&&count($attachments)===2,'native_attachment_shape_changed');
  $kinds=array_column($attachments,'type');ck(count(array_filter($kinds,static fn($type)=>$type==='image'))===1&&count(array_filter($kinds,static fn($type)=>$type==='inline_keyboard'))===1,'native_attachment_shape_changed');
  $imageAt=array_search('image',$kinds,true);$keyAt=array_search('inline_keyboard',$kinds,true);
  $sourceKinds=array_column($sourceAttachments,'type');$sourceKey=array_search('inline_keyboard',$sourceKinds,true);ck($sourceKey!==false,'original_keyboard_missing');
  $expectedButtons=$sourceAttachments[$sourceKey]['payload']['buttons']??null;
  ck(is_array($expectedButtons)&&count($expectedButtons)===1&&count($expectedButtons[0])===1&&$expectedButtons[0][0]['type']==='link','original_single_cta_missing');
  $expectedButtons[0][0]['text']=AS39_POLISH_CTA;
  $buttons=$attachments[$keyAt]['payload']['buttons']??null;
  ck(invoke($pub,'sameButtons',$buttons,$expectedButtons),'single_native_cta_changed');
  $photo=(string)(invoke($pub,'imagePayload',$message)['photo_id']??'');ck($photo!==''&&$photo===$photoReceipt,'original_photo_changed');
  $validationBody=$before['body'];$validationBody['attachments']=$sourceAttachments;
  $validationBody['attachments'][$sourceKey]['payload']['buttons']=$expectedButtons;
  ck(invoke($pub,'verifyMessageContent',$message,$mid,$validationBody,$before)===$create['public_url'],'native_identity_changed');
  return ['post'=>$id,'mid'=>$mid,'url'=>$create['public_url'],'plan'=>$before,'validation_body'=>$validationBody,'photo'=>$photo,'buttons'=>$buttons];
 };
 $fingerprint=static fn(array $p):string=>digest([$p['mid'],$p['url'],$p['plan']['native_text'],$p['plan']['native_markup'],$p['photo'],$p['buttons']]);
 $beforeAll=[];foreach(array_keys($creates)as$id)$beforeAll[$id]=$observe($id);
 $displayUrl=$creates['service-display']['public_url'];$plans=[];
 foreach(targets()as$id=>$target){
  $before=$beforeAll[$id];ck($before['mid']===$target['mid']&&$before['url']===$target['url'],'fixed_target_changed');
  ck(plain($before['plan']['native_text'])===plain($target['before_public_plain']),'caption_preimage_changed');
  $next=plan($id,$before['plan']['native_text'],$before['plan']['native_markup'],$displayUrl);
  $plans[$id]=['before'=>$before,'after'=>$next];
 }
 ck(count($plans)===9&&$safe(),'fixed_plan_incomplete');$prefixDigest=digest($original);
 foreach($plans as$id=>&$p){
  $req=['revision'=>'caption-and-17-services-v1','target_post'=>$id,'message_id'=>$p['before']['mid'],'url'=>$p['before']['url'],
   'original_operations_sha256'=>$prefixDigest,'before_text_sha256'=>hash('sha256',$p['before']['plan']['native_text']),
   'before_markup'=>$p['before']['plan']['native_markup'],'preserved_photo_id'=>$p['before']['photo'],'preserved_buttons'=>$p['before']['buttons'],
   'body'=>$p['after']['body'],'native_text'=>$p['after']['native_text'],'native_markup'=>$p['after']['native_markup'],'choices'=>$p['after']['choices']];
  $p['row']=$store->prepare('pol1-'.$id,'links',$req);
  ck($p['row']['state']==='prepared'&&(int)$p['row']['write_attempts']===0,'polish_operation_already_attempted');
 }unset($p);
 emit(['stage'=>'post_polish_prepared','posts'=>9,'original_operations_preserved'=>292,'native_posts_observed'=>69,'attachment_mutations'=>0]);
 foreach($plans as$id=>$p){
  $active=$p['row'];
  invoke($pub,'beforeWrite',$active,static function()use($observe,$fingerprint,$p,$id,$pub):void{
   ck($fingerprint($observe($id))===$fingerprint($p['before']),'post_changed_before_claim');ck(invoke($pub,'pinnedMid')===AS39_POLISH_PIN,'pin_changed');
  });
  $store->rememberMessage($active,$p['before']['mid'],$p['before']['url']);
  $ack=$transport->edit($p['before']['mid'],$p['after']['body']);
  ck(AppleService39ChannelTransport::accepted($ack)&&($ack['data']['success']??null)===true,'polish_edit_unknown');
  $after=$observe($id);$expectedBody=$p['after']['body'];$expectedBody['attachments']=$p['before']['validation_body']['attachments'];
  ck(invoke($pub,'verifyMessageContent',$get($p['before']['mid']),$p['before']['mid'],$expectedBody,$p['after'])===$p['before']['url']
   &&$after['photo']===$p['before']['photo']&&canon($after['buttons'])===canon($p['before']['buttons']),'polish_readback_failed');
  $store->finish($active,'verified',['readback'=>true,'photo_preserved'=>true,'single_cta_preserved'=>true,'caption_cta'=>AS39_POLISH_CTA,'model_service_choices'=>count($p['after']['choices'])]);
  $active=null;emit(['stage'=>'post_polish_readback','post_id'=>$id,'url'=>$after['url'],'photo_preserved'=>true,'single_cta_preserved'=>true]);usleep(650000);
 }
 $afterRows=$store->rows();$prefix=array_values(array_filter($afterRows,static fn($r)=>!str_starts_with($r['post_key'],'pol1-')));
 ck($prefix===$original&&count($afterRows)===301&&count(array_filter($afterRows,static fn($r)=>$r['state']!=='verified'))===0,'original_or_new_receipts_changed');
 $untouched=0;
 foreach($beforeAll as$id=>$before){
  $after=$observe($id);
  if(!isset($plans[$id])){ck($fingerprint($after)===$fingerprint($before),'other_post_changed');$untouched++;}
  else ck($after['plan']['native_text']===$plans[$id]['after']['native_text']&&AppleService39LandingRevision::sameMarkup($after['plan']['native_markup'],$plans[$id]['after']['native_markup']),'final_caption_changed');
 }
 ck($untouched===60&&$safe()&&invoke($pub,'pinnedMid')===AS39_POLISH_PIN,'final_state_changed');
 $store->assertBinding(invoke($pub,'preflight'));
 emit(['ok'=>true,'state'=>'nine_captions_and_iphone17_choices_published','posts_updated'=>9,'iphone17_service_choices'=>3,'original_operations_preserved'=>292,'verified_operations'=>301,'unchanged_other_posts'=>60,'native_posts_verified'=>69,'images_mids_urls_keyboard_targets_pin_preserved'=>true,'provider_edits'=>9,'provider_creates'=>0,'attachment_mutations'=>0]);
}catch(Throwable $e){
 if($store instanceof AppleService39PilotPublicationStore&&is_array($active)){
  try{$row=$store->operation($active['post_key'],$active['operation']);if($row&&in_array($row['state'],['write_started','unknown'],true))$store->finish($row,'unknown',['reason'=>'polish_edit_or_readback_unknown']);}catch(Throwable $ignored){}
 }
 $reason=$e->getMessage();emit(['ok'=>false,'reason'=>preg_match('/\A[a-z_]{2,100}\z/D',$reason)?$reason:'post_polish_failed','error_class'=>get_class($e),'error_file'=>basename($e->getFile()),'line'=>$e->getLine(),'automatic_retry'=>false]);exit(1);
}
