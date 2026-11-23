<?php
declare(strict_types=1);
function monitor_price_test_accepted877(string $packed):array {
 $proofRaw=file_get_contents(__DIR__.'/monitor-price-expansion1000-proof-20261118.json');
 if(hash('sha256',$proofRaw)!=='ff4aaa11ce77ce015e82b40acabc19db3dd15a676b5826aefade62091bf8cb31')throw new RuntimeException('expansion1000_proof_changed');
 $proof=json_decode($proofRaw,true,64,JSON_THROW_ON_ERROR);
 $packedSha=hash('sha256',$packed);
 $correctedSha='b671558af880a7ab186364c35e3750ee1ffdd500931d6f868d1f0f142a550abf';
 if(!in_array($packedSha,[$proof['roster_gzip_sha256'],$correctedSha],true))throw new RuntimeException('expansion1000_packed_changed');
 $raw=gzdecode($packed);
 if($packedSha===$correctedSha){
  // Verify the exact 66198 correction and restore its historical row in memory.
  // Byte ranges avoid PHP/JSON reserialization; row hashes cover every field.
  $historicalPacked=file_get_contents(__DIR__.'/monitor-price-expansion1000-baseline877.json.gz');
  if(hash('sha256',$historicalPacked)!==$proof['baseline_roster_gzip_sha256'])throw new RuntimeException('baseline877_packed_changed');
  $historical=gzdecode($historicalPacked);
  if(hash('sha256',$historical)!==$proof['baseline_roster_raw_sha256'])throw new RuntimeException('baseline877_changed');
  $offset=2030119;$oldLength=2925;$newLength=3549;
  $oldRow=substr($historical,$offset,$oldLength);$newRow=substr($raw,$offset,$newLength);
  if(hash('sha256',$oldRow)!=='4a9b9612958f950c5abfc8870d9ca50852401366aec32cf7387364754cc0de69'
   ||hash('sha256',$newRow)!=='2ccf9fbbfe7d3167989c1757e1990b88c7c1b62c48481751d8f59778555a2f8d')throw new RuntimeException('waldorf66198_row_changed');
  $raw=substr($raw,0,$offset).$oldRow.substr($raw,$offset+$newLength);
 }
 // Authenticate all 1000 original rows and every byte outside the one row.
 if(hash('sha256',$raw)!=='ee0b1019a09c07cea2413723e972d045244707be39a38f573fd86f1a4c879b6f')throw new RuntimeException('original1000_raw_changed');
 $full=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
 if(count($full['rows'])!==1000||array_column(array_slice($full['rows'],877),'tourvisor_hotel_id')!==array_column($proof['append123'],'tourvisor_hotel_id'))throw new RuntimeException('expansion1000_scope_changed');
 $old=substr($raw,0,$proof['baseline_roster_raw_bytes']-7)."\n  ]\n}\n";
 if(hash('sha256',$old)!==$proof['baseline_roster_raw_sha256'])throw new RuntimeException('baseline877_changed');
 return [$old,$proof['baseline_roster_gzip_sha256']];
}
