<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerPreparationOAuth.php';

header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
// Browsers may enforce form-action on the authorization POST's redirect too.
// Permit only the server-pinned callback, never a request-supplied destination.
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self' ".ManagerPreparationOAuth::REDIRECT_URI."; frame-ancestors 'none'; base-uri 'none'");

function preparationOAuthJson(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    exit;
}

/** Reject duplicate/bracketed parameters before PHP can silently collapse them. */
function preparationOAuthForm(string $raw): array
{
    if (strlen($raw)>16384) throw new InvalidArgumentException('invalid_request');
    $result=[];
    foreach (explode('&',$raw) as $part) {
        if ($part==='') continue;
        [$name,$value]=array_pad(explode('=',$part,2),2,'');
        $name=urldecode($name);
        if (!preg_match('/\A[a-z_]+\z/D',$name) || array_key_exists($name,$result)) throw new InvalidArgumentException('invalid_request');
        $result[$name]=urldecode($value);
    }
    return $result;
}

function preparationOAuthHtml(string $title, string $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</title>';
    echo '<style>body{font:17px/1.55 system-ui,sans-serif;color:#18332b;background:#f6f8f7;margin:0;padding:28px}main{max-width:650px;margin:30px auto;padding:28px;background:white;border-radius:18px}h1{font-size:25px;line-height:1.25}button,a{font:inherit}button{padding:12px 18px;border:1px solid #315b4e;border-radius:10px;background:#17664c;color:white;margin:8px 8px 0 0}button[value=deny]{background:white;color:#315b4e}a{color:#17664c}small{color:#50655d}form{margin:20px 0}li{margin:10px 0}</style><main><h1>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1>'.$body.'</main></html>';
    exit;
}

function preparationOAuthEscape(string $value): string { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }

try {
    $invalidReason='authorization_request_invalid';
    $query=preparationOAuthForm((string)($_SERVER['QUERY_STRING']??''));
    $action=$query['action']??'';
    $method=(string)($_SERVER['REQUEST_METHOD']??'');
    if (in_array($action,['resource','metadata'],true)) {
        if ($method!=='GET') preparationOAuthJson(['error'=>'invalid_request'],405);
        preparationOAuthJson($action==='resource'?ManagerPreparationOAuth::resourceMetadata():ManagerPreparationOAuth::authorizationMetadata());
    }
    if (!in_array($action,['register','token','revoke','authorize','connections'],true)) preparationOAuthJson(['error'=>'invalid_request'],400);
    if (($action==='authorize' || $action==='connections') ? !in_array($method,['GET','POST'],true) : $method!=='POST') {
        header('Allow: '.(in_array($action,['authorize','connections'],true)?'GET, POST':'POST'));
        preparationOAuthJson(['error'=>'invalid_request'],405);
    }
    require_once dirname(__DIR__).'/config.php';
    $oauth=new ManagerPreparationOAuth();
    $body=[];
    if ($method==='POST') {
        if ($action==='authorize') $invalidReason='consent_form_invalid';
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>16384) preparationOAuthJson(['error'=>'invalid_request'],413);
        $raw=(string)file_get_contents('php://input',false,null,0,16385);
        if (strlen($raw)>16384) preparationOAuthJson(['error'=>'invalid_request'],413);
        $contentType=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''),2)[0]));
        if ($action==='register') {
            if ($contentType!=='application/json') preparationOAuthJson(['error'=>'invalid_client_metadata'],415);
            $body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if (!is_array($body) || array_is_list($body)) preparationOAuthJson(['error'=>'invalid_client_metadata'],400);
        } else {
            if ($contentType!=='application/x-www-form-urlencoded') preparationOAuthJson(
                $action==='authorize' ? ['error'=>'invalid_request','error_description'=>'consent_form_encoding'] : ['error'=>'invalid_request'],415);
            $body=preparationOAuthForm($raw);
        }
    }
    if ($action==='register') preparationOAuthJson($oauth->registerClient($body,(string)($_SERVER['REMOTE_ADDR']??'unknown')),201);
    if ($action==='token') {
        // Only public-client body identification plus PKCE is supported.
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) preparationOAuthJson(['error'=>'invalid_client'],400);
        preparationOAuthJson($oauth->exchange($body));
    }
    if ($action==='revoke') {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) preparationOAuthJson(['error'=>'invalid_client'],400);
        $oauth->revoke($body);
        preparationOAuthJson([]);
    }

    require_once __DIR__.'/lib/ManagerHttp.php';
    ManagerHttp::start();
    $adminId=ManagerHttp::managerId();
    $csrf=ManagerHttp::csrf(true);
    if ($action==='connections') {
        if ($adminId<=0) preparationOAuthHtml('Войдите в кабинет администратора','<p><a href="/manager/">Открыть вход</a></p><p>После входа вернитесь на эту страницу.</p>',401);
        if ($method==='POST') {
            $oauth->disconnect($_SESSION,$adminId,(string)($body['grant_id']??''),(string)($body['csrf']??''));
            header('Location: /manager/ai-oauth.php?action=connections',true,303);
            exit;
        }
        $connections=$oauth->connections($adminId);
        $html='<p>Разрешения каждого подключения показаны отдельно. Отправка сообщений клиентам не разрешена.</p>';
        if (!$connections) $html.='<p>Активных подключений нет.</p>';
        foreach ($connections as $connection) {
            $scopes=explode(' ',$connection['scopes']);
            $rights=[];
            if (ManagerPreparationOAuth::hasScopes($scopes,ManagerPreparationOAuth::SCOPES)) $rights[]='очередь диалогов и подготовленные ответы';
            if (in_array(ManagerPreparationOAuth::HOTEL_SCOPE,$scopes,true)) $rights[]='очередь гостиничных исследований';
            if (in_array(ManagerPreparationOAuth::DEVELOPMENT_SCOPE,$scopes,true)) $rights[]='очередь плана развития';
            if (in_array(ManagerPreparationOAuth::ARCHIVE_SCOPE,$scopes,true)) $rights[]='чтение сохранённых исторических Telegram-диалогов для исследования';
            if (in_array(ManagerPreparationOAuth::FORECAST_SCOPE,$scopes,true)) $rights[]='закрытый агрегированный статус сигналов и ранних оценок';
            $html.='<form method="post" action="/manager/ai-oauth.php?action=connections"><p>Подключено '.gmdate('d.m.Y H:i',(int)$connection['created_at']).' UTC: '.preparationOAuthEscape(implode('; ',$rights)).'</p><input type="hidden" name="csrf" value="'.preparationOAuthEscape($csrf).'"><input type="hidden" name="grant_id" value="'.preparationOAuthEscape($connection['grant_id']).'"><button type="submit">Отключить</button></form>';
        }
        preparationOAuthHtml('Подключения ChatGPT',$html);
    }
    if ($method==='POST') {
        if (!array_key_exists('decision',$body)) throw new ManagerPreparationOAuthRequestException('consent_decision_missing');
        if (!in_array($body['decision'],['allow','deny'],true)) throw new ManagerPreparationOAuthRequestException('consent_decision_invalid');
        $invalidReason='consent_context_invalid';
        $target=$oauth->completeAuthorization($_SESSION,(string)($body['pending_id']??''),$adminId,(string)($body['csrf']??''),$body['decision']==='allow');
        session_write_close();
        header('Location: '.$target,true,303);
        exit;
    }
    $pendingId=isset($query['resume']) ? $query['resume'] : $oauth->beginAuthorization($_SESSION,$query);
    $authorization=$oauth->pendingAuthorization($_SESSION,$pendingId,$adminId);
    if ($adminId<=0) {
        $resume='/manager/ai-oauth.php?action=authorize&resume='.rawurlencode($pendingId);
        preparationOAuthHtml('Подключение AnyTour к ChatGPT','<p>Для подключения нужен действующий аккаунт администратора AnyTour.</p><ol><li><a href="/manager/" target="_blank" rel="noopener noreferrer">Войти в кабинет</a></li><li>После входа <a href="'.preparationOAuthEscape($resume).'">продолжить подключение</a>.</li></ol><p><small>Запрос действует 10 минут. Доступ появится после вашего подтверждения на следующем шаге.</small></p>');
    }
    $scopes=explode(' ',$authorization['scope']);
    $hotel=in_array(ManagerPreparationOAuth::HOTEL_SCOPE,$scopes,true);
    $development=in_array(ManagerPreparationOAuth::DEVELOPMENT_SCOPE,$scopes,true);
    $archive=in_array(ManagerPreparationOAuth::ARCHIVE_SCOPE,$scopes,true);
    $forecast=in_array(ManagerPreparationOAuth::FORECAST_SCOPE,$scopes,true);
    $permissions='';
    if (ManagerPreparationOAuth::hasScopes($scopes,ManagerPreparationOAuth::SCOPES)) $permissions.='<li>Чтение очереди и контекста диалогов, которым нужен ответ.</li><li>Сохранение подготовленных ответов для проверки менеджером.</li>';
    if ($hotel) $permissions.='<li>Управление очередью гостиничных исследований: создание плана, допуск этапов, сохранение состояния и подтверждений выполнения.</li><li>Получение событий готовности гостиничной очереди. Гостиничные факты и файлы общей базы это подключение не изменяет.</li>';
    if ($development) $permissions.='<li>Управление метаданными плана развития: очередь задач, привязка существующего исполнителя, состояние запусков и квитанции независимой приёмки.</li><li>Получение событий готовности следующей задачи. Это подключение само не меняет код, не выпускает сайт и не запускает внешние задания.</li>';
    if ($archive) $permissions.='<li>Чтение сохранённых исторических Telegram-диалогов, включая сообщения туристов и менеджеров, для исследования интереса, оформления и оплаты.</li><li>Архив читается ограниченными страницами. Это разрешение не отправляет сообщения, не отмечает их прочитанными, не запускает обучение и не меняет архив.</li>';
    if ($forecast) $permissions.='<li>Чтение закрытого агрегированного статуса сохранённых сигналов и ранних оценок AnyTour.</li><li>Просмотр ограничен сохранёнными аналитическими данными. Тексты, контакты и данные отдельных клиентов не возвращаются; переписки, прочитанность и сообщения клиентам не изменяются.</li>';
    preparationOAuthHtml($forecast?'Разрешить закрытый просмотр ранних оценок AnyTour в ChatGPT?':($archive?'Разрешить исследование архива AnyTour в ChatGPT?':(($hotel||$development)?'Разрешить работу с очередями AnyTour в ChatGPT?':'Разрешить подготовку ответов в ChatGPT?')),
        '<p>Вы подключаете ChatGPT к текущему проекту AnyTour от своего аккаунта администратора.</p><ul>'.$permissions.'</ul><p>Сообщения клиентам отправляет менеджер. Это подключение не разрешает отправку, смену ответственного или изменение переписки.</p><p>Доступ можно отключить в <a href="/manager/ai-oauth.php?action=connections">подключениях ChatGPT</a>. Подключение действует до 30 дней, затем потребуется новое подтверждение.</p><form method="post" action="/manager/ai-oauth.php?action=authorize"><input type="hidden" name="pending_id" value="'.preparationOAuthEscape($pendingId).'"><input type="hidden" name="csrf" value="'.preparationOAuthEscape($csrf).'"><button type="submit" name="decision" value="allow">Разрешить</button><button type="submit" name="decision" value="deny">Отмена</button></form>');
} catch (JsonException $error) {
    preparationOAuthJson(['error'=>'invalid_request'],400);
} catch (InvalidArgumentException $error) {
    $allowed=['invalid_request','invalid_client','invalid_client_metadata','invalid_target','invalid_scope','unsupported_grant_type','invalid_grant','access_denied','csrf','temporarily_unavailable'];
    $code=in_array($error->getMessage(),$allowed,true)?$error->getMessage():'invalid_request';
    $response=['error'=>$code];
    if ($code==='invalid_request' && ($action??null)==='authorize') {
        $response['error_description']=$error instanceof ManagerPreparationOAuthRequestException ? $error->reason : $invalidReason;
    }
    preparationOAuthJson($response,in_array($code,['access_denied','csrf'],true)?403:($code==='temporarily_unavailable'?429:400));
} catch (Throwable $error) {
    // No authorization code, token, state, transcript, credential or exception text in logs.
    error_log('MANAGER_PREPARATION_OAUTH_UNAVAILABLE '.get_class($error));
    preparationOAuthJson(['error'=>'temporarily_unavailable'],503);
}
