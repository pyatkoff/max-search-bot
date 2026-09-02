<?php

declare(strict_types=1);

/**
 * Browser-side Metrika bridge for user-facing tracking redirects.
 *
 * Redirect endpoints used to answer with an immediate HTTP 302, so the
 * browser never executed the Yandex Metrika tag on app.anytoour.ru. This
 * helper renders a tiny intermediate page, initializes the existing public
 * counter from METRIKA_COUNTER_ID and then continues to the already-validated
 * destination. The redirect remains fail-open when the counter/tag is absent
 * or blocked.
 */
final class MetrikaRedirectPage
{
    public static function counterId(): int
    {
        if (!defined('METRIKA_COUNTER_ID')) {
            return 0;
        }

        $raw = trim((string)METRIKA_COUNTER_ID);
        if ($raw === '' || !preg_match('/^[1-9][0-9]*$/', $raw)) {
            return 0;
        }

        return (int)$raw;
    }

    public static function html(string $target, string $title = 'Переходим…'): string
    {
        $counterId = self::counterId();
        $targetJson = json_encode($target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $titleHtml = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $targetHtml = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($targetJson === false) {
            $targetJson = '"/"';
        }

        $metrika = '';
        if ($counterId > 0) {
            $metrika = <<<HTML
<script>
(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
 m[i].l=1*new Date();k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
(window,document,'script','https://mc.yandex.ru/metrika/tag.js','ym');
ym({$counterId},'init',{clickmap:true,trackLinks:true,accurateTrackBounce:true,webvisor:false});
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/{$counterId}" style="position:absolute;left:-9999px" alt=""></div></noscript>
HTML;
        }

        // Give the async Metrika tag a short opportunity to dispatch its hit,
        // but never make analytics availability a dependency of navigation.
        return <<<HTML
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{$titleHtml}</title>
{$metrika}
<script>
(function(){
  var target={$targetJson};
  var done=false;
  function go(){if(done)return;done=true;window.location.replace(target);}
  window.addEventListener('load',function(){window.setTimeout(go,120);},{once:true});
  window.setTimeout(go,650);
})();
</script>
<noscript><meta http-equiv="refresh" content="0;url={$targetHtml}"></noscript>
</head>
<body><p>Переходим…</p></body>
</html>
HTML;
    }

    public static function send(string $target, string $title = 'Переходим…'): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo self::html($target, $title);
    }
}
