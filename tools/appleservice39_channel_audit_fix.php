<?php
declare(strict_types=1);
ini_set('display_errors','0');
// Finite correction of four observed existing posts. Same Store and transport,
// no create, no pin change, no replay and no production source/configuration edit.
const AM_ROOT='/var/www/anytoour/data/www/app.anytoour.ru';
const AM_APP='cb7ec5806ed920813c79400cbebe78d65777090b';
const AM_CHANNEL='-80917954947727';
const AM_PIN='mid.ffffb667d20a157101a1bd6d233963da';
const AM_REVISION='channel-audit-polish-v1';
const AM_PREFIX='qa1-';
const AM_CTA='Узнать стоимость ремонта';
const AQ_PRICE_URL='https://appleservice39.ru/remont-iphone/zamena-batarei-ayfon-iphone-v-kaliningrade/';
const AQ_PACKAGE_SHA256='40951416bffd31fc933265e62e3eb66146bd460db3ddfe15a883a89ca121e60d';
const AQ_PACKAGE_BASE64='eyJyZXZpc2lvbiI6ImNoYW5uZWwtYXVkaXQtcG9saXNoLXYxIiwiYXNzZXRzIjp7ImdlbmVyYWwtcmVwYWlyIjp7ImtleSI6ImdlbmVyYWwtcmVwYWlyIiwiYnl0ZXMiOjE2MTU5NjAsInNoYTI1NiI6IjE3ZTdmYWM5YTU3YTk0M2JkZDQ3Njk2ZjJlYmFjMzQxYTgyOTAwZDgwZWYxMDQzNjRjMTQxZDY5MTFjYzQ3ODYiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL2dlbmVyYWwtcmVwYWlyLnBuZyJ9LCJpcGhvbmUtOC1mYW1pbHkiOnsia2V5IjoiaXBob25lLTgtZmFtaWx5IiwiYnl0ZXMiOjE1NzMzNTIsInNoYTI1NiI6IjQzOGRmMTJmMmMyOGE0OGVhMDU5MjNlMjg5NjZkZmQ0Y2MwODUxZDc2NTNiOTg4MzY0NTBjMzNiNTBhMTJkYWUiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL2lwaG9uZS04LWZhbWlseS5wbmcifSwiaXBob25lLXgtZmFtaWx5Ijp7ImtleSI6ImlwaG9uZS14LWZhbWlseSIsImJ5dGVzIjoxNzQ5MDcxLCJzaGEyNTYiOiJkMzAzNDQ2MzYzOWU5YWU3ZTJkYmRlYWFiNjNmNzMzZjAzN2MyM2Q0MDhmY2IyNWI5ODE1OTAwYWY1N2QyMWRmIiwid2lkdGgiOjEyNTQsImhlaWdodCI6MTI1NCwidXJsIjoiaHR0cHM6Ly90b3VyLW1heC5ydS9uZXcvYXBwbGVzZXJ2aWNlMzkvY292ZXJzL2FkLWludGVudC12MS9pcGhvbmUteC1mYW1pbHkucG5nIn0sImlwaG9uZS0xMS1mYW1pbHkiOnsia2V5IjoiaXBob25lLTExLWZhbWlseSIsImJ5dGVzIjoxNTQ3ODk5LCJzaGEyNTYiOiIxNGIyZmU5Nzk2YTVjMDJlOWE4YzE1N2U3MWM2ODMwMmJmMWU3MDkyYjgyNTEwMDhkNzg1ZmViZWZlNWQ4NTViIiwid2lkdGgiOjEyNTQsImhlaWdodCI6MTI1NCwidXJsIjoiaHR0cHM6Ly90b3VyLW1heC5ydS9uZXcvYXBwbGVzZXJ2aWNlMzkvY292ZXJzL2FkLWludGVudC12MS9pcGhvbmUtMTEtZmFtaWx5LnBuZyJ9LCJpcGhvbmUtMTItZmFtaWx5Ijp7ImtleSI6ImlwaG9uZS0xMi1mYW1pbHkiLCJieXRlcyI6MTY0MjAwMCwic2hhMjU2IjoiYTlkMzQxNjVmMGE3YzExMDgzNzdiMGM0MmM0NzcxYjQyNWFhN2NiNGNhYTEzMzBjMDhhOTA2MDVjZTFiNWNkYyIsIndpZHRoIjoxMjU0LCJoZWlnaHQiOjEyNTQsInVybCI6Imh0dHBzOi8vdG91ci1tYXgucnUvbmV3L2FwcGxlc2VydmljZTM5L2NvdmVycy9hZC1pbnRlbnQtdjEvaXBob25lLTEyLWZhbWlseS5wbmcifSwiaXBob25lLTEzLWZhbWlseSI6eyJrZXkiOiJpcGhvbmUtMTMtZmFtaWx5IiwiYnl0ZXMiOjE1OTA0NjEsInNoYTI1NiI6ImJkMzBmOTJkMWQxMDU5NTY1ZDQzZWQ1YjQ2YjA0OGIxNTMzYWQzMWY1MjMxNjEyNzI4MDQzZjVkODhkNDc4NDgiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL2lwaG9uZS0xMy1mYW1pbHkucG5nIn0sImlwaG9uZS0xNC1mYW1pbHkiOnsia2V5IjoiaXBob25lLTE0LWZhbWlseSIsImJ5dGVzIjoxNjE3OTMxLCJzaGEyNTYiOiI1NWNmN2U5YTJmYjY0MGMzYTY4Y2MxNzIwZDhhOTU1YzNiYTcxODEyNmUwN2EyMjM4NGQyNWE4MDU3NmQ1ZTY0Iiwid2lkdGgiOjEyNTQsImhlaWdodCI6MTI1NCwidXJsIjoiaHR0cHM6Ly90b3VyLW1heC5ydS9uZXcvYXBwbGVzZXJ2aWNlMzkvY292ZXJzL2FkLWludGVudC12MS9pcGhvbmUtMTQtZmFtaWx5LnBuZyJ9LCJpcGhvbmUtMTYtZmFtaWx5Ijp7ImtleSI6ImlwaG9uZS0xNi1mYW1pbHkiLCJieXRlcyI6MTYyNTgwMSwic2hhMjU2IjoiYWNmNDY0MzY1YjY0MTdiYTdiZTAwNjExOTJiOTY2ODEyOGVhMDFmYjE4YTZmNjZmZDliMjY5ZTY5ZTZiNTBhMCIsIndpZHRoIjoxMjU0LCJoZWlnaHQiOjEyNTQsInVybCI6Imh0dHBzOi8vdG91ci1tYXgucnUvbmV3L2FwcGxlc2VydmljZTM5L2NvdmVycy9hZC1pbnRlbnQtdjEvaXBob25lLTE2LWZhbWlseS5wbmcifSwiaXBob25lLTE3LXByby1mYW1pbHkiOnsia2V5IjoiaXBob25lLTE3LXByby1mYW1pbHkiLCJieXRlcyI6MTYwNjc4MSwic2hhMjU2IjoiODE2MWNhY2FjYWQzYzcwYmU1NmZhM2IxOTY5ZWZlZjUwNWNkYjUwZWI1NWZhMTkxMmNiMGQ4NzkwZTAxZTc5ZCIsIndpZHRoIjoxMjU0LCJoZWlnaHQiOjEyNTQsInVybCI6Imh0dHBzOi8vdG91ci1tYXgucnUvbmV3L2FwcGxlc2VydmljZTM5L2NvdmVycy9hZC1pbnRlbnQtdjEvaXBob25lLTE3LXByby1mYW1pbHkucG5nIn0sImlwaG9uZS1haXIiOnsia2V5IjoiaXBob25lLWFpciIsImJ5dGVzIjoxNTg3Mzg2LCJzaGEyNTYiOiIxNmRlZjc0YjQ0NDY2ZDJlNGIzNmNjNzM3YzgxOGE1OTg3Y2EzYjQ2M2YzZjBmZWFmYjVmZTUyYWE1MzY2NjIzIiwid2lkdGgiOjEyNTQsImhlaWdodCI6MTI1NCwidXJsIjoiaHR0cHM6Ly90b3VyLW1heC5ydS9uZXcvYXBwbGVzZXJ2aWNlMzkvY292ZXJzL2FkLWludGVudC12MS9pcGhvbmUtYWlyLnBuZyJ9LCJjaGFyZ2luZy1wb3J0Ijp7ImtleSI6ImNoYXJnaW5nLXBvcnQiLCJieXRlcyI6MTc2NTAwMCwic2hhMjU2IjoiZDMyOWUxNTMzN2MyN2I5ZTg0MmZhYTE1NDdmMWIzYTU5YjhhY2Q1ZGI4Y2MyY2E1YzE4ZTIzZWMyNDhlODdiNSIsIndpZHRoIjoxMjU0LCJoZWlnaHQiOjEyNTQsInVybCI6Imh0dHBzOi8vdG91ci1tYXgucnUvbmV3L2FwcGxlc2VydmljZTM5L2NvdmVycy9hZC1pbnRlbnQtdjEvY2hhcmdpbmctcG9ydC5wbmcifSwibWljcm9waG9uZSI6eyJrZXkiOiJtaWNyb3Bob25lIiwiYnl0ZXMiOjE4MDI0MTcsInNoYTI1NiI6ImU5ZTllZjUxODAxMTcxYzE3ZTZjNTA2ODcwNGJjZGU2NmY2YjhhYzE2ZjVkZmViOTgzMzkxNjdhMjg0OTE0NWUiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL21pY3JvcGhvbmUucG5nIn0sImxvdWRzcGVha2VyIjp7ImtleSI6ImxvdWRzcGVha2VyIiwiYnl0ZXMiOjE2NTE4MTMsInNoYTI1NiI6Ijk3NjMxNGU2NzFkODYyZmZkN2Q3NGRlZDgwNGI2ZTJhN2Y5OTk1NDIwMmI1ZTYwZjVjYTMxYTQxNjk0YzI3M2EiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL2xvdWRzcGVha2VyLnBuZyJ9LCJmcm9udC1jYW1lcmEiOnsia2V5IjoiZnJvbnQtY2FtZXJhIiwiYnl0ZXMiOjE2MTE2MDEsInNoYTI1NiI6IjAzNTkyOWU3NWY3NjBhMzJiOGQ1MjY3MjA3MzliZDE0MDgwYmI3OTUzNzg3YWQyMWYzNjliYjhjYjEwNjhjNWUiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL2Zyb250LWNhbWVyYS5wbmcifSwiaG9tZS1idXR0b24iOnsia2V5IjoiaG9tZS1idXR0b24iLCJieXRlcyI6MTU5NjcyNiwic2hhMjU2IjoiYTYwYjU5MzBiZmRhNThiMzBlMjdmMGM5YzQ3ZDllNWMzMmE0NDk1MWUwMTk0OTkzNDRiZWQ0YWIyY2VhZDNjNCIsIndpZHRoIjoxMjU0LCJoZWlnaHQiOjEyNTQsInVybCI6Imh0dHBzOi8vdG91ci1tYXgucnUvbmV3L2FwcGxlc2VydmljZTM5L2NvdmVycy9hZC1pbnRlbnQtdjEvaG9tZS1idXR0b24ucG5nIn0sInBvd2VyLWJ1dHRvbiI6eyJrZXkiOiJwb3dlci1idXR0b24iLCJieXRlcyI6MTY1MjQxMSwic2hhMjU2IjoiMTgxNDM3MGE1YzUwODRjNWRmOGFjMmRjMWIyNzc1OTAzOTM5MDRjOThiZWU2Zjk2OTUwMTU5MmEwZGZjYTA4NyIsIndpZHRoIjoxMjU0LCJoZWlnaHQiOjEyNTQsInVybCI6Imh0dHBzOi8vdG91ci1tYXgucnUvbmV3L2FwcGxlc2VydmljZTM5L2NvdmVycy9hZC1pbnRlbnQtdjEvcG93ZXItYnV0dG9uLnBuZyJ9LCJhbnRlbm5hIjp7ImtleSI6ImFudGVubmEiLCJieXRlcyI6MTU3MTI0Miwic2hhMjU2IjoiMDQ1NGM3Y2Y2ODQ3ZWM1ODQ5MTMwYThhYzYzMzk3ODkxYTMwODgzNWY1YmFkODdiMDI1MWE5OTdlZTIxMzdiMCIsIndpZHRoIjoxMjU0LCJoZWlnaHQiOjEyNTQsInVybCI6Imh0dHBzOi8vdG91ci1tYXgucnUvbmV3L2FwcGxlc2VydmljZTM5L2NvdmVycy9hZC1pbnRlbnQtdjEvYW50ZW5uYS5wbmcifSwic29mdHdhcmUiOnsia2V5Ijoic29mdHdhcmUiLCJieXRlcyI6MTU3NjI4MCwic2hhMjU2IjoiZTYxNzM4NmI4YTUxMWIzZmM4MGY2Y2FkYTcwYjYyMmFlMGYyMzVmZGRhMjJjMTE2NTcwMDhmODZlZTI2OWM1ZSIsIndpZHRoIjoxMjU0LCJoZWlnaHQiOjEyNTQsInVybCI6Imh0dHBzOi8vdG91ci1tYXgucnUvbmV3L2FwcGxlc2VydmljZTM5L2NvdmVycy9hZC1pbnRlbnQtdjEvc29mdHdhcmUucG5nIn0sImRpYWdub3N0aWNzIjp7ImtleSI6ImRpYWdub3N0aWNzIiwiYnl0ZXMiOjE3MDA5OTUsInNoYTI1NiI6IjhlMDFjNWM2ZmE5YjBiY2FmODQyZDhlMmMzODU2YjI2MmVhZTcwMWY4Y2JlY2FmZWIxZTlkZWM5ZTNjMWYyYmUiLCJ3aWR0aCI6MTI1NCwiaGVpZ2h0IjoxMjU0LCJ1cmwiOiJodHRwczovL3RvdXItbWF4LnJ1L25ldy9hcHBsZXNlcnZpY2UzOS9jb3ZlcnMvYWQtaW50ZW50LXYxL2RpYWdub3N0aWNzLnBuZyJ9LCJob3VzaW5nIjp7ImtleSI6ImhvdXNpbmciLCJieXRlcyI6MTYwOTM2Miwic2hhMjU2IjoiMTBhMDBjNWRhZmE4NjJmYjE3ODI5YmFiMWMyZjNkMTRmZTgxZmFmYWRjYzM3MzMwYTAwMzM0NDc4NmYwNDA3YiIsIndpZHRoIjoxMjU0LCJoZWlnaHQiOjEyNTQsInVybCI6Imh0dHBzOi8vdG91ci1tYXgucnUvbmV3L2FwcGxlc2VydmljZTM5L2NvdmVycy9hZC1pbnRlbnQtdjEvaG91c2luZy5wbmcifX0sInRhcmdldHMiOnsibW9kZWwtaXBob25lLXgiOnsibWlkIjoibWlkLmZmZmZiNjY3ZDIwYTE1NzEwMWExYmQ2Y2ZjYjEwYTRhIiwidXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hcHBsZXNlcnZpY2UzOXJ1L0FhRzliUHl4Q2tvIiwiYmVmb3JlX3RleHQiOiLQoNC10LzQvtC90YIgaVBob25lIFgg0LIg0JrQsNC70LjQvdC40L3Qs9GA0LDQtNC1XG5cbtCQ0LrQutGD0LzRg9C70Y/RgtC+0YAsINGB0YLQtdC60LvQviDQuNC70Lgg0LTQuNGB0L/Qu9C10Lkg4oCUINCy0YvQsdC10YDQuNGC0LUg0L/QvtC00YXQvtC00Y/RidGD0Y4g0YPRgdC70YPQs9GDINC/0L4g0YHRgdGL0LvQutCw0Lwg0L3QuNC20LUuXG5cbtCX0LDQvNC10L3QsCDQsNC60LrRg9C80YPQu9GP0YLQvtGA0LA6INCX0LDQvNC10L3QsCDQsNC60LrRg9C80YPQu9GP0YLQvtGA0LAg0L/QviDQv9GA0LDQudGB0YMg4oCUIDMgNTAwIOKCvS4g0JTQuNCw0LPQvdC+0YHRgtC40LrQsCDQsdC10YHQv9C70LDRgtC90LDRjy4g0JDQutGC0YPQsNC70YzQvdGD0Y4g0YbQtdC90YMg0Lgg0L3QsNC70LjRh9C40LUg0LTQtdGC0LDQu9C4INGD0YLQvtGH0L3QuNC8INC00L4g0YDQtdC80L7QvdGC0LAuXG7Ql9Cw0LzQtdC90LAg0YHRgtC10LrQu9CwINCx0LXQtyDQtNC40YHQv9C70LXRjzog0KHRgtC+0LjQvNC+0YHRgtGMINC/0L4g0L/RgNCw0LnRgdGDIOKAlCA1IDAwMCDigr0uINCQ0LrRgtGD0LDQu9GM0L3Rg9GOINGG0LXQvdGDINC4INC90LDQu9C40YfQuNC1INC00LXRgtCw0LvQuCDRg9GC0L7Rh9C90LjQvCDQtNC+INGA0LXQvNC+0L3RgtCwLlxu0JfQsNC80LXQvdCwINC00LjRgdC/0LvQtdGPOiDQntGA0LjQs9C40L3QsNC70YzQvdGL0Lkg0LTQuNGB0L/Qu9C10Lkg4oCUIDEyIDAwMCDigr0uINCQ0L3QsNC70L7QsyDigJQgNiAwMDAg4oK9LiDQptC10L3RiyDQv9C+INC/0YDQsNC50YHRgy4g0KPRgtC+0YfQvdC40Lwg0L3QsNC70LjRh9C40LUg0Lgg0YPRgdC70L7QstC40Y8g0LLRi9Cx0YDQsNC90L3QvtCz0L4g0LLQsNGA0LjQsNC90YLQsC5cblxu0JPQsNGA0LDQvdGC0LjRjyDigJQgMyDQvNC10YHRj9GG0LAuXG5cbtCd0LDQttC80LjRgtC1IMKr0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgNC10LzQvtC90YLQsMK7LCDRh9GC0L7QsdGLINC+0L/QuNGB0LDRgtGMINC/0YDQvtCx0LvQtdC80YMg0Lgg0YHQvtCz0LvQsNGB0L7QstCw0YLRjCDRgNC10LzQvtC90YIuINCd0LDQu9C40YfQuNC1INC00LXRgtCw0LvQuCwg0L7QutC+0L3Rh9Cw0YLQtdC70YzQvdGD0Y4g0YHRgtC+0LjQvNC+0YHRgtGMINC4INGB0YDQvtC6INGD0YLQvtGH0L3QuNC8INC00L4g0L3QsNGH0LDQu9CwINGA0LDQsdC+0YIuXG5cbkFwcGxlU2VydmljZTM5IMK3INCa0LDQu9C40L3QuNC90LPRgNCw0LRcbtCb0LXQvdC40L3RgdC60LjQuSDQv9GA0L7RgdC/0LXQutGCLCAxOCDCtyA0INGN0YLQsNC2LCDQvtGE0LjRgSA0MDRcblxu0JLRi9Cx0LXRgNC40YLQtSDRgNC10LzQvtC90YJcbtCU0LjRgdC/0LvQtdC5XG7QodGC0LXQutC70L4g0Y3QutGA0LDQvdCwXG7QkNC60LrRg9C80YPQu9GP0YLQvtGAXG7QnNC10L3RjiDRgNC10LzQvtC90YLQsCIsImJlZm9yZV9tYXJrdXAiOlt7InR5cGUiOiJzdHJvbmciLCJmcm9tIjowLCJsZW5ndGgiOjMwfSx7InR5cGUiOiJsaW5rIiwiZnJvbSI6NzU2LCJsZW5ndGgiOjcsInVybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYXBwbGVzZXJ2aWNlMzlydS9BYUc5Ykw3a1dmNCJ9LHsidHlwZSI6ImxpbmsiLCJmcm9tIjo3NjQsImxlbmd0aCI6MTMsInVybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYXBwbGVzZXJ2aWNlMzlydS9BYUc5YkxvT0UzOCJ9LHsidHlwZSI6ImxpbmsiLCJmcm9tIjo3NzgsImxlbmd0aCI6MTEsInVybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYXBwbGVzZXJ2aWNlMzlydS9BYUc5SFFic1h2ayJ9LHsidHlwZSI6ImxpbmsiLCJmcm9tIjo3OTAsImxlbmd0aCI6MTIsInVybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYXBwbGVzZXJ2aWNlMzlydS9BYUc5YlNNNVk5byJ9XSwiYmVmb3JlX3Bob3RvX2lkIjoiNDY3NTA4OTgyNTcifSwibW9kZWwtaXBob25lLTE3Ijp7Im1pZCI6Im1pZC5mZmZmYjY2N2QyMGExNTcxMDFhMWM5ZTFmZmJlNWRiMiIsInVybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYXBwbGVzZXJ2aWNlMzlydS9BYUhKNGYtLVhiSSIsImJlZm9yZV90ZXh0Ijoi0KDQtdC80L7QvdGCIGlQaG9uZSAxNyDQsiDQmtCw0LvQuNC90LjQvdCz0YDQsNC00LVcblxu0KDQtdC80L7QvdGCIGlQaG9uZSAxNywgMTcgUHJvINC4IDE3IFBybyBNYXgg0LIgQXBwbGVTZXJ2aWNlMzkuINCf0L7QvNC+0LbQtdC8INGBINC/0L7QstGA0LXQttC00ZHQvdC90YvQvCDRjdC60YDQsNC90L7QvCwg0LDQutC60YPQvNGD0LvRj9GC0L7RgNC+0LwsINGA0LDQt9GK0ZHQvNC+0Lwg0LfQsNGA0Y/QtNC60Lgg0Lgg0LTRgNGD0LPQuNC80Lgg0L3QtdC40YHQv9GA0LDQstC90L7RgdGC0Y/QvNC4LCDQv9GA0LXQtNGD0YHQvNC+0YLRgNC10L3QvdGL0LzQuCDQtNC70Y8g0LLQsNGI0LXQuSDQvNC+0LTQtdC70LguXG5cbtCj0YLQvtGH0L3QuNC8INGC0L7Rh9C90YPRjiDQstC10YDRgdC40Y4gaVBob25lLCDQv9GA0L7QstC10YDQuNC8INC/0L7QtNGF0L7QtNGP0YnQuNC5INCy0LDRgNC40LDQvdGCINGA0LXQvNC+0L3RgtCwINC4INC90LDQu9C40YfQuNC1INC00LXRgtCw0LvQuC4g0KHRgtC+0LjQvNC+0YHRgtGMINC4INGB0YDQvtC6INGB0L7Qs9C70LDRgdGD0LXQvCDQtNC+INC90LDRh9Cw0LvQsCDRgNCw0LHQvtGCLlxuXG7Qk9Cw0YDQsNC90YLQuNGPINC90LAg0YDQtdC80L7QvdGCIOKAlCAzINC80LXRgdGP0YbQsC5cblxu0JrQsNC70LjQvdC40L3Qs9GA0LDQtCwg0JvQtdC90LjQvdGB0LrQuNC5INC/0YDQvtGB0L/QtdC60YIsIDE4IMK3IDQg0Y3RgtCw0LYsINC+0YTQuNGBIDQwNFxuXG7QndCw0LbQvNC40YLQtSDCq9Cj0LfQvdCw0YLRjCDRgdGC0L7QuNC80L7RgdGC0Ywg0YDQtdC80L7QvdGC0LDCuyDigJQg0YPRgtC+0YfQvdC40Lwg0LLQsNGA0LjQsNC90YIgaVBob25lIDE3INC4INCy0L7Qt9C80L7QttC90L7RgdGC0Ywg0LfQsNC/0LjRgdC4LlxuXG7QktGL0LHQtdGA0LjRgtC1INGA0LXQvNC+0L3RgiBpUGhvbmUgMTdcbtCX0LDQvNC10L3QsCDQsNC60LrRg9C80YPQu9GP0YLQvtGA0LBcbtCX0LDQvNC10L3QsCDRgdGC0LXQutC70LAg0LHQtdC3INC00LjRgdC/0LvQtdGPXG7Ql9Cw0LzQtdC90LAg0LTQuNGB0L/Qu9C10Y8gaVBob25lXG7Ql9Cw0LzQtdC90LAg0LfQsNC00L3QtdCz0L4g0YHRgtC10LrQu9CwIGlQaG9uZVxu0KDQtdC80L7QvdGCIGlQaG9uZSIsImJlZm9yZV9tYXJrdXAiOlt7InR5cGUiOiJzdHJvbmciLCJmcm9tIjowLCJsZW5ndGgiOjMxfSx7InR5cGUiOiJsaW5rIiwiZnJvbSI6NTQ1LCJsZW5ndGgiOjE5LCJ1cmwiOiJodHRwczovL21heC5ydS9pZDM5MDUyMTEyOTA2Ml9ib3Q/c3RhcnQ9YXMzOV9wYWlyX21vZGVsLWlwaG9uZS0xN19iYXR0ZXJ5In0seyJ0eXBlIjoibGluayIsImZyb20iOjU2NSwibGVuZ3RoIjoyNSwidXJsIjoiaHR0cHM6Ly9tYXgucnUvaWQzOTA1MjExMjkwNjJfYm90P3N0YXJ0PWFzMzlfcGFpcl9tb2RlbC1pcGhvbmUtMTdfZ2xhc3MifSx7InR5cGUiOiJsaW5rIiwiZnJvbSI6NTkxLCJsZW5ndGgiOjIxLCJ1cmwiOiJodHRwczovL21heC5ydS9pZDM5MDUyMTEyOTA2Ml9ib3Q/c3RhcnQ9YXMzOV9wYWlyX21vZGVsLWlwaG9uZS0xN19kaXNwbGF5In0seyJ0eXBlIjoibGluayIsImZyb20iOjYxMywibGVuZ3RoIjoyOCwidXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hcHBsZXNlcnZpY2UzOXJ1L0FhSEo0WlZQT1lrIn0seyJ0eXBlIjoibGluayIsImZyb20iOjY0MiwibGVuZ3RoIjoxMywidXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hcHBsZXNlcnZpY2UzOXJ1L0FhRzliU001WTlvIn1dLCJiZWZvcmVfcGhvdG9faWQiOiI0NzE5NzQxNDIyNSJ9LCJzZXJ2aWNlLWJhdHRlcnktcmVwbGFjZW1lbnQiOnsibWlkIjoibWlkLmZmZmZiNjY3ZDIwYTE1NzEwMWExYmQ2ZDFlNmY2ZWViIiwidXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hcHBsZXNlcnZpY2UzOXJ1L0FhRzliUjV2YnVzIiwiYmVmb3JlX3RleHQiOiLQl9Cw0LzQtdC90LAg0LDQutC60YPQvNGD0LvRj9GC0L7RgNCwIGlQaG9uZTog0YbQtdC90Ysg0L/QviDQvNC+0LTQtdC70Y/QvFxuXG7Ql9Cw0LzQtdC90LAg0LDQutC60YPQvNGD0LvRj9GC0L7RgNCwINC00LvRjyAzMyDQvNC+0LTQtdC70LXQuSBpUGhvbmUuINCd0LjQttC1IOKAlCDRhtC10L3RiyDQv9C+INC80L7QtNC10LvQuCDQuNC3INC/0YDQsNC50YHQsCDRg9GB0LvRg9Cz0LguINCV0YHQu9C4INC/0YDQuNGH0LjQvdCwINGA0LDQt9GA0Y/QtNC60Lgg0LTRgNGD0LPQsNGPLCDRgdC90LDRh9Cw0LvQsCDRg9GC0L7Rh9C90LjQvCDQvdC10L7QsdGF0L7QtNC40LzRi9C5INGA0LXQvNC+0L3Rgi5cblxuaVBob25lIDgg4oCUIDMgMDAwIOKCvTsgOCBQbHVzIOKAlCAzIDUwMCDigr1cbmlQaG9uZSBYIC8gWFIgLyBYUyDigJQgMyA1MDAg4oK9OyBYUyBNYXgg4oCUIDQgNTAwIOKCvVxuaVBob25lIDExIOKAlCA0IDAwMCDigr07IDExIFBybyAvIDExIFBybyBNYXgg4oCUIDQgNTAwIOKCvVxuaVBob25lIDEyIC8gMTIgbWluaSAvIDEyIFBybyDigJQgNSAwMDAg4oK9OyAxMiBQcm8gTWF4IOKAlCA1IDUwMCDigr1cbmlQaG9uZSAxMyAvIDEzIG1pbmkg4oCUIDUgNTAwIOKCvTsgMTMgUHJvIC8gMTMgUHJvIE1heCDigJQgNiA1MDAg4oK9XG5pUGhvbmUgMTQgLyAxNCBQbHVzIC8gMTQgUHJvIOKAlCA2IDUwMCDigr07IDE0IFBybyBNYXgg4oCUIDcgMDAwIOKCvVxuaVBob25lIDE1IC8gMTUgUGx1cyDigJQgNiAwMDAg4oK9OyAxNSBQcm8gLyAxNSBQcm8gTWF4IOKAlCA3IDAwMCDigr1cbmlQaG9uZSAxNiAvIDE2IFBsdXMg4oCUIDYgNTAwIOKCvTsgMTYgUHJvIOKAlCA3IDAwMCDigr07IDE2IFBybyBNYXgg4oCUIDggMDAwIOKCvVxuaVBob25lIDE3IOKAlCAxMyAwMDAg4oK9OyAxNyBQcm8gLyBBaXIg4oCUIDE1IDAwMCDigr07IDE3IFBybyBNYXgg4oCUIDE2IDAwMCDigr1cblxu0JPQsNGA0LDQvdGC0LjRjyDigJQgMyDQvNC10YHRj9GG0LAuXG5cbtCd0LDQttC80LjRgtC1IMKr0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgNC10LzQvtC90YLQsMK7INC4INGD0LrQsNC20LjRgtC1INGC0L7Rh9C90YPRjiDQvNC+0LTQtdC70YwuINCj0YLQvtGH0L3QuNC8INCy0LDRgNC40LDQvdGCINC4INC90LDQu9C40YfQuNC1INC00LXRgtCw0LvQuCwg0YHQvtCz0LvQsNGB0YPQtdC8INGB0YLQvtC40LzQvtGB0YLRjCDQuCDRgdGA0L7QuiDQtNC+INGA0LXQvNC+0L3RgtCwLlxuXG5BcHBsZVNlcnZpY2UzOSDCtyDQmtCw0LvQuNC90LjQvdCz0YDQsNC0XG7Qm9C10L3QuNC90YHQutC40Lkg0L/RgNC+0YHQv9C10LrRgiwgMTggwrcgNCDRjdGC0LDQtiwg0L7RhNC40YEgNDA0XG5cbtCf0L7QtNGA0L7QsdC90YvQtSDQutCw0YDRgtC+0YfQutC4XG5pUGhvbmUgMTVcbmlQaG9uZSAxNSBQbHVzXG5pUGhvbmUgMTUgUHJvXG5pUGhvbmUgMTUgUHJvIE1heFxuaVBob25lIFhcbtCc0LXQvdGOINGA0LXQvNC+0L3RgtCwIiwiYmVmb3JlX21hcmt1cCI6W3sidHlwZSI6InN0cm9uZyIsImZyb20iOjAsImxlbmd0aCI6NDN9LHsidHlwZSI6ImxpbmsiLCJmcm9tIjo5NjEsImxlbmd0aCI6OSwidXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hcHBsZXNlcnZpY2UzOXJ1L0FhRzliTU9xWTVZIn0seyJ0eXBlIjoibGluayIsImZyb20iOjk3MSwibGVuZ3RoIjoxNCwidXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hcHBsZXNlcnZpY2UzOXJ1L0FhRzliTklZZFdnIn0seyJ0eXBlIjoibGluayIsImZyb20iOjk4NiwibGVuZ3RoIjoxMywidXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hcHBsZXNlcnZpY2UzOXJ1L0FhRzliT0JJRmJJIn0seyJ0eXBlIjoibGluayIsImZyb20iOjEwMDAsImxlbmd0aCI6MTcsInVybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYXBwbGVzZXJ2aWNlMzlydS9BYUc5Yk81NmVIZyJ9LHsidHlwZSI6ImxpbmsiLCJmcm9tIjoxMDE4LCJsZW5ndGgiOjgsInVybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYXBwbGVzZXJ2aWNlMzlydS9BYUc5SFFic1h2ayJ9LHsidHlwZSI6ImxpbmsiLCJmcm9tIjoxMDI3LCJsZW5ndGgiOjEyLCJ1cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFHOWJTTTVZOW8ifV0sImJlZm9yZV9waG90b19pZCI6IjQ2NzUwOTAwODE3In0sIm1lbnUiOnsibWlkIjoibWlkLmZmZmZiNjY3ZDIwYTE1NzEwMWExYmQ2ZDIzMzk2M2RhIiwidXJsIjoiaHR0cHM6Ly9tYXgucnUvY2hhbm5lbF9hcHBsZXNlcnZpY2UzOXJ1L0FhRzliU001WTlvIiwiYmVmb3JlX3RleHQiOiLQoNC10LzQvtC90YIgaVBob25lINCyINCa0LDQu9C40L3QuNC90LPRgNCw0LTQtVxuXG7QrdC60YDQsNC9LCDQsNC60LrRg9C80YPQu9GP0YLQvtGALCDQt9Cw0YDRj9C00LrQsCwg0LrQsNC80LXRgNGLINC4INC30LLRg9C6IOKAlCDQv9C+0LzQvtC20LXQvCDQv9C+0LTQvtCx0YDQsNGC0Ywg0YDQtdC80L7QvdGCINC00LvRjyDQstCw0YjQtdC5INC80L7QtNC10LvQuC5cblxu0KHRgtC+0LjQvNC+0YHRgtGMINC4INGB0YDQvtC6INGB0L7Qs9C70LDRgdGD0LXQvCDQtNC+INC90LDRh9Cw0LvQsCDRgNCw0LHQvtGCLiDQk9Cw0YDQsNC90YLQuNGPINC90LAg0YDQtdC80L7QvdGCIOKAlCAzINC80LXRgdGP0YbQsC5cblxu0JrQsNC70LjQvdC40L3Qs9GA0LDQtCwg0JvQtdC90LjQvdGB0LrQuNC5INC/0YDQvtGB0L/QtdC60YIsIDE4XG40INGN0YLQsNC2LCDQvtGE0LjRgSA0MDRcblxu0JrQsNGC0LDQu9C+0LMg0Lgg0L7RgtC30YvQstGLXG7QktGB0LUg0LzQvtC00LXQu9C4IGlQaG9uZVxu0JLRgdC1INGD0YHQu9GD0LPQuCDQuCDQstCw0YDQuNCw0L3RgtGLINGA0LXQvNC+0L3RgtCwXG4zMDArINC+0YbQtdC90L7QuiDQutC70LjQtdC90YLQvtCyIOKAlCDRh9C40YLQsNGC0Ywg0L7RgtC30YvQstGLXG5cbtCd0LDQttC80LjRgtC1IMKr0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgNC10LzQvtC90YLQsMK7IOKAlCDRg9GC0L7Rh9C90LjQvCDRgNC10LzQvtC90YIg0Lgg0YPQtNC+0LHQvdC+0LUg0LLRgNC10LzRjy4iLCJiZWZvcmVfbWFya3VwIjpbeyJ0eXBlIjoic3Ryb25nIiwiZnJvbSI6MCwibGVuZ3RoIjoyOH0seyJ0eXBlIjoic3Ryb25nIiwiZnJvbSI6MjQ5LCJsZW5ndGgiOjE2fSx7InR5cGUiOiJsaW5rIiwiZnJvbSI6MjY2LCJsZW5ndGgiOjE3LCJ1cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFIUUdfbVRENXcifSx7InR5cGUiOiJsaW5rIiwiZnJvbSI6Mjg0LCJsZW5ndGgiOjI5LCJ1cmwiOiJodHRwczovL21heC5ydS9jaGFubmVsX2FwcGxlc2VydmljZTM5cnUvQWFIUUg3RU5LSHMifSx7InR5cGUiOiJsaW5rIiwiZnJvbSI6MzE0LCJsZW5ndGgiOjM2LCJ1cmwiOiJodHRwczovL3lhbmRleC5ydS9tYXBzL29yZy9hcHBsZV9zZXJ2aWNlMzkvMTczMTU1MDYyNy9yZXZpZXdzLyJ9XSwiYmVmb3JlX3Bob3RvX2lkIjoiNDcxOTc0NDgwMTcifX0sInByaWNlX3NvdXJjZSI6eyJ1cmwiOiJodHRwczovL2FwcGxlc2VydmljZTM5LnJ1L3JlbW9udC1pcGhvbmUvemFtZW5hLWJhdGFyZWktYXlmb24taXBob25lLXYta2FsaW5pbmdyYWRlLyIsInNoYTI1NiI6ImI2MjE1OWM1ZDA1OWJmZjY2OTU0ZmQ2MThlNmRmMDZmNjMxM2Y1MTdkYzQ0NmZkMzQ0NWE2MmNiYTBkOGU5OWIiLCJvYnNlcnZlZF9hdCI6IjIwMjYtMTEtMTRUMTY6MDM6MTUuMTM4MTE1KzAwOjAwIiwiY2hhbmdlcyI6W3sic291cmNlX2lkIjoiYXMzOV83YmM2ZjYwMTA0MTNmNjdiMzFjYiIsInJvd19udW1iZXIiOjE0LCJiZWZvcmUiOlsiSVBob25lIDEzLzEzIG1pbmkiLCI1NTAwINGA0YPQsS4iLCLinJQiLCI2MCDQvNC40L0uIl0sImN1cnJlbnQiOlsiSVBob25lIDEzLzEzIG1pbmkiLCI1MDAwINGA0YPQsS4iLCLinJQiLCI2MCDQvNC40L0uIl19LHsic291cmNlX2lkIjoiYXMzOV9hYTY4NTVkZWRkZWZkMjg3ZTczZiIsInJvd19udW1iZXIiOjE1LCJiZWZvcmUiOlsiSVBob25lIDEzIHBybyIsIjY1MDAg0YDRg9CxLiIsIuKclCIsIjYwINC80LjQvS4iXSwiY3VycmVudCI6WyJJUGhvbmUgMTMgcHJvIiwiNjAwMCDRgNGD0LEuIiwi4pyUIiwiNjAg0LzQuNC9LiJdfSx7InNvdXJjZV9pZCI6ImFzMzlfYjMxMTFmZTZhODI5MTIxYzEyNWUiLCJyb3dfbnVtYmVyIjoxNywiYmVmb3JlIjpbIklQaG9uZSAxNCIsIjY1MDAg0YDRg9CxLiIsIuKclCIsIjYwINC80LjQvS4iXSwiY3VycmVudCI6WyJJUGhvbmUgMTQiLCI1NTAwINGA0YPQsS4iLCLinJQiLCI2MCDQvNC40L0uIl19LHsic291cmNlX2lkIjoiYXMzOV9mZDk4YWVjZTI3ZjNlNDA5ZWUwYyIsInJvd19udW1iZXIiOjE4LCJiZWZvcmUiOlsiSVBob25lIDE0IHBsdXMiLCI2NTAwINGA0YPQsS4iLCLinJQiLCI2MCDQvNC40L0uIl0sImN1cnJlbnQiOlsiSVBob25lIDE0IHBsdXMiLCI2MDAwINGA0YPQsS4iLCLinJQiLCI2MCDQvNC40L0uIl19LHsic291cmNlX2lkIjoiYXMzOV8zNzlhNzYyYjRkMDcxMjE2N2E5ZCIsInJvd19udW1iZXIiOjIwLCJiZWZvcmUiOlsiSVBob25lIDE0IHBybyBtYXgiLCI3MDAwINGA0YPQsS4iLCLinJQiLCI2MCDQvNC40L0uIl0sImN1cnJlbnQiOlsiSVBob25lIDE0IHBybyBtYXgiLCI2NTAwINGA0YPQsS4iLCLinJQiLCI2MCDQvNC40L0uIl19XX19';

function am_ck(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
function am_emit(array $value): void { echo json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL; }
function am_canon($value) { if (!is_array($value)) return $value; if (!array_is_list($value)) ksort($value); foreach ($value as &$item) $item=am_canon($item); unset($item); return $value; }
function am_json($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function am_digest($value): string { return hash('sha256',am_json(am_canon($value))); }
function am_invoke(object $owner, string $method, ...$args) { return (new ReflectionMethod($owner,$method))->invoke($owner,...$args); }
function am_reason(Throwable $error): string { $r=$error->getMessage(); return preg_match('/\A[a-z_]{2,100}\z/D',$r) ? $r : 'media_operation_unavailable'; }
function am_require_decoder(): void {
    foreach(['imagecreatefromstring','imagecreatetruecolor','imagecopyresampled','imagecolorat','imagejpeg','imagewebp','mb_strlen','curl_init'] as $fn)
        am_ck(function_exists($fn),'required_image_decoder_unavailable');
    am_ck((imagetypes()&IMG_JPG)!==0&&(imagetypes()&IMG_PNG)!==0&&(imagetypes()&IMG_WEBP)!==0,'required_image_decoder_unavailable');
}
function am_decode(string $bytes): array {
    am_ck(strlen($bytes)>100&&strlen($bytes)<=6291456,'native_image_size_invalid');
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
    am_ck(isset($sources[$expected])&&count($sources)===20,'comparison_sources_incomplete');
    $native=am_samples($nativeBytes);$scores=[];
    foreach($sources as $key=>$sample)$scores[$key]=am_difference($sample['rank'],$native['rank'],false)['mae'];
    asort($scores,SORT_NUMERIC);$keys=array_keys($scores);$best=$keys[0];$runnerUp=$keys[1];
    $detail=am_difference($sources[$expected]['full'],$native['full'],true);
    imagedestroy($native['rank']);imagedestroy($native['full']);
    $margin=$scores[$runnerUp]-$scores[$best];
    $accepted=$best===$expected&&$margin>=2.0&&$detail['mae']<=4.0&&$detail['rmse']<=10.0
        &&$detail['max_tile_mae']<=12.0&&$detail['high_difference_fraction']<=0.02;
    return ['method'=>'gd_resampled_rgb_unique_match_v1','accepted'=>$accepted,'expected'=>$expected,'best'=>$best,
        'runner_up'=>$runnerUp,'ranking_side'=>64,'detail_side'=>256,'candidate_count'=>20,'margin'=>$margin,
        'ranking_mae'=>$scores,'detail'=>$detail,'native_width'=>$native['width'],'native_height'=>$native['height'],
        'native_mime'=>$native['mime'],'thresholds'=>['min_unique_margin'=>2.0,'max_mae'=>4.0,'max_rmse'=>10.0,
            'max_tile_mae'=>12.0,'max_high_difference_fraction'=>0.02]];
}
function am_url(string $url,string $kind): void {
    $p=parse_url($url);am_ck(is_array($p)&&($p['scheme']??null)==='https'&&!isset($p['port'])&&!isset($p['user'])
        &&!isset($p['pass'])&&!isset($p['fragment'])&&strlen($url)<=8192&&!preg_match('/[\x00-\x20\x7f]/',$url),'image_url_refused');
    if($kind==='price'){am_ck($url===AQ_PRICE_URL,'price_source_url_refused');return;}
    if($kind==='source')am_ck(preg_match('~\Ahttps://tour-max\.ru/new/appleservice39/covers/ad-intent-v1/[a-z0-9-]+\.png\z~D',$url)===1,'source_image_url_refused');
    else am_ck(in_array($p['host']??'', ['i.oneme.ru','iu.oneme.ru'],true),'native_image_host_refused');
}
function am_download(string $url,string $kind): string {
    am_url($url,$kind);$raw='';$curl=curl_init($url);am_ck($curl!==false,'image_download_failed');
    $options=MaxTlsConfig::strictCurlOptions()+[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>25,CURLOPT_RETURNTRANSFER=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>[$kind==='price'?'Accept: text/html':'Accept: image/png,image/jpeg,image/webp','User-Agent: AppleService39-channel-audit/1'],
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>6291456)return 0;$raw.=$chunk;return strlen($chunk);}];
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

function aq_package(): array {
    $raw=base64_decode(AQ_PACKAGE_BASE64,true);
    am_ck(is_string($raw)&&hash('sha256',$raw)===AQ_PACKAGE_SHA256,'audit_package_changed');
    $p=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
    am_ck($p['revision']===AM_REVISION&&count($p['assets'])===20
        &&array_keys($p['targets'])===['model-iphone-x','model-iphone-17','service-battery-replacement','menu']
        &&$p['price_source']['url']===AQ_PRICE_URL,'audit_scope_changed');
    foreach($p['assets']as$key=>$a)am_ck($a['key']===$key&&$a['width']===1254&&$a['height']===1254
        &&preg_match('/\A[a-f0-9]{64}\z/D',$a['sha256'])===1
        &&$a['url']==='https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/'.$key.'.png','audit_asset_invalid');
    return $p;
}
function aq_plain(string $s): string {return trim((string)preg_replace('/[\s\p{Z}]+/u',' ',$s));}
function aq_links(string $text,array $markup): array {
    usort($markup,static fn($a,$b)=>$a['from']<=>$b['from']);$out=[];
    foreach($markup as$s)if($s['type']==='link')$out[]=['label'=>mb_substr($text,$s['from'],$s['length'],'UTF-8'),'url'=>$s['url']];
    return $out;
}
function aq_replace(string $text,array $markup,string $old,string $next): array {
    $at=mb_strpos($text,$old,0,'UTF-8');
    am_ck($at!==false&&mb_strpos($text,$old,$at+1,'UTF-8')===false,'caption_phrase_changed');
    $length=mb_strlen($old,'UTF-8');$delta=mb_strlen($next,'UTF-8')-$length;
    foreach($markup as&$s){
        if($s['from']+$s['length']<=$at)continue;
        if($s['from']>=$at+$length){$s['from']+=$delta;continue;}
        // The single reviewed iPhone17 link label may lengthen, keeping its URL.
        am_ck($s['from']===$at&&$s['length']===$length&&$s['type']==='link'
            &&$old==='Замена дисплея iPhone'&&$next==='Замена дисплея iPhone 17'
            &&$s['url']==='https://max.ru/id390521129062_bot?start=as39_pair_model-iphone-17_display','edit_overlaps_native_markup');
        $s['length']+= $delta;
    }unset($s);
    return [mb_substr($text,0,$at,'UTF-8').$next.mb_substr($text,$at+$length,null,'UTF-8'),$markup];
}
function aq_plan(string $id,string $text,array $markup): array {
    $p=aq_package();am_ck(isset($p['targets'][$id]),'unreviewed_target');$fixture=$p['targets'][$id];
    am_ck(aq_plain($text)===aq_plain($fixture['before_text'])
        &&aq_links($text,$markup)===aq_links($fixture['before_text'],$fixture['before_markup']),'caption_preimage_changed');
    $changes=match($id){
        'model-iphone-x'=>[['Замена аккумулятора: Замена аккумулятора по прайсу —','Замена аккумулятора по прайсу —']],
        'model-iphone-17'=>[
            ['Ремонт iPhone 17, 17 Pro и 17 Pro Max в AppleService39.','Ремонт iPhone 17 в AppleService39.'],
            ['Уточним точную версию iPhone, проверим','Проверим'],
            ['уточним вариант iPhone 17 и возможность записи.','уточним подходящий ремонт и возможность записи.'],
            ['Замена дисплея iPhone','Замена дисплея iPhone 17'],
        ],
        'service-battery-replacement'=>[
            ['iPhone 13 / 13 mini — 5 500 ₽; 13 Pro / 13 Pro Max — 6 500 ₽','iPhone 13 / 13 mini — 5 000 ₽; 13 Pro — 6 000 ₽; 13 Pro Max — 6 500 ₽'],
            ['iPhone 14 / 14 Plus / 14 Pro — 6 500 ₽; 14 Pro Max — 7 000 ₽','iPhone 14 — 5 500 ₽; 14 Plus — 6 000 ₽; 14 Pro / 14 Pro Max — 6 500 ₽'],
        ],
        'menu'=>[],
    };
    $oldLinks=aq_links($text,$markup);
    foreach($changes as[$old,$next])[$text,$markup]=aq_replace($text,$markup,$old,$next);
    $newLinks=aq_links($text,$markup);
    am_ck(array_column($oldLinks,'url')===array_column($newLinks,'url'),'link_destination_changed');
    if($id==='model-iphone-17')$oldLinks[2]['label']='Замена дисплея iPhone 17';
    am_ck($oldLinks===$newLinks&&substr_count($text,'«'.AM_CTA.'»')===1
        &&str_contains($text,'3 месяца')&&str_contains($text,'Ленинский проспект, 18'),'preserved_caption_contract_changed');
    return am_html_plan($text,$markup);
}
function aq_runtime_context(): void {
    $entry=AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot','as39_model-iphone-17');
    am_ck(is_array($entry)&&$entry['post_id']==='model-iphone-17'&&$entry['scope']==='model'
        &&$entry['model']==='iPhone 17'&&$entry['service']==='','exact_iphone17_runtime_not_installed');
    $reply=AppleService39PilotCatalog::responseText($entry,'AppleService39');
    am_ck(str_contains($reply,'iPhone 17')&&!str_contains($reply,'17 Pro')&&!str_contains($reply,'версия'),'exact_iphone17_runtime_not_installed');
}
function aq_self_test(string $root): void {
    require_once $root.'/services/AppleService39PilotCatalog.php';
    am_require_decoder();$p=aq_package();$checks=0;
    $assert=static function(bool $ok)use(&$checks):void{am_ck($ok,'audit_selftest_failed');$checks++;};
    foreach($p['targets']as$id=>$f){
        $next=aq_plan($id,$f['before_text'],$f['before_markup']);
        $assert($next['body']['format']==='html'&&!isset($next['body']['attachments']));
        $assert(array_column(aq_links($next['native_text'],$next['native_markup']),'url')===array_column(aq_links($f['before_text'],$f['before_markup']),'url'));
        $assert(($next['native_text']===$f['before_text'])===($id==='menu'));
        $assert(str_contains($next['native_text'],'3 месяца')&&str_contains($next['native_text'],'офис 404'));
        try{aq_plan($id,$f['before_text'].' changed',$f['before_markup']);$assert(false);}catch(RuntimeException$e){$assert($e->getMessage()==='caption_preimage_changed');}
    }
    $x=aq_plan('model-iphone-x',$p['targets']['model-iphone-x']['before_text'],$p['targets']['model-iphone-x']['before_markup']);
    $assert(!str_contains($x['native_text'],'Замена аккумулятора: Замена аккумулятора'));
    $assert(str_contains($x['native_text'],'3 500 ₽')&&str_contains($x['native_text'],'12 000 ₽')&&str_contains($x['native_text'],'6 000 ₽'));
    $b=aq_plan('service-battery-replacement',$p['targets']['service-battery-replacement']['before_text'],$p['targets']['service-battery-replacement']['before_markup']);
    foreach(['13 / 13 mini — 5 000 ₽','13 Pro — 6 000 ₽','13 Pro Max — 6 500 ₽','14 — 5 500 ₽','14 Plus — 6 000 ₽','14 Pro / 14 Pro Max — 6 500 ₽']as$s)$assert(str_contains($b['native_text'],$s));
    $s=aq_plan('model-iphone-17',$p['targets']['model-iphone-17']['before_text'],$p['targets']['model-iphone-17']['before_markup']);
    $assert(!str_contains($s['native_text'],'17 Pro')&&!str_contains($s['native_text'],'точную версию'));
    $assert(aq_links($s['native_text'],$s['native_markup'])[2]['label']==='Замена дисплея iPhone 17');
    foreach(['verified','write_started','unknown','rejected']as$state)$assert(!am_writable_state(['state'=>$state,'write_attempts'=>1]));
    $assert(am_writable_state(['state'=>'prepared','write_attempts'=>0])&&!am_writable_state(['state'=>'prepared','write_attempts'=>1]));
    try{aq_replace('abcd',[['type'=>'strong','from'=>1,'length'=>2]],'bc','z');$assert(false);}catch(RuntimeException$e){$assert($e->getMessage()==='edit_overlaps_native_markup');}
    $assert(am_original_verified(['state'=>'verified','write_attempts'=>0,'operation'=>'pin','outcome'=>['already_present'=>true,'readback'=>true]]));
    am_emit(['ok'=>true,'state'=>'audit_plans_checked','checks'=>$checks,'caption_edits'=>3,'image_edits'=>1,'provider_writes'=>0]);
}

$store=null;$active=null;$assetFiles=[];$tmp=null;
try{
    $mode=$argv[1]??'';
    if($mode==='--self-test'){aq_self_test($argv[2]??dirname(__DIR__));exit;}
    if($mode==='--menu-image-test'){
        am_require_decoder();$p=aq_package();$dir=$argv[2]??'';$samples=[];
        foreach($p['assets']as$key=>$a){$bytes=file_get_contents($dir.'/'.$key.'.png');am_ck(hash('sha256',$bytes)===$a['sha256'],'source_fixture_changed');$samples[$key]=am_samples($bytes);}
        $source=am_decode(file_get_contents($dir.'/general-repair.png'));$rows=[];
        foreach(['imagejpeg','imagewebp']as$encoder){ob_start();$ok=$encoder($source['image'],null,75);$raw=ob_get_clean();am_ck($ok,'image_fixture_failed');$comparison=am_compare('general-repair',$raw,$samples);am_ck($comparison['accepted'],'menu_image_comparison_failed');$rows[]=$comparison;}
        am_ck(!am_compare('general-repair',file_get_contents($dir.'/iphone-12-family.png'),$samples)['accepted'],'wrong_menu_cover_accepted');
        am_emit(['ok'=>true,'state'=>'menu_image_formats_checked','formats'=>$rows,'wrong_model_rejected'=>true,'provider_writes'=>0]);exit;
    }
    am_ck($mode==='--apply'&&$argc===2,'fixed_operation_required');am_require_decoder();$p=aq_package();
    $private='/var/www/anytoour/data/config/max-search.php';
    am_ck(PHP_SAPI==='cli'&&realpath(AM_ROOT)===AM_ROOT&&is_file($private)&&!is_link($private),'fixed_runtime_required');
    $privateHash=hash_file('sha256',$private);
    $safe=static fn():bool=>am_git(['rev-parse','HEAD'])===AM_APP&&am_git(['status','--porcelain','--untracked-files=no'])===''
        &&is_file($private)&&!is_link($private)&&hash_file('sha256',$private)===$privateHash;
    am_ck($safe(),'checkout_changed');
    require_once AM_ROOT.'/config.php';require_once AM_ROOT.'/services/ConversationDb.php';require_once AM_ROOT.'/services/AppleService39PilotPublisher.php';
    aq_runtime_context();
    $pdo=ConversationDb::connection();am_ck(!$pdo->inTransaction(),'autocommit_required');
    $store=new AppleService39PilotPublicationStore($pdo);$original=$store->rows();
    am_ck(count($original)===357&&count(array_filter($original,static fn($r)=>!am_original_verified($r)))===0
        &&count(array_filter($original,static fn($r)=>str_starts_with($r['post_key'],AM_PREFIX)))===0,'audit_original_operations_changed');
    $parentDigest=am_digest($original);$manifest=AppleService39PilotManifest::installed();
    $pub=new AppleService39PilotPublisher($pdo,$manifest,AM_CHANNEL,$safe,null,null,new AppleService39CatalogueRevision($manifest),new AppleService39SearchIntentRevision($manifest));
    $store->assertBinding(am_invoke($pub,'preflight'));am_ck(am_invoke($pub,'pinnedMid')===AM_PIN,'pin_changed');
    $connection=ProjectMaxConnection::resolve('appleservice39');am_ck($connection!==null,'connection_unavailable');
    $transport=new AppleService39ChannelTransport($connection,AM_CHANNEL);
    $guard=static function()use($safe,$store,$original,$pub):void{
        am_ck($safe()&&am_original_rows($store->rows())===$original,'source_or_original_operations_changed');
        am_ck(am_invoke($pub,'pinnedMid')===AM_PIN,'pin_changed');
    };
    $get=static function(string $mid)use($transport):array{
        $r=$transport->message($mid);am_ck(AppleService39ChannelTransport::accepted($r),'native_read_failed');
        $m=$r['data']['message']??$r['data'];am_ck(is_array($m),'native_read_failed');return $m;
    };
    $creates=[];foreach($original as$r)if($r['operation']==='create')$creates[$r['post_key']]=$r;
    am_ck(count($creates)===69,'native_inventory_changed');
    $observe=static function(string $id,bool $originalImage=true)use($get,$creates,$original,$pub):array{
        $create=$creates[$id]??null;am_ck(is_array($create)&&$create['state']==='verified','native_publication_missing');
        $mid=$create['external_message_id'];$m=$get($mid);$sourceAttachments=null;$receiptPhoto=null;
        foreach($original as$r)if($r['post_key']===$id||($r['request']['target_post']??null)===$id){
            if(isset($r['request']['body']['attachments']))$sourceAttachments=$r['request']['body']['attachments'];
            if(isset($r['outcome']['image_receipt']['photo_id']))$receiptPhoto=$r['outcome']['image_receipt']['photo_id'];
        }
        am_ck(is_array($sourceAttachments)&&is_string($receiptPhoto),'original_attachment_binding_missing');
        $plan=am_html_plan($m['body']['text'],$m['body']['markup']??[]);$attachments=$m['body']['attachments']??null;
        am_ck(is_array($attachments)&&array_is_list($attachments)&&count($attachments)===2,'native_attachment_shape_changed');
        $types=array_column($attachments,'type');$keyAt=array_search('inline_keyboard',$types,true);$imageAt=array_search('image',$types,true);
        am_ck($keyAt!==false&&$imageAt!==false&&$keyAt!==$imageAt,'native_attachment_shape_changed');
        $sourceKey=array_search('inline_keyboard',array_column($sourceAttachments,'type'),true);am_ck($sourceKey!==false,'original_keyboard_missing');
        $buttons=$sourceAttachments[$sourceKey]['payload']['buttons']??null;
        am_ck(is_array($buttons)&&count($buttons)===1&&count($buttons[0])===1&&$buttons[0][0]['type']==='link','original_single_cta_missing');
        $buttons[0][0]['text']=AM_CTA;
        am_ck(am_invoke($pub,'sameButtons',$attachments[$keyAt]['payload']['buttons']??null,$buttons),'native_cta_changed');
        am_ck(preg_match('~\Ahttps://max\.ru/id390521129062_bot\?start=([A-Za-z0-9_-]{1,64})\z~D',(string)($buttons[0][0]['url']??''),$match)===1,'native_cta_target_invalid');
        $entry=AppleService39PilotCatalog::resolve('appleservice39','id390521129062_bot',$match[1]);
        am_ck(is_array($entry)&&$entry['post_id']===$id,'native_cta_context_mismatch');
        $payload=am_invoke($pub,'imagePayload',$m);$photo=(string)($payload['photo_id']??'');
        am_ck(preg_match('/\A-?[0-9]{1,20}\z/D',$photo)===1&&(!$originalImage||$photo===$receiptPhoto),'original_photo_changed');
        $body=$plan['body'];$body['attachments']=$sourceAttachments;$body['attachments'][$sourceKey]['payload']['buttons']=$buttons;
        am_ck(am_invoke($pub,'verifyMessageContent',$m,$mid,$body,$plan)===$create['public_url'],'native_identity_changed');
        return ['post'=>$id,'mid'=>$mid,'url'=>$create['public_url'],'plan'=>$plan,'attachments'=>$body['attachments'],'photo'=>$photo,'buttons'=>$buttons,'payload'=>$payload];
    };
    $before=[];foreach(array_keys($creates)as$id)$before[$id]=$observe($id);
    $plans=[];
    foreach($p['targets']as$id=>$f){
        $b=$before[$id];am_ck($b['mid']===$f['mid']&&$b['url']===$f['url']&&$b['photo']===$f['before_photo_id'],'fixed_target_changed');
        $plans[$id]=aq_plan($id,$b['plan']['native_text'],$b['plan']['native_markup']);
    }
    // Authoritative price snapshot is checked before preparing any operation.
    $price=am_download(AQ_PRICE_URL,'price');am_ck(hash('sha256',$price)===$p['price_source']['sha256'],'current_price_source_changed');
    $priceCheckedAt=gmdate('Y-m-d\TH:i:s\Z');
    // Decode the actual current menu image before uploads; no format inference.
    $old=AM_ROOT.'/resources/appleservice39/search-intent-v1/covers/menu.png';
    am_ck(hash_file('sha256',$old)==='2f580bbf4f0963f5a37be34db455527aa8f650597801b7d0b6e81dce790a8681','old_menu_source_changed');
    $oldSample=am_samples(file_get_contents($old));$nativeOld=am_samples(am_download((string)($before['menu']['payload']['url']??''),'native'));
    $oldDifference=am_difference($oldSample['full'],$nativeOld['full'],true);
    am_ck($oldDifference['mae']<=4.0&&$oldDifference['rmse']<=10.0&&$oldDifference['max_tile_mae']<=12.0,'old_menu_image_changed');
    $tmp=sys_get_temp_dir().'/as39-audit-'.bin2hex(random_bytes(8));am_ck(mkdir($tmp,0700),'temporary_directory_failed');$sources=[];
    foreach($p['assets']as$key=>$asset){
        $raw=am_download($asset['url'],'source');am_ck(strlen($raw)===$asset['bytes']&&hash('sha256',$raw)===$asset['sha256'],'approved_original_changed');
        $sources[$key]=am_samples($raw);
        if($key==='general-repair'){$assetFiles[$key]=$tmp.'/'.$key.'.png';am_ck(file_put_contents($assetFiles[$key],$raw,LOCK_EX)===strlen($raw),'source_temporary_write_failed');chmod($assetFiles[$key],0600);}
    }
    am_emit(['stage'=>'audit_preflight_complete','native_posts'=>69,'verified_original_operations'=>357,'source_price_sha256'=>$p['price_source']['sha256'],
        'price_checked_at'=>$priceCheckedAt,'exact_iphone17_runtime'=>true,'caption_targets'=>3,'menu_source_sha256'=>$p['assets']['general-repair']['sha256']]);
    // Text-only updates omit attachments and retain every actual image/keyboard.
    foreach(['model-iphone-x','model-iphone-17','service-battery-replacement']as$id){
        $b=$before[$id];$next=$plans[$id];$next['body']['notify']=false;$guard();
        $req=['revision'=>AM_REVISION,'revision_sha256'=>AQ_PACKAGE_SHA256,'target_post'=>$id,'message_id'=>$b['mid'],'url'=>$b['url'],
            'original_operations_sha256'=>$parentDigest,'before_text_sha256'=>hash('sha256',$b['plan']['native_text']),
            'before_markup'=>$b['plan']['native_markup'],'preserved_photo_id'=>$b['photo'],'preserved_buttons'=>$b['buttons'],
            'body'=>$next['body'],'native_text'=>$next['native_text'],'native_markup'=>$next['native_markup']];
        if($id==='service-battery-replacement')$req['price_source']=$p['price_source']+['verified_at'=>$priceCheckedAt];
        $row=$store->prepare(AM_PREFIX.$id,'links',$req);am_ck(am_writable_state($row),'caption_operation_already_attempted');
        am_invoke($pub,'beforeWrite',$row,static function()use($guard,$observe,$id,$b):void{$guard();am_ck(am_fingerprint($observe($id))===am_fingerprint($b),'native_preimage_changed');});
        $active=$row;$store->rememberMessage($row,$b['mid'],$b['url']);
        $ack=$transport->edit($b['mid'],$next['body']);$acknowledged=AppleService39ChannelTransport::accepted($ack)&&($ack['data']['success']??null)===true;
        $after=$observe($id);
        am_ck($after['plan']['native_text']===$next['native_text']&&AppleService39LandingRevision::sameMarkup($after['plan']['native_markup'],$next['native_markup'])
            &&$after['photo']===$b['photo']&&am_canon($after['buttons'])===am_canon($b['buttons']),'caption_readback_failed');
        $store->finish($row,'verified',['readback'=>true,'native_text_sha256'=>hash('sha256',$after['plan']['native_text']),
            'photo_preserved'=>true,'single_cta_preserved'=>true,'provider_acknowledged'=>$acknowledged]);$active=null;
        am_emit(['stage'=>'caption_verified','post_id'=>$id,'url'=>$after['url'],'native_text'=>$after['plan']['native_text'],
            'native_markup'=>$after['plan']['native_markup'],'photo_id'=>$after['photo'],'photo_preserved'=>true,'single_cta_preserved'=>true]);usleep(650000);
    }
    // Exactly one approved menu original is uploaded and bound to its own receipt.
    $guard();$asset=$p['assets']['general-repair'];
    $uploadRequest=['revision'=>AM_REVISION,'revision_sha256'=>AQ_PACKAGE_SHA256,'cover_key'=>'general-repair',
        'source_sha256'=>$asset['sha256'],'source_bytes'=>$asset['bytes'],'original_operations_sha256'=>$parentDigest];
    $upload=$store->prepare(AM_PREFIX.'upload-general-repair','landing_upload',$uploadRequest);am_ck(am_writable_state($upload),'menu_upload_already_attempted');
    am_invoke($pub,'beforeWrite',$upload,$guard);$active=$upload;
    $receipt=AppleService39ChannelTransport::uploadReceipt($transport->upload($assetFiles['general-repair']));
    am_ck($receipt!==null,'menu_upload_unknown');$store->finish($upload,'verified',$receipt);$active=null;
    $upload=$store->operation(AM_PREFIX.'upload-general-repair','landing_upload');
    am_ck($upload['state']==='verified'&&(int)$upload['write_attempts']===1&&is_string($upload['outcome']['token']??null),'menu_upload_receipt_invalid');
    $b=$before['menu'];$body=am_body($plans['menu'],$b['attachments'],$upload['outcome']['token']);
    $request=['revision'=>AM_REVISION,'revision_sha256'=>AQ_PACKAGE_SHA256,'target_post'=>'menu','cover_key'=>'general-repair','source_sha256'=>$asset['sha256'],
        'mid'=>$b['mid'],'url'=>$b['url'],'upload_operation_id'=>(int)$upload['id'],'upload_request_sha256'=>$upload['payload_sha256'],
        'upload_token_sha256'=>hash('sha256',$upload['outcome']['token']),'original_operations_sha256'=>$parentDigest,
        'previous_photo_id'=>$b['photo'],'preserved_buttons'=>$b['buttons'],'body'=>$body,'native_text'=>$b['plan']['native_text'],'native_markup'=>$b['plan']['native_markup']];
    $row=$store->prepare(AM_PREFIX.'menu','landing_edit',$request);am_ck(am_writable_state($row),'menu_edit_already_attempted');
    am_invoke($pub,'beforeWrite',$row,static function()use($guard,$observe,$b):void{$guard();am_ck(am_fingerprint($observe('menu'))===am_fingerprint($b),'native_preimage_changed');});
    $active=$row;$store->rememberMessage($row,$b['mid'],$b['url']);
    $ack=$transport->edit($b['mid'],$body);$acknowledged=AppleService39ChannelTransport::accepted($ack)&&($ack['data']['success']??null)===true;
    $row=$store->operation(AM_PREFIX.'menu','landing_edit');$after=$observe('menu',false);
    am_ck($after['photo']!==$b['photo']&&$after['plan']['native_text']===$b['plan']['native_text']
        &&AppleService39LandingRevision::sameMarkup($after['plan']['native_markup'],$b['plan']['native_markup'])
        &&am_canon($after['buttons'])===am_canon($b['buttons']),'menu_content_readback_failed');
    $nativeUrl=(string)($after['payload']['url']??'');$native=am_download($nativeUrl,'native');
    $comparison=am_compare('general-repair',$native,$sources);am_ck($comparison['accepted'],'menu_original_comparison_failed');
    $proof=['schema_version'=>1,'kind'=>'automatic_native_pixel_comparison','method'=>$comparison['method'],'project_key'=>'appleservice39',
        'channel_id'=>AM_CHANNEL,'target_post'=>'menu','message_id'=>$after['mid'],'public_url'=>$after['url'],'native_photo_id'=>$after['photo'],
        'source_sha256'=>$asset['sha256'],'native_preview_sha256'=>hash('sha256',$native),'native_payload_url_sha256'=>hash('sha256',$nativeUrl),
        'native_preview_bytes'=>strlen($native),'request_sha256'=>$row['payload_sha256'],'upload_operation_id'=>(int)$upload['id'],
        'upload_request_sha256'=>$upload['payload_sha256'],'upload_token_sha256'=>$request['upload_token_sha256'],'edit_operation_id'=>(int)$row['id'],
        'edit_started_ms'=>$row['started_ms'],'application_sha'=>AM_APP,'comparison'=>$comparison];
    $proofHash=am_digest($proof);
    am_emit(['record'=>'native_evidence','post_id'=>'menu','evidence_sha256'=>$proofHash,'evidence'=>$proof,'native_image_base64'=>base64_encode($native)]);
    if(defined('STDOUT'))fflush(STDOUT);
    $outcome=['readback'=>true,'image_comparison'=>$proof,'image_comparison_sha256'=>$proofHash,
        'text_markup_keyboard_mid_url_pin_preserved'=>true,'provider_acknowledged'=>$acknowledged,'reason'=>'verified_image_metadata_finalization_pending'];
    $store->finish($row,'unknown',$outcome);
    $store->rememberLandingImageReceipt($row,['kind'=>'reviewed_native_replacement','message_id'=>$after['mid'],'photo_id'=>$after['photo'],
        'source_sha256'=>$asset['sha256'],'upload_token_sha256'=>$request['upload_token_sha256'],'request_sha256'=>$row['payload_sha256'],
        'revision_sha256'=>AQ_PACKAGE_SHA256,'evidence_sha256'=>$proofHash,'native_preview_sha256'=>hash('sha256',$native)]);
    unset($outcome['reason']);$store->finish($row,'verified',$outcome);$active=null;$menuPhoto=$after['photo'];
    $guard();$unchanged=0;$verified=[];
    foreach($before as$id=>$b){
        $after=$observe($id,$id!=='menu');
        if(!isset($plans[$id])){am_ck(am_fingerprint($after)===am_fingerprint($b),'unrelated_post_changed');$unchanged++;}
        else{
            am_ck($after['plan']['native_text']===$plans[$id]['native_text']&&AppleService39LandingRevision::sameMarkup($after['plan']['native_markup'],$plans[$id]['native_markup'])
                &&am_canon($after['buttons'])===am_canon($b['buttons'])&&$after['photo']===($id==='menu'?$menuPhoto:$b['photo']),'final_target_changed');
            $verified[]=$id;
        }
        am_emit(['record'=>'native_post_audit','post_id'=>$id,'url'=>$after['url'],'photo_id'=>$after['photo'],
            'native_text_sha256'=>hash('sha256',$after['plan']['native_text']),'native_markup_sha256'=>am_digest($after['plan']['native_markup']),
            'cta_label'=>$after['buttons'][0][0]['text'],'cta_url'=>$after['buttons'][0][0]['url'],'cta_context_matches_post'=>true,'changed'=>isset($plans[$id])]);
    }
    $rows=$store->rows();$own=array_values(array_filter($rows,static fn($r)=>str_starts_with($r['post_key'],AM_PREFIX)));
    am_ck(count($rows)===362&&count($own)===5&&count(array_filter($own,static fn($r)=>!am_original_verified($r)))===0
        &&am_original_rows($rows)===$original&&$unchanged===65&&count($verified)===4,'audit_final_operations_changed');
    $store->assertBinding(am_invoke($pub,'preflight'));aq_runtime_context();
    am_emit(['ok'=>true,'state'=>'channel_audit_corrections_complete','posts_verified'=>69,'changed_posts'=>$verified,'unchanged_posts'=>65,
        'caption_edits'=>3,'approved_menu_image'=>true,'old_operations_preserved'=>357,'verified_operations'=>362,'provider_edits'=>4,'provider_uploads'=>1,
        'provider_creates'=>0,'single_cta_targets_mids_urls_pin_preserved'=>true,'exact_iphone17_runtime'=>true,'held'=>[],'automatic_retry'=>false]);
    foreach($assetFiles as$f)unlink($f);rmdir($tmp);exit;
}catch(Throwable$error){
    if($store instanceof AppleService39PilotPublicationStore&&is_array($active))try{am_hold($store,$active,am_reason($error));}catch(Throwable$ignored){}
    foreach($assetFiles as$f)if(is_file($f))@unlink($f);if(is_string($tmp)&&is_dir($tmp))@rmdir($tmp);
    am_emit(['ok'=>false,'state'=>'channel_audit_corrections_stopped','reason'=>am_reason($error),'error_class'=>get_class($error),'line'=>$error->getLine(),'automatic_retry'=>false]);exit(1);
}

