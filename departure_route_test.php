<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/DepartureRouteAdvisor.php';

$resolver = new DepartureRouteAdvisor();

$departure = isset($_GET['departure']) ? trim((string)$_GET['departure']) : 'Калининград';
$country   = isset($_GET['country']) ? trim((string)$_GET['country']) : '';
$period    = isset($_GET['period']) ? trim((string)$_GET['period']) : '2026-12';

$result = null;
$error = null;

if (isset($_GET['run'])) {
    try {
        $result = $resolver->resolve(
            $departure,
            $country !== '' ? $country : null,
            $period !== '' ? $period : null
        );
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>DepartureRouteResolver test</title>
<style>
body{font-family:Arial,sans-serif;max-width:1100px;margin:30px auto;padding:0 15px}
input{padding:8px;margin:4px;min-width:220px}
button{padding:9px 15px}
pre{background:#f5f5f5;padding:15px;border-radius:8px;overflow:auto}
.err{background:#ffe8e8;padding:12px}
.ok{background:#eef8ee;padding:12px;border-radius:8px;margin:15px 0}
</style>
</head>
<body>
<h1>DepartureRouteResolver test</h1>

<form>
    <input type="hidden" name="run" value="1">
    <input name="departure" value="<?=h($departure)?>" placeholder="Калининград">
    <input name="country" value="<?=h($country)?>" placeholder="Страна или пусто">
    <input name="period" value="<?=h($period)?>" placeholder="2026-12">
    <button type="submit">Проверить</button>
</form>

<p>
Примеры:
<a href="?run=1&departure=Калининград&period=2026-12">Калининград, декабрь</a> |
<a href="?run=1&departure=Калининград&country=Таиланд&period=2026-12">Калининград → Таиланд, декабрь</a> |
<a href="?run=1&departure=Ярославль&period=2026-10">Ярославль, октябрь</a> |
<a href="?run=1&departure=Ярославль&country=Египет&period=2026-12">Ярославль → Египет, декабрь</a>
</p>

<?php if ($error): ?>
<div class="err"><?=h($error)?></div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <?php if (($result['status'] ?? '') === 'fallback_destinations'): ?>
        <div class="ok">
            Прямых чартерных направлений из <?=h($result['departure'] ?? $departure)?>
            на <?=h($result['period'] ?? '')?> не найдено.
            Использован fallback: <b><?=h($result['fallback_departure'] ?? '')?></b>.
        </div>
    <?php endif; ?>
<pre><?=h(json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT))?></pre>
<?php endif; ?>

</body>
</html>
