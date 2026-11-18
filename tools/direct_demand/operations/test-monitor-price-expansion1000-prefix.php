<?php
declare(strict_types=1);
function monitor_price_test_accepted877(string $packed):array {
 $proofRaw=file_get_contents(__DIR__.'/monitor-price-expansion1000-proof-20261118.json');
 if(hash('sha256',$proofRaw)!=='ff4aaa11ce77ce015e82b40acabc19db3dd15a676b5826aefade62091bf8cb31')throw new RuntimeException('expansion1000_proof_changed');
 $proof=json_decode($proofRaw,true,64,JSON_THROW_ON_ERROR);
 if(hash('sha256',$packed)!==$proof['roster_gzip_sha256'])throw new RuntimeException('expansion1000_packed_changed');
 $raw=gzdecode($packed);$full=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
 if(count($full['rows'])!==1000||array_column(array_slice($full['rows'],877),'tourvisor_hotel_id')!==array_column($proof['append123'],'tourvisor_hotel_id'))throw new RuntimeException('expansion1000_scope_changed');
 $old=substr($raw,0,$proof['baseline_roster_raw_bytes']-7)."\n  ]\n}\n";
 if(hash('sha256',$old)!==$proof['baseline_roster_raw_sha256'])throw new RuntimeException('baseline877_changed');
 return [$old,$proof['baseline_roster_gzip_sha256']];
}
