# AnyTour: дополнительный режим Satori

Этот пакет подключает выбранную композицию Satori + Sharp к существующему `../prepare-commercial-covers.py`. **Pillow остаётся режимом по умолчанию.** Satori включается только через `--renderer satori`; фотографии, названия и идентификаторы берутся из переданного `cover-input`, локация и короткая подпись — из отдельного редакционного JSON с заданным SHA256.

Исследовательская часть закрыта. Композиция и сохранённый набор из 11 карточек не меняются. Здесь переносится готовый runtime; новые варианты оформления не разрабатываются. [Комплект CONTENT](CONTENT_REVIEW.md) содержит три контрольных изображения и точные вопросы, оставшиеся перед коммерческим применением.

## Куда подключено

Канонический exact-input CLI находится в ветке `audit/content100-bot-publication-20261101`, база `26171ec67ada13822f140aa0ed6b870617263ee4`; в проверенном `main` этот файл отсутствует. Поэтому технический draft PR адресован этой CONTENT-ветке.

Автоматический `.github/workflows/commercial-hotel-cover-preview.yml` вызывает другой инструмент, `prepare-commercial-gallery-covers.py`. Этот PR не подключает Satori к той автоматике и не меняет workflow, публикации, фиды, расписания или сервер. Новые зависимости устанавливаются только там, где явно нужен локальный режим Satori.

## Установка зависимостей

Команды ниже выполняются из корня репозитория. Проверенная среда — Linux x64, Node 24.22.3 и Python 3.12 с Pillow 13.0.0. `.nvmrc`, `package-lock.json` и `requirements-test.txt` фиксируют проверенные версии. Satori 0.63.2 и Sharp 0.35.5 устанавливаются в отдельный вложенный пакет.

```bash
python3 -m pip install -r tools/direct_demand/operations/satori/requirements-test.txt
npm ci --prefix tools/direct_demand/operations/satori --no-fund --no-audit
```

Pillow использует имеющийся Liberation Sans Bold или переданный `--font`. Его прежние зависимости и правила не изменены. Satori использует включённые Open Sans Regular/Semibold/Bold с notice и полным Apache-2.0; логотип берётся из существующего `.github/traffic-publish/brand/anytour-logo-white.png` и проверяется по прежнему SHA. Open Sans остаётся временным шрифтом до решения CONTENT.

После установки подготовка полностью локальна: внешние изображения не скачиваются, провайдеры и API публикации не вызываются.

## Выбор режима

### Прежний Pillow

Существующая команда работает без новых аргументов. Для демонстрационного запуска на сохранённом реальном входе Royal Brayka:

```bash
python3 tools/direct_demand/operations/prepare-commercial-covers.py \
  --input tools/direct_demand/operations/satori/fixtures/4185-cover-input.json \
  --expected-input-sha256 a171254f4c94f60f07ee36d528853cdd039ceb6f1733e4704e5e3efe94cf4319 \
  --output-dir /tmp/anytour-pillow-4185
```

Явный `--renderer pillow` эквивалентен умолчанию. Node и редакционный JSON этому режиму не нужны.

### Явный Satori

Эта готовая команда сохраняет **прежнюю подпись пилота Royal**, не выбирает новую формулировку автоматически:

```bash
python3 tools/direct_demand/operations/prepare-commercial-covers.py \
  --renderer satori \
  --input tools/direct_demand/operations/satori/fixtures/4185-cover-input.json \
  --expected-input-sha256 a171254f4c94f60f07ee36d528853cdd039ceb6f1733e4704e5e3efe94cf4319 \
  --editorial-input tools/direct_demand/operations/satori/fixtures/4185-editorial.json \
  --expected-editorial-sha256 3ed4b5d298c1c777f871d74079c9d67b4d700523e5a6a14faab9ff88004034b1 \
  --output-dir /tmp/anytour-satori-4185
```

Для Satori требуется новый, ещё не существующий выходной каталог. Повторный запуск должен использовать другой путь: чужой или прежний результат не удаляется. Ошибка хеша, данных, переноса или рендера завершает команду без скрытого переключения на Pillow. `--font` относится только к Pillow.

Новый редакционный вариант Royal хранится отдельно: `fixtures/4185-editorial-candidate.json`, SHA256 `88baebdb0bc3b7309388641632faf3c661655689d49e1b7a88c3f3c3d2b02d86`. Его нужно выбрать явно вместо прежнего editorial-файла и использовать отдельный output-dir. Это кандидат для CONTENT, не утверждённая замена рабочей подписи.

## Результаты команды

Satori создаёт PNG/JPEG 1000×1000, JPEG 768×768, SVG, контрольные PNG 320/360, contact sheet и `cover-receipt.json`. Выход остаётся `PREPARED_AWAITING_VISUAL_ACCEPTANCE`, `publication_allowed:false`, `production_installed:false`.

Квитанция сохраняет исходные поля строки и весь editorial-объект, включая `sourceRefs`, версии, права, ограничения и holds. Проверяются точные байты источников и связи ролей `hero/detail_left/detail_right`; EXIF учитывается. Геометрия фотографий берётся из того же `planHotelLayout`, который используется рендером, поэтому перевод Pillow `crop_center` в focal сохраняет смысл смещённой обрезки.

Никакие тексты, признаки возраста, цены, условия или фотографии не выводятся автоматически. SHA подтверждает неизменность входа, а не фактологическую правильность или разрешение на публикацию. Для нового коммерческого входа остаётся обычная редакционная приёмка CONTENT. Готовые fixtures имеют статус контрольных примеров, не новой очереди публикации.

## Проверки

```bash
npm test --prefix tools/direct_demand/operations/satori
```

Используются три готовых случая: Royal Brayka, Al Sahel и Koh Yao Yai. Проверяются сохранность прежнего Pillow относительно точного Git-снимка, реальные Satori PNG, метаданные и SHA, асимметричная обрезка, запрет изменения слов подсказкой переноса и отказ перезаписывать существующий каталог. Ошибочные входы не должны создавать успешный результат.

Для проверки всех 11 сохранённых карточек распакуйте [готовый пакет](https://chatgpt.com/library?file_id=libfile_fde3_f-4a2b2ed6fb6c8191b705d761cb51da35_839482358a3c8191a38d8cf811d28df4&account_id=personal) и передайте корневую папку, содержащую `assets`, `fixtures` и `output`:

```bash
npm test --prefix tools/direct_demand/operations/satori -- \
  --pilot-root /absolute/path/to/anytour-satori-refined
```

Хеши всех 11 эталонов и исходного архива зафиксированы в `fixtures/manifest.json`; старые изображения не заменяются новыми golden. Для локальной проверки Pillow нужен исходный Git-коммит базы, уже присутствующий в обычном клоне этой ветки.

## Статус и остаток перед обычным выпуском

- Исследование завершено; техническое подключение подготовлено отдельным draft PR.
- Владелец остаётся [CONTENT / HOTEL #1566](https://github.com/pyatkoff/max-search-bot/issues/1566#issuecomment-6961029169). Другие claims и MAIN/DEPLOY publisher не занимаются.
- Композиция трёх образцов дополнительно просмотрена пользователем без обязательных исправлений. Открытые решения по шрифту, Royal и формулировке Koh Yao Yai перечислены в [CONTENT_REVIEW.md](CONTENT_REVIEW.md).
- В этой source-ветке существующие `regression` и `guard` автоматически запускаются только для PR в `main`. Отсутствие их запуска у carrier-base draft не означает PASS. Обязательные гейты обычного выпуска должны быть отдельно подтверждены на точном принимаемом head; workflow ради пилота не меняется.
- Локальное чтение точного базового коммита обнаружило 348 строк в 45 прежних файлах, которые не проходят существующий retired-domain guard. Новые файлы Satori таких ссылок не добавляют. Этот унаследованный остаток записан в [review/release-gates.json](review/release-gates.json); архивы не переписываются и guard не ослабляется. PHP-инвентарь локально не выполнен, поскольку PHP в окружении отсутствует.
- Целевая проверка подключения: [review/technical-checks.json](review/technical-checks.json), 15/15 сценариев и 11/11 сохранённых PNG. Это отдельное свидетельство для просмотра draft, не замена общим гейтам выпуска.
- Merge, установка, включение автоматического gallery-workflow и коммерческая публикация этим поручением не выполняются. Физический телефон и пережатие каналами ещё не проверены.
