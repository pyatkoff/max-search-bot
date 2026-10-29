from pathlib import Path
import json
p=Path('tests/run_manager_http_boundary_regression.php')
s=p.read_text()
old="    if(in_array(basename($managerEndpoint),['ai-mcp.php','development-mcp.php'],true))continue;"
new=r'''    $name=basename($managerEndpoint);
    // Exact fixed wrappers delegate to independently checked shared boundaries.
    // Do not exempt arbitrary files or replace bearer auth with browser sessions.
    $isolated=['replies-mcp.php'=>['replies','SeparatedMcpHttp.php'],
        'hotels-mcp.php'=>['hotels','SeparatedMcpHttp.php'],
        'replies-oauth.php'=>['replies','SeparatedMcpOAuthHttp.php'],
        'hotels-oauth.php'=>['hotels','SeparatedMcpOAuthHttp.php']];
    if(isset($isolated[$name])) {
        [$profile,$helper]=$isolated[$name];
        if(!str_contains($source,"\$surfaceProfile='".$profile."';")
            ||!str_contains($source,"require __DIR__.'/lib/".$helper."';")
            ||str_contains($source,'$_GET')||str_contains($source,'$_POST')||str_contains($source,'$_REQUEST')) $unbounded[]=$name;
        continue;
    }
    if(in_array($name,['ai-mcp.php','development-mcp.php'],true))continue;'''
if s.count(old)!=1: raise SystemExit('Boundary inventory marker mismatch')
s=s.replace(old,new)
marker="mhCheck('all session Manager PHP interfaces enter through ManagerHttp',$unbounded===[]);"
extra=r'''
$isolatedHttp=(string)file_get_contents($root.'/manager/lib/SeparatedMcpHttp.php');
$isolatedOauth=(string)file_get_contents($root.'/manager/lib/SeparatedMcpOAuthHttp.php');
$isolatedService=(string)file_get_contents($root.'/services/SeparatedMcpService.php');
mhCheck('isolated MCP wrappers keep a fixed OAuth audience before dispatch',
    str_contains($isolatedHttp,"in_array(\$surfaceProfile,['replies','hotels'],true)")
    &&str_contains($isolatedHttp,'new ManagerPreparationOAuth(null,null,$surfaceProfile)')
    &&str_contains($isolatedHttp,'->contextForBearer($header)')
    &&str_contains($isolatedHttp,'if ($context===null)')
    &&str_contains($isolatedHttp,'WWW-Authenticate: Bearer resource_metadata=')
    &&strpos($isolatedHttp,'->contextForBearer($header)')<strpos($isolatedHttp,'new SeparatedMcpService($surfaceProfile)')
    &&str_contains($isolatedHttp,"\$context['admin_id'],\$context['connection_id'],\$context['scopes']")
    &&!str_contains($isolatedHttp,'ManagerHttp::start'));
mhCheck('isolated OAuth browser routes retain manager session and CSRF boundary',
    str_contains($isolatedOauth,"in_array(\$surfaceProfile,['replies','hotels'],true)")
    &&str_contains($isolatedOauth,"require_once __DIR__.'/ManagerHttp.php'")
    &&str_contains($isolatedOauth,'ManagerHttp::start();')
    &&str_contains($isolatedOauth,'ManagerHttp::managerId()')
    &&str_contains($isolatedOauth,'ManagerHttp::csrf(true)')
    &&str_contains($isolatedOauth,'$oauth->disconnect($_SESSION,$adminId,')
    &&str_contains($isolatedOauth,'$oauth->completeAuthorization($_SESSION,'));
mhCheck('isolated command and event restrictions are checked before delegated calls',
    str_contains($isolatedService,'private const REPLY_TOOLS=')
    &&str_contains($isolatedService,'private function authorize(')
    &&str_contains($isolatedService,'connectionAuthorized(')
    &&str_contains($isolatedService,'throw new OutOfBoundsException()'));
'''
if s.count(marker)!=1: raise SystemExit('Boundary assertion marker mismatch')
p.write_text(s.replace(marker,marker+extra))
paths=json.loads(Path('split_review_paths.json').read_text())
paths.append(str(p))
Path('split_review_paths.json').write_text(json.dumps(paths))
print(json.dumps({'boundary_test_updated':str(p),'production_operations':0}))
