<?php
declare(strict_types=1);
// CLI-only synthetic rendering. This file is never an anonymous data endpoint.
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
$template=$argv[1]??'';
if (basename($template)!=='view.php'||!is_file($template)||is_link($template)) exit(2);
$h=static fn(string $text):string=>htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$interest=null;$selection=null;
if (($argv[2]??'')==='available') {
    $offer=['index'=>0,'hotel'=>'Демонстрационный отель','destination'=>'Египет · пример',
        'amount'=>180000,'currency'=>'RUB','adults'=>2,'children'=>0,'date_from'=>'2026-12-01','nights'=>7,
        'departure'=>'Москва','room'=>'Стандартный номер','meal'=>'Всё включено',
        'photos'=>['demo-1.svg','demo-2.svg','demo-3.svg'],'photo_layout'=>'gallery-v1',
        'rationale'=>'Вымышленные условия для проверки интерфейса.','compromise'=>'',
        'flights'=>'','inclusions'=>'','constraints'=>'','observed_at'=>'',
        'bot_links'=>['telegram'=>'demo-contact.html#telegram','max'=>'demo-contact.html#max']];
    $second=$offer;$second['index']=1;$second['amount']=205000;$second['room']='Номер с видом на море';
    $selection=['title'=>'Тестовая подборка AnyTour','intro'=>'Демонстрация: вымышленные предложения и изображения. Сообщения не отправляются.',
        'version'=>1,'updated_at'=>'2026-10-25 00:00:00','offers'=>[$offer,$second]];
}
require $template;
