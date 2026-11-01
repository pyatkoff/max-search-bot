<?php
declare(strict_types=1);

/** Actual canonical endpoint on disposable SQL/session storage; no application config. */
function hotelKnowledgeHttpChecks(): void
{
    $root=dirname(__DIR__,2);
    $directory=sys_get_temp_dir().'/hotel-knowledge-http-'.bin2hex(random_bytes(8));
    if(!mkdir($directory,0700))throw new RuntimeException('FAIL hotel HTTP fixture directory');
    $process=null;$log=null;
    try {
        [$pdo,$publication]=draftHotelFixture();
        $source=$publication['offers'][0]['import_source'];
        draftWorkingSave($pdo,[array_replace($source['fields'],['id'=>str_repeat('4',32),'selected'=>true,
            'source_url'=>$source['url'],'observed_at'=>$source['observed_at'],'import_source'=>$source])]);
        $database=$directory.'/fixture.sqlite';
        $pdo->exec('VACUUM INTO '.$pdo->quote($database));
        $web=$directory.'/web';$sessions=$directory.'/sessions';
        mkdir($web.'/manager/lib',0700,true);mkdir($sessions,0700);
        foreach(['manager/hotel-knowledge-api.php','manager/tour-selection-editor-api.php','manager/lib/ManagerHttp.php'] as $file) {
            if(!copy($root.'/'.$file,$web.'/'.$file))throw new RuntimeException('FAIL hotel HTTP endpoint copy');
            if(hash_file('sha256',$root.'/'.$file)!==hash_file('sha256',$web.'/'.$file))throw new RuntimeException('FAIL hotel HTTP endpoint bytes');
        }
        foreach(['services','knowledge'] as $name)if(!symlink($root.'/'.$name,$web.'/'.$name))throw new RuntimeException('FAIL hotel HTTP service link');
        $config="<?php\nrequire_once ".var_export($root.'/services/ManagerHotelKnowledgeReadService.php',true).";\n";
        $config.='(new ReflectionProperty(ConversationDb::class,\'pdo\'))->setValue(null,new PDO('.var_export('sqlite:'.$database,true).",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]));\n";
        $config.="ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic project']]);\n";
        file_put_contents($web.'/config.php',$config);
        $csrf='fixture-hotel-csrf';$sessionIds=[];
        // The surrounding CLI suite already printed output. Write synthetic
        // PHP-session bytes without changing its global session or headers.
        foreach([1,2,3] as $actor) {
            $sessionIds[$actor]=bin2hex(random_bytes(16));
            file_put_contents($sessions.'/sess_'.$sessionIds[$actor],'manager_id|'.serialize($actor).'csrf|'.serialize($csrf));
            chmod($sessions.'/sess_'.$sessionIds[$actor],0600);
        }
        $db=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $snapshot=static function()use($db):array {
            $out=[];
            foreach(['messages','conversation_events','conversations','manager_conversation_reads','manager_tour_selection_drafts','manager_tour_selection_publications'] as $table) {
                $out[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();
            }
            return $out;
        };
        $before=$snapshot();
        $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
        if(!$listener)throw new RuntimeException('FAIL hotel HTTP loopback listener');
        $address=stream_socket_get_name($listener,false);fclose($listener);
        $log=fopen($directory.'/server.log','w');
        $process=proc_open([PHP_BINARY,'-d','sys_temp_dir='.sys_get_temp_dir(),'-d','session.save_path='.$sessions,
            '-d','session.serialize_handler=php','-S',$address,'-t',$web],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);
        if(!is_resource($process))throw new RuntimeException('FAIL hotel HTTP server start');
        fclose($pipes[0]);$ready=false;
        for($i=0;$i<100;$i++) {
            if($socket=@stream_socket_client('tcp://'.$address,$errno,$error,.05)){fclose($socket);$ready=true;break;}
            if(!proc_get_status($process)['running'])break;
            usleep(20000);
        }
        if(!$ready)throw new RuntimeException('FAIL hotel HTTP server readiness');
        $request=static function(?int $actor,array $input,string $method='POST',string $endpoint='hotel-knowledge-api.php')use($address,$sessionIds):array {
            $headers=['Content-Type: application/json'];
            if($actor!==null)$headers[]='Cookie: anytour_manager_panel='.$sessionIds[$actor];
            $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),
                'content'=>json_encode($input,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>5]]);
            $body=file_get_contents('http://'.$address.'/manager/'.$endpoint,false,$context);
            $status=0;foreach($http_response_header??[] as $header)if(preg_match('/^HTTP\/\S+ (\d+)/',$header,$m))$status=(int)$m[1];
            return [$status,json_decode((string)$body,true),$body,$http_response_header??[]];
        };
        $draft=['conversation_id'=>1,'csrf'=>$csrf,'kind'=>'working_draft','draft_version'=>1,'offer_id'=>str_repeat('4',32)];
        [$status,$result]=$request(null,[],'GET');
        draftContextCheck('actual hotel HTTP endpoint rejects GET before private access',$status===405&&($result['error']??'')==='method_not_allowed');
        [$status,$result,$body]=$request(null,$draft);
        draftContextCheck('actual hotel HTTP endpoint requires a current manager session',$status===401&&($result['error']??'')==='unauthorized'&&!str_contains($body,'hotel_reference'));
        [$status,$result]=$request(1,array_replace($draft,['csrf'=>'wrong']));
        draftContextCheck('actual hotel HTTP read requires CSRF',$status===403&&($result['error']??'')==='csrf');
        [$status,$result,$body]=$request(3,$draft);
        draftContextCheck('actual hotel HTTP read denies a foreign project manager without facts',$status===403&&($result['error']??'')==='forbidden'&&!str_contains($body,'hotel_reference'));
        [$status,$result]=$request(1,$draft+['hotel_id'=>1554]);
        draftContextCheck('actual hotel HTTP rejects caller hotel identity and unknown fields',$status===422&&($result['error']??'')==='invalid_selection');
        $wrapped=$draft+['action'=>'read','editor_actor'=>1,'editor_resource'=>'hotel-knowledge-api.php'];
        [$status,$result,$body]=$request(1,$wrapped,'POST','tour-selection-editor-api.php');
        draftContextCheck('actual editor gateway returns scoped hotel facts through the read boundary',$status===200&&($result['hotel_reference']['status']??'')==='available'&&($result['viewer_id']??0)===1);
        [$status,$result,$body]=$request(1,array_replace($wrapped,['editor_actor'=>2]),'POST','tour-selection-editor-api.php');
        draftContextCheck('actual editor gateway refuses stale actor without private facts',$status===409&&($result['error']??'')==='stale_actor'&&!str_contains($body,'hotel_reference'));
        [$status,$result,$body]=$request(1,array_replace($wrapped,['editor_resource'=>'unknown.php']),'POST','tour-selection-editor-api.php');
        draftContextCheck('actual editor gateway keeps its resource allowlist',$status===422&&($result['error']??'')==='invalid_editor_resource'&&!str_contains($body,'hotel_reference'));
        [$status,$result]=$request(1,$draft+['action'=>'read']);
        draftContextCheck('hotel HTTP accepts the existing manager client read envelope',$status===200&&($result['hotel_reference']['status']??'')==='available');
        [$status,$result,$body]=$request(1,$draft+['action'=>'save']);
        draftContextCheck('hotel HTTP refuses non-read action without facts',$status===400&&($result['error']??'')==='unknown_action'&&!str_contains($body,'hotel_reference'));
        [$status,$result]=$request(1,array_replace($draft,['draft_version'=>2]));
        draftContextCheck('actual hotel HTTP returns conflict for an outdated exact draft',$status===409&&($result['error']??'')==='selection_changed');
        [$status,$result,$body,$headers]=$request(1,$draft);
        $reference=$result['hotel_reference']??[];
        $expected=json_decode(file_get_contents($root.'/knowledge/hotels/anytour-1554-family-suite.json'),true,32,JSON_THROW_ON_ERROR);
        draftContextCheck('actual hotel HTTP exposes exact accepted facts and sources for the authorized saved offer',
            $status===200&&($result['ok']??false)&&($result['viewer_id']??0)===1
            &&($reference['hotel_ref']??null)===['catalog'=>'anytour','id'=>1554]
            &&($reference['facts']??null)===$expected['facts']&&($reference['sources']??null)===$expected['sources']
            &&($reference['material_limits']??null)===$expected['material_limits']);
        draftContextCheck('actual hotel HTTP preserves unknown trip choice price availability and tariff equivalence',
            ($result['current_trip_link']??null)===false&&($result['customer_choice_verified']??null)===false
            &&($result['offer_conditions_verified']??null)===false&&($result['requires_manager_review']??null)===true
            &&($reference['current_price_verified']??null)===false&&($reference['availability_verified']??null)===false
            &&($reference['room_tariff_equivalence_verified']??null)===false&&($reference['meal_plan_equivalence_verified']??null)===false
            &&($reference['future_operation_verified']??null)===false);
        draftContextCheck('actual hotel HTTP does not expose private donor source URL or cacheable response',
            !str_contains($body,'PRIVATE')&&!str_contains($body,'pro.tourvisor.ru')&&!isset($result['messages'],$result['import_source'])
            &&count(array_filter($headers,static fn(string $h):bool=>stripos($h,'Cache-Control:')===0
                &&in_array('no-store',array_map('trim',explode(',',strtolower(substr($h,14)))),true)))===1);
        $pub=['conversation_id'=>1,'csrf'=>$csrf,'kind'=>'publication','publication_id'=>str_repeat('a',32),'card_index'=>0];
        [$status,$result,$body]=$request(1,$pub);
        draftContextCheck('actual hotel HTTP cannot read a publication through another conversation',
            $status===422&&($result['error']??'')==='selection_unavailable'&&!str_contains($body,'hotel_reference'));
        [$status,$result]=$request(1,array_replace($pub,['conversation_id'=>2]));
        draftContextCheck('actual hotel HTTP reads the exact frozen card within its own authorized conversation',
            $status===200&&($result['hotel_reference']['facts']??null)===$reference['facts']
            &&($result['hotel_reference']['selector_provenance']['kind']??'')==='publication'
            &&($result['hotel_reference']['selector_provenance']['card_index']??null)===0
            &&($result['current_trip_link']??null)===false);
        draftContextCheck('actual hotel HTTP reads preserve all synthetic source and read-marker rows',$snapshot()===$before);
    } catch(Throwable $error) {
        if(is_file($directory.'/server.log'))fwrite(STDERR,substr((string)file_get_contents($directory.'/server.log'),-3000));
        throw $error;
    } finally {
        if(is_resource($process)){proc_terminate($process);proc_close($process);}
        if(is_resource($log))fclose($log);
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isLink()||!$file->isDir())unlink($file->getPathname());else rmdir($file->getPathname());}
        rmdir($directory);
    }
}
