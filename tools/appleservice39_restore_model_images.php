<?php
declare(strict_types=1);
ini_set('display_errors', '0');

// One finite, owner-requested media correction. Existing Store/transport own
// every attempt. No create, pin, application install, new queue or retry exists.
const AM_ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';
const AM_APP = 'e5c93a27aa46f197a3d2e02e7a536ed5de8cd672';
const AM_CHANNEL = '-80917954947727';
const AM_PIN = 'mid.ffffb667d20a157101a1bd6d233963da';
const AM_REVISION = 'restore-approved-model-images-v1';
const AM_PREFIX = 'img1-';
const AM_MAP_SHA256 = '12389007bf83ae9ea324905c2fa76fff5a2cd36473144d48290c259f5384d277';
const AM_MAP_BASE64 = 'eyJyZXZpc2lvbiI6InJlc3RvcmUtYXBwcm92ZWQtbW9kZWwtaW1hZ2VzLXYxIiwic291cmNlX21hbmlmZXN0X3NoYTI1NiI6IjE2Zjk5ODc2MzM3YjZkNTUyMzIzMDM5ZWE5MzcyM2JhZTU1YTY4NjdkMDY4Y2ZjMjE1NTAzMmU1NGM0MWE5ZDMiLCJhc3NldHMiOnsiaXBob25lLTgtZmFtaWx5Ijp7ImtleSI6ImlwaG9uZS04LWZhbWlseSIsImJ5dGVzIjoxNTczMzUyLCJzaGEyNTYiOiI0MzhkZjEyZjJjMjhhNDhlYTA1OTIzZTI4OTY2ZGZkNGNjMDg1MWQ3NjUzYjk4ODM2NDUwYzMzYjUwYTEyZGFlIiwid2lkdGgiOjEyNTQsImhlaWdodCI6MTI1NCwidXJsIjoiaHR0cHM6Ly90b3VyLW1heC5ydS9uZXcvYXBwbGVzZXJ2aWNlMzkvY292ZXJzL2FkLWludGVudC12MS9pcGhvbmUtOC1mYW1pbHkucG5nIn0sImlwaG9uZS14LWZhbWlseSI6eyJrZXkiOiJpcGhvbmUteC1mYW1pbHkiLCJieXRlcyI6MTc0OTA3MSwic2hhMjU2IjoiZDMwMzQ0NjM2MzllOWFlN2UyZGJkZWFhYjYzZjczM2YwMzdjMjNkNDA4ZmNiMjViOTgxNTkwMGFmNTdkMjFkZiIsIndpZHRoIjoxMjU0LCJoZWlnaHQiOjEyNTQsInVybCI6Imh0dHBzOi8vdG91ci1tYXgucnUvbmV3L2FwcGxlc2VydmljZTM5L2NvdmVycy9hZC1pbnRlbnQtdjEvaXBob25lLXgtZmFtaWx5LnBuZyJ9LCJpcGhvbmUtMTEtZmFtaWx5Ijp7ImtleSI6ImlwaG9uZS0xMS1mYW1pbHkiLCJieXRlcyI6MTU0Nzg5OSwic2hhMjU2IjoiMTRiMmZlOTc5NmE1YzAyZTlhOGMxNTdlNzFjNjgzMDJiZjFlNzA5MmI4MjUxMDA4ZDc4NWZlYmVmZTVkODU1YiIsIndpZHRoIjoxMjU0LCJoZWlnaHQiOjEyNTQsInVybCI6Imh0dHBzOi8vdG91ci1tYXgucnUvbmV3L2FwcGxlc2VydmljZTM5L2NvdmVycy9hZC1pbnRlbnQtdjEvaXBob25lLTExLWZhbWlseS5wbmcifSwiaXBob25lLTEyLWZhbWlseSI6eyJrZXkiOiJpcGhvbmUtMTItZmFtaWx5IiwiYnl0ZXMiOjE2NDIwMDAsInNoYTI1NiI6ImE5ZDM0MTY1ZjBhN2MxMTA4Mzc3YjBjNDJjNDc3MWI0MjVhYTdjYjRjYWExMzMwYzA4YTkwNjA1Y2UxYjVjZGMiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL2lwaG9uZS0xMi1mYW1pbHkucG5nIn0sImlwaG9uZS0xMy1mYW1pbHkiOnsia2V5IjoiaXBob25lLTEzLWZhbWlseSIsImJ5dGVzIjoxNTkwNDYxLCJzaGEyNTYiOiJiZDMwZjkyZDFkMTA1OTU2NWQ0M2VkNWI0NmIwNDhiMTUzM2FkMzFmNTIzMTYxMjcyODA0M2Y1ZDg4ZDQ3ODQ4Iiwid2lkdGgiOjEyNTQsImhlaWdodCI6MTI1NCwidXJsIjoiaHR0cHM6Ly90b3VyLW1heC5ydS9uZXcvYXBwbGVzZXJ2aWNlMzkvY292ZXJzL2FkLWludGVudC12MS9pcGhvbmUtMTMtZmFtaWx5LnBuZyJ9LCJpcGhvbmUtMTQtZmFtaWx5Ijp7ImtleSI6ImlwaG9uZS0xNC1mYW1pbHkiLCJieXRlcyI6MTYxNzkzMSwic2hhMjU2IjoiNTVjZjdlOWEyZmI2NDBjM2E2OGNjMTcyMGQ4YTk1NWMzYmE3MTgxMjZlMDdhMjIzODRkMjVhODA1NzZkNWU2NCIsIndpZHRoIjoxMjU0LCJoZWlnaHQiOjEyNTQsInVybCI6Imh0dHBzOi8vdG91ci1tYXgucnUvbmV3L2FwcGxlc2VydmljZTM5L2NvdmVycy9hZC1pbnRlbnQtdjEvaXBob25lLTE0LWZhbWlseS5wbmcifSwiaXBob25lLTE2LWZhbWlseSI6eyJrZXkiOiJpcGhvbmUtMTYtZmFtaWx5IiwiYnl0ZXMiOjE2MjU4MDEsInNoYTI1NiI6ImFjZjQ2NDM2NWI2NDE3YmE3YmUwMDYxMTkyYjk2NjgxMjhlYTAxZmIxOGE2ZjY2ZmQ5YjI2OWU2OWU2YjUwYTAiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL2lwaG9uZS0xNi1mYW1pbHkucG5nIn0sImlwaG9uZS0xNy1wcm8tZmFtaWx5Ijp7ImtleSI6ImlwaG9uZS0xNy1wcm8tZmFtaWx5IiwiYnl0ZXMiOjE2MDY3ODEsInNoYTI1NiI6IjgxNjFjYWNhY2FkM2M3MGJlNTZmYTNiMTk2OWVmZWY1MDVjZGI1MGViNTVmYTE5MTJjYjBkODc5MGUwMWU3OWQiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL2lwaG9uZS0xNy1wcm8tZmFtaWx5LnBuZyJ9LCJpcGhvbmUtYWlyIjp7ImtleSI6ImlwaG9uZS1haXIiLCJieXRlcyI6MTU4NzM4Niwic2hhMjU2IjoiMTZkZWY3NGI0NDQ2NmQyZTRiMzZjYzczN2M4MThhNTk4N2NhM2I0NjNmM2YwZmVhZmI1ZmU1MmFhNTM2NjYyMyIsIndpZHRoIjoxMjU0LCJoZWlnaHQiOjEyNTQsInVybCI6Imh0dHBzOi8vdG91ci1tYXgucnUvbmV3L2FwcGxlc2VydmljZTM5L2NvdmVycy9hZC1pbnRlbnQtdjEvaXBob25lLWFpci5wbmcifSwiY2hhcmdpbmctcG9ydCI6eyJrZXkiOiJjaGFyZ2luZy1wb3J0IiwiYnl0ZXMiOjE3NjUwMDAsInNoYTI1NiI6ImQzMjllMTUzMzdjMjdiOWU4NDJmYWExNTQ3ZjFiM2E1OWI4YWNkNWRiOGNjMmNhNWMxOGUyM2VjMjQ4ZTg3YjUiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL2NoYXJnaW5nLXBvcnQucG5nIn0sIm1pY3JvcGhvbmUiOnsia2V5IjoibWljcm9waG9uZSIsImJ5dGVzIjoxODAyNDE3LCJzaGEyNTYiOiJlOWU5ZWY1MTgwMTE3MWMxN2U2YzUwNjg3MDRiY2RlNjZmNmI4YWMxNmY1ZGZlYjk4MzM5MTY3YTI4NDkxNDVlIiwid2lkdGgiOjEyNTQsImhlaWdodCI6MTI1NCwidXJsIjoiaHR0cHM6Ly90b3VyLW1heC5ydS9uZXcvYXBwbGVzZXJ2aWNlMzkvY292ZXJzL2FkLWludGVudC12MS9taWNyb3Bob25lLnBuZyJ9LCJsb3Vkc3BlYWtlciI6eyJrZXkiOiJsb3Vkc3BlYWtlciIsImJ5dGVzIjoxNjUxODEzLCJzaGEyNTYiOiI5NzYzMTRlNjcxZDg2MmZmZDdkNzRkZWQ4MDRiNmUyYTdmOTk5NTQyMDJiNWU2MGY1Y2EzMWE0MTY5NGMyNzNhIiwid2lkdGgiOjEyNTQsImhlaWdodCI6MTI1NCwidXJsIjoiaHR0cHM6Ly90b3VyLW1heC5ydS9uZXcvYXBwbGVzZXJ2aWNlMzkvY292ZXJzL2FkLWludGVudC12MS9sb3Vkc3BlYWtlci5wbmcifSwiZnJvbnQtY2FtZXJhIjp7ImtleSI6ImZyb250LWNhbWVyYSIsImJ5dGVzIjoxNjExNjAxLCJzaGEyNTYiOiIwMzU5MjllNzVmNzYwYTMyYjhkNTI2NzIwNzM5YmQxNDA4MGJiNzk1Mzc4N2FkMjFmMzY5YmI4Y2IxMDY4YzVlIiwid2lkdGgiOjEyNTQsImhlaWdodCI6MTI1NCwidXJsIjoiaHR0cHM6Ly90b3VyLW1heC5ydS9uZXcvYXBwbGVzZXJ2aWNlMzkvY292ZXJzL2FkLWludGVudC12MS9mcm9udC1jYW1lcmEucG5nIn0sImhvbWUtYnV0dG9uIjp7ImtleSI6ImhvbWUtYnV0dG9uIiwiYnl0ZXMiOjE1OTY3MjYsInNoYTI1NiI6ImE2MGI1OTMwYmZkYTU4YjMwZTI3ZjBjOWM0N2Q5ZTVjMzJhNDQ5NTFlMDE5NDk5MzQ0YmVkNGFiMmNlYWQzYzQiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL2hvbWUtYnV0dG9uLnBuZyJ9LCJwb3dlci1idXR0b24iOnsia2V5IjoicG93ZXItYnV0dG9uIiwiYnl0ZXMiOjE2NTI0MTEsInNoYTI1NiI6IjE4MTQzNzBhNWM1MDg0YzVkZjhhYzJkYzFiMjc3NTkwMzkzOTA0Yzk4YmVlNmY5Njk1MDE1OTJhMGRmY2EwODciLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL3Bvd2VyLWJ1dHRvbi5wbmcifSwiYW50ZW5uYSI6eyJrZXkiOiJhbnRlbm5hIiwiYnl0ZXMiOjE1NzEyNDIsInNoYTI1NiI6IjA0NTRjN2NmNjg0N2VjNTg0OTEzMGE4YWM2MzM5Nzg5MWEzMDg4MzVmNWJhZDg3YjAyNTFhOTk3ZWUyMTM3YjAiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL2FudGVubmEucG5nIn0sInNvZnR3YXJlIjp7ImtleSI6InNvZnR3YXJlIiwiYnl0ZXMiOjE1NzYyODAsInNoYTI1NiI6ImU2MTczODZiOGE1MTFiM2ZjODBmNmNhZGE3MGI2MjJhZTBmMjM1ZmRkYTIyYzExNjU3MDA4Zjg2ZWUyNjljNWUiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL3NvZnR3YXJlLnBuZyJ9LCJkaWFnbm9zdGljcyI6eyJrZXkiOiJkaWFnbm9zdGljcyIsImJ5dGVzIjoxNzAwOTk1LCJzaGEyNTYiOiI4ZTAxYzVjNmZhOWIwYmNhZjg0MmQ4ZTJjMzg1NmIyNjJlYWU3MDFmOGNiZWNhZmViMWU5ZGVjOWUzYzFmMmJlIiwid2lkdGgiOjEyNTQsImhlaWdodCI6MTI1NCwidXJsIjoiaHR0cHM6Ly90b3VyLW1heC5ydS9uZXcvYXBwbGVzZXJ2aWNlMzkvY292ZXJzL2FkLWludGVudC12MS9kaWFnbm9zdGljcy5wbmcifSwiaG91c2luZyI6eyJrZXkiOiJob3VzaW5nIiwiYnl0ZXMiOjE2MDkzNjIsInNoYTI1NiI6IjEwYTAwYzVkYWZhODYyZmIxNzgyOWJhYjFjMmYzZDE0ZmU4MWZhZmFkY2MzNzMzMGEwMDMzNDQ3ODZmMDQwN2IiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL2hvdXNpbmcucG5nIn19LCJ0YXJnZXRzIjp7Im1vZGVsLWlwaG9uZS04IjoiaXBob25lLTgtZmFtaWx5IiwibW9kZWwtaXBob25lLTgtcGx1cyI6ImlwaG9uZS04LWZhbWlseSIsIm1vZGVsLWlwaG9uZS14ciI6ImlwaG9uZS14LWZhbWlseSIsIm1vZGVsLWlwaG9uZS14cyI6ImlwaG9uZS14LWZhbWlseSIsIm1vZGVsLWlwaG9uZS14cy1tYXgiOiJpcGhvbmUteC1mYW1pbHkiLCJtb2RlbC1pcGhvbmUtMTEiOiJpcGhvbmUtMTEtZmFtaWx5IiwibW9kZWwtaXBob25lLTExLXBybyI6ImlwaG9uZS0xMS1mYW1pbHkiLCJtb2RlbC1pcGhvbmUtMTEtcHJvLW1heCI6ImlwaG9uZS0xMS1mYW1pbHkiLCJtb2RlbC1pcGhvbmUtMTIiOiJpcGhvbmUtMTItZmFtaWx5IiwibW9kZWwtaXBob25lLTEyLW1pbmkiOiJpcGhvbmUtMTItZmFtaWx5IiwibW9kZWwtaXBob25lLTEyLXBybyI6ImlwaG9uZS0xMi1mYW1pbHkiLCJtb2RlbC1pcGhvbmUtMTItcHJvLW1heCI6ImlwaG9uZS0xMi1mYW1pbHkiLCJtb2RlbC1pcGhvbmUtMTMiOiJpcGhvbmUtMTMtZmFtaWx5IiwibW9kZWwtaXBob25lLTEzLW1pbmkiOiJpcGhvbmUtMTMtZmFtaWx5IiwibW9kZWwtaXBob25lLTEzLXBybyI6ImlwaG9uZS0xMy1mYW1pbHkiLCJtb2RlbC1pcGhvbmUtMTMtcHJvLW1heCI6ImlwaG9uZS0xMy1mYW1pbHkiLCJtb2RlbC1pcGhvbmUtMTQiOiJpcGhvbmUtMTQtZmFtaWx5IiwibW9kZWwtaXBob25lLTE0LXBsdXMiOiJpcGhvbmUtMTQtZmFtaWx5IiwibW9kZWwtaXBob25lLTE0LXBybyI6ImlwaG9uZS0xNC1mYW1pbHkiLCJtb2RlbC1pcGhvbmUtMTQtcHJvLW1heCI6ImlwaG9uZS0xNC1mYW1pbHkiLCJtb2RlbC1pcGhvbmUtMTYiOiJpcGhvbmUtMTYtZmFtaWx5IiwibW9kZWwtaXBob25lLTE2LXBsdXMiOiJpcGhvbmUtMTYtZmFtaWx5IiwibW9kZWwtaXBob25lLTE2LXBybyI6ImlwaG9uZS0xNi1mYW1pbHkiLCJtb2RlbC1pcGhvbmUtMTYtcHJvLW1heCI6ImlwaG9uZS0xNi1mYW1pbHkiLCJtb2RlbC1pcGhvbmUtMTctcHJvIjoiaXBob25lLTE3LXByby1mYW1pbHkiLCJtb2RlbC1pcGhvbmUtMTctcHJvLW1heCI6ImlwaG9uZS0xNy1wcm8tZmFtaWx5IiwibW9kZWwtaXBob25lLWFpciI6ImlwaG9uZS1haXIiLCJzZXJ2aWNlLWNoYXJnaW5nLXBvcnQtcmVwbGFjZW1lbnQiOiJjaGFyZ2luZy1wb3J0Iiwic2VydmljZS1taWNyb3Bob25lLXJlcGxhY2VtZW50IjoibWljcm9waG9uZSIsInNlcnZpY2UtbG91ZHNwZWFrZXItcmVwbGFjZW1lbnQiOiJsb3Vkc3BlYWtlciIsInNlcnZpY2UtZnJvbnQtY2FtZXJhLXJlcGxhY2VtZW50IjoiZnJvbnQtY2FtZXJhIiwic2VydmljZS1ob21lLWJ1dHRvbi1yZXBhaXIiOiJob21lLWJ1dHRvbiIsInNlcnZpY2UtcG93ZXItYnV0dG9uLXJlcGFpciI6InBvd2VyLWJ1dHRvbiIsInNlcnZpY2UtYW50ZW5uYS1yZXBhaXIiOiJhbnRlbm5hIiwic2VydmljZS1zb2Z0d2FyZS1yZWNvdmVyeSI6InNvZnR3YXJlIiwic2VydmljZS1kaWFnbm9zdGljcyI6ImRpYWdub3N0aWNzIiwic2VydmljZS1ob3VzaW5nLXJlcGFpciI6ImhvdXNpbmcifX0=';
const AM_OLD_IMAGE_SHA256 = '2f580bbf4f0963f5a37be34db455527aa8f650597801b7d0b6e81dce790a8681';
const AM_CTA = 'Узнать стоимость ремонта';

function am_ck(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
function am_emit(array $value): void { echo json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL; }
function am_canon($value) { if (!is_array($value)) return $value; if (!array_is_list($value)) ksort($value); foreach ($value as &$item) $item=am_canon($item); unset($item); return $value; }
function am_json($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function am_digest($value): string { return hash('sha256',am_json(am_canon($value))); }
function am_invoke(object $owner, string $method, ...$args) { return (new ReflectionMethod($owner,$method))->invoke($owner,...$args); }
function am_reason(Throwable $error): string { $r=$error->getMessage(); return preg_match('/\A[a-z_]{2,100}\z/D',$r) ? $r : 'media_operation_unavailable'; }
function am_map(): array {
    $bytes=base64_decode(AM_MAP_BASE64,true);
    am_ck(is_string($bytes)&&hash('sha256',$bytes)===AM_MAP_SHA256,'media_map_changed');
    $map=json_decode($bytes,true,20,JSON_THROW_ON_ERROR);
    am_ck($map['revision']===AM_REVISION&&count($map['assets'])===19&&count($map['targets'])===37,'media_map_invalid');
    $models=0;$services=0;$seen=[];
    foreach($map['assets'] as $key=>$asset) {
        am_ck(preg_match('/\A[a-z][a-z0-9-]{1,40}\z/D',$key)===1&&$asset['key']===$key
            &&preg_match('/\A[a-f0-9]{64}\z/D',$asset['sha256'])===1&&$asset['width']===1254&&$asset['height']===1254
            &&$asset['bytes']>100000&&$asset['bytes']<2097152
            &&$asset['url']==='https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/'.$key.'.png','media_asset_invalid');
    }
    foreach($map['targets'] as $id=>$key) {
        am_ck(isset($map['assets'][$key])&&preg_match('/\A(?:model-iphone-[a-z0-9-]+|service-[a-z0-9-]+)\z/D',$id)===1
            &&strlen(AM_PREFIX.$id)<=56&&!isset($seen[$id]),'media_target_invalid');
        $seen[$id]=true; if(str_starts_with($id,'model-'))$models++;else$services++;
    }
    am_ck($models===27&&$services===10&&!isset($map['assets']['general-repair'])&&!isset($map['targets']['menu']),'media_scope_changed');
    return $map;
}
function am_require_decoder(): void {
    foreach(['imagecreatefromstring','imagecreatetruecolor','imagecopyresampled','imagecolorat','imagejpeg','imagewebp','mb_strlen','curl_init'] as $fn)
        am_ck(function_exists($fn),'required_image_decoder_unavailable');
    am_ck((imagetypes()&IMG_JPG)!==0&&(imagetypes()&IMG_PNG)!==0&&(imagetypes()&IMG_WEBP)!==0,'required_image_decoder_unavailable');
}
function am_decode(string $bytes): array {
    am_ck(strlen($bytes)>100&&strlen($bytes)<=5242880,'native_image_size_invalid');
    $size=@getimagesizefromstring($bytes);
    am_ck(is_array($size)&&in_array($size['mime']??null,['image/png','image/jpeg','image/webp'],true)
        &&$size[0]===$size[1]&&$size[0]>=256&&$size[0]<=2048,'native_image_dimensions_invalid');
    $image=@imagecreatefromstring($bytes);am_ck($image instanceof GdImage,'native_image_decode_failed');
    return ['image'=>$image,'width'=>$size[0],'height'=>$size[1],'mime'=>$size['mime']];
}
function am_resize(GdImage $image,int $side): GdImage {
    $out=imagecreatetruecolor($side,$side);am_ck($out instanceof GdImage,'image_allocation_failed');
    $white=imagecolorallocate($out,255,255,255);imagefill($out,0,0,$white);
    am_ck(imagecopyresampled($out,$image,0,0,0,0,$side,$side,imagesx($image),imagesy($image)),'image_resample_failed');
    return $out;
}
function am_samples(string $bytes): array {
    $decoded=am_decode($bytes);$full=am_resize($decoded['image'],256);imagedestroy($decoded['image']);
    return ['rank'=>am_resize($full,64),'full'=>$full,'width'=>$decoded['width'],'height'=>$decoded['height'],'mime'=>$decoded['mime']];
}
function am_difference(GdImage $left,GdImage $right,bool $detail): array {
    $n=imagesx($left);am_ck($n===imagesx($right)&&$n===imagesy($left)&&$n===imagesy($right),'comparison_shape_invalid');
    $sum=0.0;$squares=0.0;$bad=0;$tiles=array_fill(0,256,0.0);$step=intdiv($n,16);
    for($y=0;$y<$n;$y++)for($x=0;$x<$n;$x++) {
        $a=imagecolorat($left,$x,$y);$b=imagecolorat($right,$x,$y);
        $dr=abs((($a>>16)&255)-(($b>>16)&255));$dg=abs((($a>>8)&255)-(($b>>8)&255));$db=abs(($a&255)-($b&255));
        $sum+=$dr+$dg+$db;
        if($detail){$squares+=$dr*$dr+$dg*$dg+$db*$db;$tiles[intdiv($y,$step)*16+intdiv($x,$step)]+=$dr+$dg+$db;if(max($dr,$dg,$db)>32)$bad++;}
    }
    $out=['mae'=>$sum/($n*$n*3)];
    if($detail)$out+=['rmse'=>sqrt($squares/($n*$n*3)),'max_tile_mae'=>max($tiles)/($step*$step*3),'high_difference_fraction'=>$bad/($n*$n)];
    return $out;
}
function am_compare(string $expected,string $nativeBytes,array $sources): array {
    am_ck(isset($sources[$expected])&&count($sources)===19,'comparison_sources_incomplete');
    $native=am_samples($nativeBytes);$scores=[];
    foreach($sources as $key=>$sample)$scores[$key]=am_difference($sample['rank'],$native['rank'],false)['mae'];
    asort($scores,SORT_NUMERIC);$keys=array_keys($scores);$best=$keys[0];$runnerUp=$keys[1];
    $detail=am_difference($sources[$expected]['full'],$native['full'],true);
    imagedestroy($native['rank']);imagedestroy($native['full']);
    $margin=$scores[$runnerUp]-$scores[$best];
    $accepted=$best===$expected&&$margin>=2.0&&$detail['mae']<=4.0&&$detail['rmse']<=10.0
        &&$detail['max_tile_mae']<=12.0&&$detail['high_difference_fraction']<=0.02;
    return ['method'=>'gd_resampled_rgb_unique_match_v1','accepted'=>$accepted,'expected'=>$expected,'best'=>$best,
        'runner_up'=>$runnerUp,'ranking_side'=>64,'detail_side'=>256,'candidate_count'=>19,'margin'=>$margin,
        'ranking_mae'=>$scores,'detail'=>$detail,'native_width'=>$native['width'],'native_height'=>$native['height'],
        'native_mime'=>$native['mime'],'thresholds'=>['min_unique_margin'=>2.0,'max_mae'=>4.0,'max_rmse'=>10.0,
            'max_tile_mae'=>12.0,'max_high_difference_fraction'=>0.02]];
}
function am_url(string $url,string $kind): void {
    $p=parse_url($url);am_ck(is_array($p)&&($p['scheme']??null)==='https'&&!isset($p['port'])&&!isset($p['user'])
        &&!isset($p['pass'])&&!isset($p['fragment'])&&strlen($url)<=8192&&!preg_match('/[\x00-\x20\x7f]/',$url),'image_url_refused');
    if($kind==='source')am_ck(preg_match('~\Ahttps://tour-max\.ru/new/appleservice39/covers/ad-intent-v1/[a-z0-9-]+\.png\z~D',$url)===1,'source_image_url_refused');
    else am_ck(in_array($p['host']??'', ['i.oneme.ru','iu.oneme.ru'],true),'native_image_host_refused');
}
function am_download(string $url,string $kind): string {
    am_url($url,$kind);$raw='';$curl=curl_init($url);am_ck($curl!==false,'image_download_failed');
    $options=MaxTlsConfig::strictCurlOptions()+[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>25,CURLOPT_RETURNTRANSFER=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>['Accept: image/png,image/jpeg','User-Agent: AppleService39-approved-image-restoration/1'],
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>5242880)return 0;$raw.=$chunk;return strlen($chunk);}];
    curl_setopt_array($curl,$options);$ok=curl_exec($curl);$code=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_errno($curl);curl_close($curl);
    am_ck($ok!==false&&$error===0&&$code===200,'image_download_failed');return $raw;
}
function am_html_plan(string $text,array $markup): array {
    am_ck(preg_match('/[\x{10000}-\x{10ffff}]/u',$text)===0,'unsupported_native_character');
    usort($markup,static fn($a,$b)=>$a['from']<=>$b['from']);$html='';$offset=0;$escape=static fn($s)=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    foreach($markup as $span){$from=$span['from']??null;$length=$span['length']??null;
        am_ck(is_int($from)&&is_int($length)&&$from>=$offset&&$length>0&&$from+$length<=mb_strlen($text,'UTF-8'),'native_markup_invalid');
        $html.=$escape(mb_substr($text,$offset,$from-$offset,'UTF-8'));$label=$escape(mb_substr($text,$from,$length,'UTF-8'));
        if(($span['type']??null)==='strong')$html.='<b>'.$label.'</b>';
        elseif(($span['type']??null)==='link'){$url=$span['url']??'';$pair=false;
            if(preg_match('~\Ahttps://max\.ru/id390521129062_bot\?start=(as39_pair_[A-Za-z0-9_-]+)\z~D',$url,$match)===1&&class_exists('AppleService39PilotCatalog')){
                $entry=AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot',$match[1]);$pair=is_array($entry)&&$entry['scope']==='model_service';}
            am_ck($pair||preg_match('~\Ahttps://(?:max\.ru/channel_appleservice39ru/[A-Za-z0-9_-]+|yandex\.ru/maps/org/apple_service39/1731550627/reviews/)\z~D',$url)===1,'native_link_refused');
            $html.='<a href="'.$escape($url).'">'.$label.'</a>';
        }else throw new RuntimeException('unsupported_native_markup');$offset=$from+$length;}
    $html.=$escape(mb_substr($text,$offset,null,'UTF-8'));am_ck(mb_strlen($html,'UTF-8')<=4000,'preserved_caption_too_long');
    return ['body'=>['text'=>$html,'format'=>'html'],'native_text'=>$text,'native_markup'=>$markup];
}
function am_body(array $plan,array $attachments,string $token): array {
    am_ck($token!==''&&count($attachments)===2&&array_is_list($attachments),'image_body_invalid');
    $types=array_column($attachments,'type');$image=array_search('image',$types,true);$keyboard=array_search('inline_keyboard',$types,true);
    am_ck($image!==false&&$keyboard!==false&&$image!==$keyboard,'image_body_invalid');
    $out=$plan['body'];$out['attachments']=$attachments;$out['attachments'][$image]=['type'=>'image','payload'=>['token'=>$token]];$out['notify']=false;
    am_ck(am_canon($out['attachments'][$keyboard])===am_canon($attachments[$keyboard]),'preserved_keyboard_changed');return $out;
}
function am_git(array $args): string {
    $pipes=[];$p=proc_open(['git','-C',AM_ROOT,...$args],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    am_ck(is_resource($p),'checkout_read_failed');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);am_ck(proc_close($p)===0&&is_string($out),'checkout_read_failed');return trim($out);
}
function am_fingerprint(array $p): string { return am_digest([$p['mid'],$p['url'],$p['plan']['native_text'],$p['plan']['native_markup'],$p['photo'],$p['buttons']]); }
function am_original_rows(array $rows): array { return array_values(array_filter($rows,static fn($r)=>!str_starts_with($r['post_key'],AM_PREFIX))); }
function am_writable_state(array $row): bool { return $row['state']==='prepared'&&(int)$row['write_attempts']===0; }
function am_original_verified(array $row): bool {
    return $row['state']==='verified'&&((int)$row['write_attempts']===1
        ||((int)$row['write_attempts']===0&&$row['operation']==='pin'
            &&($row['outcome']['already_present']??null)===true&&($row['outcome']['readback']??null)===true));
}
function am_hold(AppleService39PilotPublicationStore $store,array $row,string $reason,$acknowledged=null): void {
    $current=$store->operation($row['post_key'],$row['operation']);
    am_ck(is_array($current)&&$current['payload_sha256']===$row['payload_sha256'],'media_hold_row_changed');
    if($current['state']==='verified')return;
    am_ck(in_array($current['state'],['write_started','unknown'],true),'media_hold_state_invalid');
    // Retain any actual comparison already saved before a metadata fault.
    $outcome=$current['outcome'];$outcome['reason']=$reason;$outcome['automatic_retry']=false;
    if($acknowledged!==null)$outcome['provider_acknowledged']=$acknowledged;
    $store->finish($current,'unknown',$outcome);
}

function am_selftest(): void {
    $map=am_map();am_require_decoder();$checks=0;$assert=static function(bool $ok)use(&$checks):void{am_ck($ok,'selftest_failed');$checks++;};
    $assert(count($map['targets'])===37&&count($map['assets'])===19);
    foreach($map['targets']as$id=>$key)$assert(isset($map['assets'][$key])&&strlen(AM_PREFIX.$id)<=56);
    $text="Ремонт iPhone\nВсе услуги";$markup=[['type'=>'strong','from'=>0,'length'=>13],['type'=>'link','from'=>14,'length'=>10,'url'=>'https://max.ru/channel_appleservice39ru/test']];
    $p=am_html_plan($text,$markup);$buttons=[[['type'=>'link','text'=>AM_CTA,'url'=>'https://max.ru/id390521129062_bot?start=as39_model-iphone-8']]];
    $attachments=[['type'=>'image','payload'=>['photo_id'=>'10','token'=>'old']],['type'=>'inline_keyboard','payload'=>['buttons'=>$buttons]]];
    $body=am_body($p,$attachments,'new');$assert($body['text']===$p['body']['text']&&$p['native_text']===$text&&$p['native_markup']===$markup);
    $assert($body['attachments'][1]===$attachments[1]&&$body['attachments'][0]['payload']['token']==='new'&&$body['notify']===false);
    foreach(['unknown','write_started','verified','rejected']as$state)$assert(!am_writable_state(['state'=>$state,'write_attempts'=>1]));
    $assert(!am_writable_state(['state'=>'prepared','write_attempts'=>1])&&am_writable_state(['state'=>'prepared','write_attempts'=>0]));
    try{am_html_plan($text,[['type'=>'link','from'=>0,'length'=>13,'url'=>'https://evil.invalid/']]);$assert(false);}catch(RuntimeException $e){$assert($e->getMessage()==='native_link_refused');}
    try{am_url('https://127.0.0.1/a','native');$assert(false);}catch(RuntimeException $e){$assert($e->getMessage()==='native_image_host_refused');}
    $samples=[];$bytes=[];
    for($i=0;$i<19;$i++){$im=imagecreatetruecolor(256,256);$color=imagecolorallocate($im,($i%3)*100,intdiv($i,3)%3*100,intdiv($i,9)*100);imagefill($im,0,0,$color);ob_start();imagepng($im);$bytes['test'.$i]=ob_get_clean();imagedestroy($im);$samples['test'.$i]=am_samples($bytes['test'.$i]);}
    $assert(am_compare('test3',$bytes['test3'],$samples)['accepted']);$assert(!am_compare('test3',$bytes['test4'],$samples)['accepted']);
    $webpInput=am_decode($bytes['test3']);ob_start();$written=imagewebp($webpInput['image'],null,80);$webp=ob_get_clean();imagedestroy($webpInput['image']);
    $assert($written&&is_string($webp)&&(@getimagesizefromstring($webp)['mime']??null)==='image/webp');
    $assert(am_compare('test3',$webp,$samples)['accepted']);$assert(!am_compare('test4',$webp,$samples)['accepted']);
    foreach($samples as$s){imagedestroy($s['rank']);imagedestroy($s['full']);}
    am_emit(['ok'=>true,'state'=>'media_selftest_passed','checks'=>$checks,'posts'=>37,'originals'=>19,'provider_writes'=>0,'database_writes'=>0]);
}
function am_image_selftest(string $directory): void {
    am_require_decoder();$map=am_map();$samples=[];$originals=[];
    foreach($map['assets']as$key=>$asset){$path=rtrim($directory,'/').'/'.$key.'.png';$b=file_get_contents($path);am_ck(is_string($b)&&strlen($b)===$asset['bytes']&&hash('sha256',$b)===$asset['sha256'],'source_original_changed');$originals[$key]=$b;$samples[$key]=am_samples($b);}
    $cases=0;$max=['mae'=>0.0,'rmse'=>0.0,'max_tile_mae'=>0.0,'high_difference_fraction'=>0.0];$minMargin=PHP_FLOAT_MAX;
    foreach($originals as$key=>$bytes){$own=am_compare($key,$bytes,$samples);am_ck($own['accepted'],'original_image_not_separable');$cases++;
        foreach([512,768,1024]as$side)foreach([55,70,85]as$quality){$decoded=am_decode($bytes);$image=am_resize($decoded['image'],$side);imagedestroy($decoded['image']);ob_start();imagejpeg($image,null,$quality);$jpeg=ob_get_clean();imagedestroy($image);
            $r=am_compare($key,$jpeg,$samples);am_ck($r['accepted'],'jpeg_resize_not_accepted');$cases++;foreach($max as$metric=>$v)$max[$metric]=max($v,$r['detail'][$metric]);$minMargin=min($minMargin,$r['margin']);}
        $decoded=am_decode($bytes);ob_start();$written=imagewebp($decoded['image'],null,80);$webp=ob_get_clean();imagedestroy($decoded['image']);
        am_ck($written&&is_string($webp)&&(@getimagesizefromstring($webp)['mime']??null)==='image/webp','webp_fixture_failed');
        $r=am_compare($key,$webp,$samples);am_ck($r['accepted'],'webp_original_not_accepted');$cases++;foreach($max as$metric=>$v)$max[$metric]=max($v,$r['detail'][$metric]);$minMargin=min($minMargin,$r['margin']);
        $wrong=array_values(array_filter(array_keys($originals),static fn($other)=>$other!==$key))[0];am_ck(!am_compare($wrong,$webp,$samples)['accepted'],'wrong_webp_original_accepted');$cases++;
        foreach($originals as$other=>$otherBytes)if($other!==$key){$r=am_compare($key,$otherBytes,$samples);am_ck(!$r['accepted'],'wrong_original_accepted');$cases++;}}
    foreach($samples as$s){imagedestroy($s['rank']);imagedestroy($s['full']);}
    am_emit(['ok'=>true,'state'=>'actual_originals_jpeg_and_wrong_image_checks_passed','cases'=>$cases,'originals'=>19,'positive_variants_per_original'=>11,'wrong_image_pairs'=>342,'webp_positive_cases'=>19,'wrong_webp_pairs'=>19,'max_detail'=>$max,'min_positive_margin'=>$minMargin,'provider_writes'=>0,'database_writes'=>0]);
}

// Entry modes are fixed. Self-tests never load runtime credentials or a DB.
$mode=$argv[1]??'--help';
try {
    if($mode==='--capabilities'){am_emit(['php'=>PHP_VERSION,'gd'=>extension_loaded('gd'),'curl'=>extension_loaded('curl'),'mbstring'=>extension_loaded('mbstring'),'pdo_mysql'=>extension_loaded('pdo_mysql')]);exit;}
    if($mode==='--self-test'){am_selftest();exit;}
    if(str_starts_with($mode,'--image-self-test=')){am_image_selftest(substr($mode,18));exit;}
    if($mode==='--help'){am_emit(['modes'=>['--capabilities','--self-test','--image-self-test=<original-directory>','--apply','--reconcile'],'scope'=>'37_existing_posts_19_originals_no_create_no_replay']);exit;}
    am_ck(in_array($mode,['--apply','--reconcile'],true)&&$argc===2,'fixed_operation_required');
    am_require_decoder();$map=am_map();$private='/var/www/anytoour/data/config/max-search.php';
    am_ck(PHP_SAPI==='cli'&&realpath(AM_ROOT)===AM_ROOT&&is_file($private)&&!is_link($private),'fixed_runtime_required');
    $privateHash=hash_file('sha256',$private);$safe=static fn():bool=>am_git(['rev-parse','HEAD'])===AM_APP&&am_git(['status','--porcelain','--untracked-files=no'])===''&&is_file($private)&&!is_link($private)&&hash_file('sha256',$private)===$privateHash;
    am_ck($safe(),'checkout_changed');require_once AM_ROOT.'/config.php';require_once AM_ROOT.'/services/ConversationDb.php';require_once AM_ROOT.'/services/AppleService39PilotPublisher.php';
    $pdo=ConversationDb::connection();am_ck(!$pdo->inTransaction(),'autocommit_required');$store=new AppleService39PilotPublicationStore($pdo);$allRows=$store->rows();$original=am_original_rows($allRows);
    am_ck(count($original)===301&&count(array_filter($original,static fn($r)=>!am_original_verified($r)))===0,'original_operations_changed');
    if($mode==='--apply')am_ck(count($allRows)===301,'media_revision_already_started');
    $parentDigest=am_digest($original);$manifest=AppleService39PilotManifest::installed();
    $pub=new AppleService39PilotPublisher($pdo,$manifest,AM_CHANNEL,$safe,null,null,new AppleService39CatalogueRevision($manifest),new AppleService39SearchIntentRevision($manifest));
    $store->assertBinding(am_invoke($pub,'preflight'));am_ck(am_invoke($pub,'pinnedMid')===AM_PIN,'pin_changed');
    $connection=ProjectMaxConnection::resolve('appleservice39');am_ck($connection!==null,'connection_unavailable');$transport=new AppleService39ChannelTransport($connection,AM_CHANNEL);
    $guard=static function()use($safe,$store,$original,$pub):void{am_ck($safe(),'checkout_changed');am_ck(am_original_rows($store->rows())===$original,'original_operations_changed');am_ck(am_invoke($pub,'pinnedMid')===AM_PIN,'pin_changed');};
    $get=static function(string $mid)use($transport):array{$r=$transport->message($mid);am_ck(AppleService39ChannelTransport::accepted($r),'native_read_failed');$m=$r['data']['message']??$r['data'];am_ck(is_array($m),'native_read_failed');return $m;};
    $creates=[];foreach($original as$row)if($row['operation']==='create')$creates[$row['post_key']]=$row;am_ck(count($creates)===69,'native_inventory_changed');
    foreach($map['targets']as$id=>$key)am_ck(isset($creates[$id]),'target_publication_missing');
    $observe=static function(string $id,bool $originalImage=true)use($get,$creates,$original,$pub):array{
        $create=$creates[$id];$mid=$create['external_message_id'];$message=$get($mid);$sourceAttachments=null;$photoReceipt=null;
        foreach($original as$row)if($row['post_key']===$id||($row['request']['target_post']??null)===$id){if(isset($row['request']['body']['attachments']))$sourceAttachments=$row['request']['body']['attachments'];if(isset($row['outcome']['image_receipt']['photo_id']))$photoReceipt=$row['outcome']['image_receipt']['photo_id'];}
        am_ck(is_array($sourceAttachments)&&is_string($photoReceipt),'original_attachment_binding_missing');$plan=am_html_plan($message['body']['text'],$message['body']['markup']??[]);$attachments=$message['body']['attachments']??null;
        am_ck(is_array($attachments)&&array_is_list($attachments)&&count($attachments)===2,'native_attachment_shape_changed');$types=array_column($attachments,'type');$keyAt=array_search('inline_keyboard',$types,true);$imageAt=array_search('image',$types,true);
        am_ck($keyAt!==false&&$imageAt!==false&&$keyAt!==$imageAt,'native_attachment_shape_changed');$sourceKey=array_search('inline_keyboard',array_column($sourceAttachments,'type'),true);am_ck($sourceKey!==false,'original_keyboard_missing');
        $buttons=$sourceAttachments[$sourceKey]['payload']['buttons']??null;am_ck(is_array($buttons)&&count($buttons)===1&&count($buttons[0])===1&&$buttons[0][0]['type']==='link','original_single_cta_missing');$buttons[0][0]['text']=AM_CTA;
        am_ck(am_invoke($pub,'sameButtons',$attachments[$keyAt]['payload']['buttons']??null,$buttons),'native_cta_changed');$payload=am_invoke($pub,'imagePayload',$message);$photo=(string)($payload['photo_id']??'');
        am_ck(preg_match('/\A-?[0-9]{1,20}\z/D',$photo)===1&&(!$originalImage||$photo===$photoReceipt),'original_photo_changed');
        $body=$plan['body'];$body['attachments']=$sourceAttachments;$body['attachments'][$sourceKey]['payload']['buttons']=$buttons;
        am_ck(am_invoke($pub,'verifyMessageContent',$message,$mid,$body,$plan)===$create['public_url'],'native_identity_changed');
        return ['post'=>$id,'mid'=>$mid,'url'=>$create['public_url'],'plan'=>$plan,'attachments'=>$body['attachments'],'photo'=>$photo,'buttons'=>$buttons,'payload'=>$payload];
    };
    $before=[];if($mode==='--apply')foreach(array_keys($creates)as$id)$before[$id]=$observe($id,true);
    // Fail before any upload/edit if the actual native image path or decoder is unavailable.
    if($mode==='--apply'){
        $old=AM_ROOT.'/resources/appleservice39/search-intent-v1/covers/menu.png';am_ck(is_file($old)&&!is_link($old)&&hash_file('sha256',$old)===AM_OLD_IMAGE_SHA256,'shared_cover_source_changed');
        $oldSamples=am_samples((string)file_get_contents($old));$native=am_samples(am_download((string)($before['model-iphone-8']['payload']['url']??''),'native'));
        $difference=am_difference($oldSamples['full'],$native['full'],true);am_ck($difference['mae']<=4.0&&$difference['rmse']<=10.0&&$difference['max_tile_mae']<=12.0,'native_image_preflight_failed');
        foreach([$oldSamples,$native]as$sample){imagedestroy($sample['rank']);imagedestroy($sample['full']);}
        am_emit(['stage'=>'native_image_decoder_preflight','ok'=>true,'old_cover_matches'=>true,'method'=>'gd_resampled_rgb','detail'=>$difference]);
    }
    $tmp=sys_get_temp_dir().'/as39-approved-images-'.bin2hex(random_bytes(8));am_ck(mkdir($tmp,0700),'temporary_directory_failed');$sources=[];$assetFiles=[];
    foreach($map['assets']as$key=>$asset){$bytes=am_download($asset['url'],'source');am_ck(strlen($bytes)===$asset['bytes']&&hash('sha256',$bytes)===$asset['sha256'],'approved_original_changed');$sample=am_samples($bytes);am_ck($sample['width']===1254&&$sample['height']===1254&&$sample['mime']==='image/png','approved_original_dimensions_changed');$sources[$key]=$sample;$assetFiles[$key]=$tmp.'/'.$key.'.png';am_ck(file_put_contents($assetFiles[$key],$bytes,LOCK_EX)===strlen($bytes),'source_temporary_write_failed');chmod($assetFiles[$key],0600);}
    am_emit(['stage'=>'approved_originals_checked','assets'=>19,'targets'=>37,'source_sha256'=>AM_MAP_SHA256]);
    $uploads=[];$failed=[];$verified=[];$active=null;
    foreach($map['assets']as$key=>$asset){
        $postKey=AM_PREFIX.'upload-'.$key;$request=['revision'=>AM_REVISION,'revision_sha256'=>AM_MAP_SHA256,'cover_key'=>$key,'source_sha256'=>$asset['sha256'],'source_bytes'=>$asset['bytes'],'original_operations_sha256'=>$parentDigest];
        if($mode==='--apply'){$guard();$row=$store->prepare($postKey,'landing_upload',$request);am_ck(am_writable_state($row),'media_upload_already_attempted');am_invoke($pub,'beforeWrite',$row,$guard);$active=$row;
            try{$response=$transport->upload($assetFiles[$key]);$receipt=AppleService39ChannelTransport::uploadReceipt($response);am_ck($receipt!==null,'media_upload_unknown');foreach($uploads as$prior)am_ck($prior['outcome']['token']!==$receipt['token'],'media_upload_token_collision');$store->finish($row,'verified',$receipt);$active=null;}
            catch(Throwable $error){$store->finish($row,'unknown',['reason'=>am_reason($error)]);$active=null;$failed['asset:'.$key]=am_reason($error);am_emit(['stage'=>'media_upload_held','cover_key'=>$key,'reason'=>am_reason($error),'automatic_retry'=>false]);}
        }
        $row=$store->operation($postKey,'landing_upload');if($row===null)continue;am_ck($row['request']===$request,'media_upload_request_changed');
        if($row['state']==='verified'){am_ck((int)$row['write_attempts']===1&&is_string($row['outcome']['token']??null)&&$row['outcome']['token']!=='','media_upload_receipt_invalid');$uploads[$key]=$row;}
    }
    $verifyEdit=static function(array $row,string $id,string $key)use($observe,$sources,$map,$uploads,$store,$pub,$parentDigest):array{
        $r=$row['request'];$upload=$uploads[$key];am_ck($r['revision']===AM_REVISION&&$r['revision_sha256']===AM_MAP_SHA256&&$r['target_post']===$id&&$r['source_sha256']===$map['assets'][$key]['sha256']&&$r['upload_operation_id']===(int)$upload['id']&&$r['upload_request_sha256']===$upload['payload_sha256']&&$r['upload_token_sha256']===hash('sha256',$upload['outcome']['token'])&&$r['original_operations_sha256']===$parentDigest,'media_edit_request_changed');
        $after=$observe($id,false);am_ck($after['mid']===$r['mid']&&$after['url']===$r['url']&&$after['plan']['native_text']===$r['native_text']&&AppleService39LandingRevision::sameMarkup($after['plan']['native_markup'],$r['native_markup'])&&am_canon($after['buttons'])===am_canon($r['preserved_buttons']),'media_content_readback_failed');
        am_ck($after['photo']!==$r['previous_photo_id'],'media_image_not_replaced');$nativeUrl=(string)($after['payload']['url']??'');$nativeBytes=am_download($nativeUrl,'native');$comparison=am_compare($key,$nativeBytes,$sources);am_ck($comparison['accepted'],'native_original_comparison_failed');
        $proof=['schema_version'=>1,'kind'=>'automatic_native_pixel_comparison','method'=>$comparison['method'],'project_key'=>'appleservice39','channel_id'=>AM_CHANNEL,'target_post'=>$id,'message_id'=>$after['mid'],'public_url'=>$after['url'],'native_photo_id'=>$after['photo'],'source_sha256'=>$r['source_sha256'],'native_preview_sha256'=>hash('sha256',$nativeBytes),'native_payload_url_sha256'=>hash('sha256',$nativeUrl),'native_preview_bytes'=>strlen($nativeBytes),'request_sha256'=>$row['payload_sha256'],'upload_operation_id'=>(int)$upload['id'],'upload_request_sha256'=>$upload['payload_sha256'],'upload_token_sha256'=>$r['upload_token_sha256'],'edit_operation_id'=>(int)$row['id'],'edit_started_ms'=>$row['started_ms'],'application_sha'=>AM_APP,'comparison'=>$comparison];
        $proofHash=am_digest($proof);$receipt=['kind'=>'reviewed_native_replacement','message_id'=>$after['mid'],'photo_id'=>$after['photo'],'source_sha256'=>$r['source_sha256'],'upload_token_sha256'=>$r['upload_token_sha256'],'request_sha256'=>$row['payload_sha256'],'revision_sha256'=>AM_MAP_SHA256,'evidence_sha256'=>$proofHash,'native_preview_sha256'=>hash('sha256',$nativeBytes)];
        $priorReceipt=$row['outcome']['image_receipt']??null;$priorProof=$row['outcome']['image_comparison']??null;
        if($row['state']==='verified')am_ck(is_array($priorReceipt),'verified_image_receipt_missing');
        if($priorProof!==null||$priorReceipt!==null||$row['state']==='verified'){
            am_ck(is_array($priorProof)&&($row['outcome']['readback']??null)===true
                &&($priorProof['kind']??null)==='automatic_native_pixel_comparison'
                &&($priorProof['comparison']['accepted']??null)===true
                &&($priorProof['comparison']['method']??null)==='gd_resampled_rgb_unique_match_v1'
                &&($priorProof['comparison']['expected']??null)===$key
                &&($priorProof['comparison']['best']??null)===$key
                &&am_digest($priorProof)===($row['outcome']['image_comparison_sha256']??null),'saved_image_evidence_changed');
            foreach(['project_key','channel_id','target_post','message_id','public_url','native_photo_id','source_sha256','request_sha256','upload_operation_id','upload_request_sha256','upload_token_sha256','edit_operation_id','edit_started_ms','application_sha']as$field)
                am_ck(($priorProof[$field]??null)===$proof[$field],'saved_image_evidence_changed');
            am_ck(preg_match('/\A[a-f0-9]{64}\z/D',(string)($priorProof['native_preview_sha256']??''))===1,'saved_image_evidence_changed');
            $receipt['evidence_sha256']=am_digest($priorProof);$receipt['native_preview_sha256']=$priorProof['native_preview_sha256'];
            if($priorReceipt!==null)am_ck($priorReceipt===$receipt,'saved_image_receipt_changed');
            // A signed URL may rotate. Retain the immutable original proof and
            // save this fresh observation only in the run artifact, never rewrite it.
        }
        // Public native image bytes, no provider token or signed locator. Capture
        // actual bytes before receipt/finalization; a metadata fault must not
        // discard the independently observed comparison or cause another PUT.
        am_emit(['record'=>'native_evidence','post_id'=>$id,'evidence_sha256'=>$proofHash,'original_evidence_sha256'=>$receipt['evidence_sha256'],'evidence'=>$proof,'native_image_base64'=>base64_encode($nativeBytes)]);
        if(defined('STDOUT'))fflush(STDOUT);
        if($row['state']!=='verified'){
            $retainedProof=is_array($priorProof)?$priorProof:$proof;$retainedHash=am_digest($retainedProof);
            $outcome=['readback'=>true,'image_comparison'=>$retainedProof,'image_comparison_sha256'=>$retainedHash,
                'text_markup_keyboard_mid_url_pin_preserved'=>true,'reason'=>'verified_image_metadata_finalization_pending'];
            if($priorReceipt!==null)$outcome['image_receipt']=$priorReceipt;
            // This UNKNOWN describes pending metadata completion after a proven
            // image readback. It does not claim the provider write is unobserved.
            $store->finish($row,'unknown',$outcome);
            $store->rememberLandingImageReceipt($row,$receipt);
            unset($outcome['reason']);if(isset($outcome['image_receipt']))unset($outcome['image_receipt']);
            $store->finish($row,'verified',$outcome);
        }
        return $after;
    };
    foreach($map['targets']as$id=>$key){
        if(!isset($uploads[$key])){$failed[$id]='approved_upload_unverified';continue;}
        $row=$store->operation(AM_PREFIX.$id,'landing_edit');
        if($mode==='--apply'){
            $guard();$p=$before[$id];$u=$uploads[$key];$body=am_body($p['plan'],$p['attachments'],$u['outcome']['token']);
            $request=['revision'=>AM_REVISION,'revision_sha256'=>AM_MAP_SHA256,'target_post'=>$id,'cover_key'=>$key,'source_sha256'=>$map['assets'][$key]['sha256'],'mid'=>$p['mid'],'url'=>$p['url'],'upload_operation_id'=>(int)$u['id'],'upload_request_sha256'=>$u['payload_sha256'],'upload_token_sha256'=>hash('sha256',$u['outcome']['token']),'original_operations_sha256'=>$parentDigest,'previous_photo_id'=>$p['photo'],'preserved_buttons'=>$p['buttons'],'body'=>$body,'native_text'=>$p['plan']['native_text'],'native_markup'=>$p['plan']['native_markup']];
            $row=$store->prepare(AM_PREFIX.$id,'landing_edit',$request);am_ck(am_writable_state($row),'media_edit_already_attempted');
            am_invoke($pub,'beforeWrite',$row,static function()use($guard,$observe,$id,$p):void{$guard();am_ck(am_fingerprint($observe($id,true))===am_fingerprint($p),'native_preimage_changed');});
            $store->rememberMessage($row,$p['mid'],$p['url']);$active=$row;
            // Never call edit again for this row, including a lost response.
            $ack=$transport->edit($p['mid'],$body);$acknowledged=AppleService39ChannelTransport::accepted($ack)&&($ack['data']['success']??null)===true;
            $row=$store->operation(AM_PREFIX.$id,'landing_edit');
        }else{$acknowledged=null;if($row===null||$row['state']==='prepared'){$failed[$id]='media_edit_unattempted';continue;}}
        am_ck(is_array($row)&&in_array($row['state'],['write_started','unknown','verified'],true)&&(int)$row['write_attempts']===1,'media_edit_state_invalid');
        try{$after=$verifyEdit($row,$id,$key);$verified[$id]=$after;$active=null;am_emit(['stage'=>'approved_image_verified','post_id'=>$id,'url'=>$after['url'],'cover_key'=>$key,'acknowledged'=>$acknowledged,'method'=>'gd_resampled_rgb_unique_match_v1']);}
        catch(Throwable $error){if($row['state']!=='verified')am_hold($store,$row,am_reason($error),$acknowledged);$active=null;$failed[$id]=am_reason($error);am_emit(['stage'=>'approved_image_held','post_id'=>$id,'reason'=>am_reason($error),'automatic_retry'=>false]);}
        if($mode==='--apply')usleep(650000);
    }
    $guard();$untouched=0;
    if($mode==='--apply')foreach($before as$id=>$p){$after=$observe($id,!isset($map['targets'][$id]));if(!isset($map['targets'][$id])){am_ck(am_fingerprint($after)===am_fingerprint($p),'unrelated_post_changed');$untouched++;}else am_ck($after['plan']['native_text']===$p['plan']['native_text']&&AppleService39LandingRevision::sameMarkup($after['plan']['native_markup'],$p['plan']['native_markup'])&&am_canon($after['buttons'])===am_canon($p['buttons'])&&$after['mid']===$p['mid']&&$after['url']===$p['url'],'final_preserved_content_changed');}
    $rows=$store->rows();$own=array_values(array_filter($rows,static fn($r)=>str_starts_with($r['post_key'],AM_PREFIX)));$held=array_values(array_map(static fn($r)=>['post_key'=>$r['post_key'],'operation'=>$r['operation'],'state'=>$r['state']],array_filter($own,static fn($r)=>$r['state']!=='verified')));
    am_ck(am_original_rows($rows)===$original,'original_receipts_changed');$store->assertBinding(am_invoke($pub,'preflight'));$complete=count($verified)===37&&count($own)===56&&$held===[];
    am_emit(['ok'=>$complete,'state'=>$complete?'approved_images_restored':'approved_images_partial','mode'=>$mode,'posts_verified'=>count($verified),'originals_verified'=>count($uploads),'expected_posts'=>37,'original_operations_preserved'=>301,'original_posts'=>69,'untouched_posts_verified'=>$untouched,'own_operations'=>count($own),'held'=>$held,'failures'=>$failed,'provider_creates'=>0,'automatic_retry'=>false]);
    if(isset($sources))foreach($sources as$s){imagedestroy($s['rank']);imagedestroy($s['full']);}foreach($assetFiles as$f)unlink($f);rmdir($tmp);exit($complete?0:1);
} catch(Throwable $error) {
    if(isset($store,$active)&&$store instanceof AppleService39PilotPublicationStore&&is_array($active))try{am_hold($store,$active,am_reason($error));}catch(Throwable $ignored){}
    if(isset($assetFiles))foreach($assetFiles as$f)if(is_file($f))@unlink($f);if(isset($tmp)&&is_dir($tmp))@rmdir($tmp);
    am_emit(['ok'=>false,'state'=>'media_operation_stopped','reason'=>am_reason($error),'error_class'=>get_class($error),'line'=>$error->getLine(),'automatic_retry'=>false]);exit(1);
}
