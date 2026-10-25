<?php
declare(strict_types=1);
define('ANYTOUR_SELECTION_HOSTING_INVENTORY_LIBRARY',true);
require dirname(__DIR__).'/hosting_inventory.php';
$dir=sys_get_temp_dir().'/selection-hosting-fixture-'.bin2hex(random_bytes(8));
mkdir($dir,0700);mkdir($dir.'/www');mkdir($dir.'/vhosts');
$n=0;
function checkHosting(bool $ok,string $message):void {global $n;$n++;if (!$ok) throw new RuntimeException($message);}
try {
    mkdir($dir.'/www/app.anytoour.ru');mkdir($dir.'/www/tours.anytoour.ru');
    mkdir($dir.'/www/unrelated.example');mkdir($dir.'/www/bad_anytoour.ru');
    file_put_contents($dir.'/vhosts/test.conf',"server_name app.anytoour.ru tours.anytoour.ru;\nPRIVATE_TEST_PASSWORD=DO_NOT_EXPORT\nssl_certificate_key /private/secret.key;\n");
    $r=selectionHostingInventory([$dir.'/www'],[$dir.'/vhosts']);
    checkHosting($r['ok']===true,'inventory result');
    checkHosting(count($r['domains'])===2,'exact domain suffix only');
    checkHosting($r['domains'][0]['domain']==='app.anytoour.ru','stable order');
    checkHosting($r['domains'][1]['directory']&&$r['domains'][1]['vhost'],'hosting facts');
    checkHosting($r['configuration_changed']===false,'no configuration mutation');
    checkHosting(!$r['dns_checked']&&!$r['tls_checked'],'do not infer DNS/TLS');
    $json=json_encode($r,JSON_THROW_ON_ERROR);
    checkHosting(!str_contains($json,'DO_NOT_EXPORT')&&!str_contains($json,'secret.key'),'no secret/config text export');
    checkHosting(!str_contains($json,$dir),'no filesystem path export');
    checkHosting($r['web_roots_available']===1&&$r['vhost_directories_available']===1,'availability explicit');
    checkHosting(file_get_contents($dir.'/vhosts/test.conf')==="server_name app.anytoour.ru tours.anytoour.ru;\nPRIVATE_TEST_PASSWORD=DO_NOT_EXPORT\nssl_certificate_key /private/secret.key;\n",'input unchanged');
    $missing=selectionHostingInventory([$dir.'/missing'],[$dir.'/missing']);
    checkHosting($missing['web_roots_available']===0&&$missing['domains']===[],'missing visibility is not absence claim');
    symlink($dir.'/vhosts/test.conf',$dir.'/vhosts/linked.conf');
    checkHosting(selectionHostingInventory([],[$dir.'/vhosts'])['truncated']===true,'symlink configuration excluded');
    mkdir($dir.'/www/d.anytoour.ru');
    file_put_contents($dir.'/www/d.anytoour.ru/index.html','SYNTHETIC_BODY_MUST_STAY_UNREAD');
    file_put_contents($dir.'/vhosts/d.anytoour.ru.conf',"server_name d.anytoour.ru;\nroot ".$dir."/www/d.anytoour.ru;\nssl_certificate /private/cert;\n");
    $detail=selectionHostingDetail($dir.'/www',[$dir.'/vhosts']);
    checkHosting($detail['domain']==='d.anytoour.ru'&&$detail['directory'],'exact host only');
    checkHosting($detail['public_entries'][0]['name']==='index.html','entry metadata only');
    checkHosting($detail['vhosts'][0]['document_roots']===['d.anytoour.ru'],'root relation');
    checkHosting($detail['vhosts'][0]['tls_configured']===true,'configuration not TLS proof');
    checkHosting(!$detail['dns_checked']&&!$detail['tls_checked'],'offline detail never invents network proof');
    checkHosting(!str_contains(json_encode($detail),'SYNTHETIC_BODY_MUST_STAY_UNREAD'),'no page body export');
    echo 'HOSTING INVENTORY: '.$n." PASS\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) {if ($p->isDir()&&!$p->isLink()) rmdir($p->getPathname());else unlink($p->getPathname());}
    rmdir($dir);
}
