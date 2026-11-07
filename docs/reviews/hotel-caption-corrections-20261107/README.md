# Три адресные поправки к существующим подписям

**Статус: PREPARED_NOT_EXECUTED_NOT_FRESH.** Пакет подготовлен для review6706115450: Sherwood1352, Gural1074 и Sphinx462, ровно шесть уже существующих сообщений. Это проверяемый вход для действующего механизма caption-only; подготовка не выполняла PHP, provider requests, DB queries/writes, workflow dispatch или публикацию. Исторические версии ниже не объявляются текущими.

Основание текстов: [принятый review6706115450](https://github.com/pyatkoff/max-search-bot/issues/1142#issuecomment-6706115450), редакция 2026-11-07T11:46:26Z. Все три полные новые подписи и SHA256 перенесены оттуда без переработки. Старые подписи и media SHA взяты из исходных manifest batch3/batch4; материалы и изображения заново не собирались.

## Публичные файлы пакета

Разрешено сохранить в изолированную review branch только следующие четыре файла:

- `caption-correction-input.json` — точные тексты, safe historical bindings, источники и ограничения.
- `update-reviewed-three-captions-template.php` — узкая адаптация существующего template.
- `prepare-caption-correction.py` — офлайн-подготовка приватного PHP из двух настоящих ZIP.
- `README.md` — источники, изменения и конкретная передача действующему owner.

Каталог `reference/`, оригинальные ZIP, raw receipts и собранный PHP **не включать в Git, комментарии или публичные артефакты**. Они содержат сохранённые button URLs. В публичном manifest находятся только их SHA256. Подготовщик создаёт новый файл с режимом 0600 в каталоге 0700, не перезаписывает существующий output и печатает только безопасные контрольные суммы.

Место review: `docs/reviews/hotel-caption-corrections-20261107/` на изолированной `review/hotel-captions-20261107-1352-1074-462` от main `a9b03a52bb4c4505640eae79541b7d48aa391083`. Сам этот пакет не создаёт PR, CI или workflow. Он не изменяет активную audit-ветку.

## Источники и подлинность квитанций

Исходный [принятый caption template](https://github.com/pyatkoff/max-search-bot/blob/c261486fdb8ff8f18a467ee51c228e29bcf6c5bd/tools/direct_demand/operations/update-existing-three-captions-20261107-template.php):

- Git blob: `f0015367abf054af59196d2234e015991716f018`.
- SHA256 исходных байтов: `ef6636881b3969da8041f07f69017bbaf54261224c13692d5ac9e316760deb32`.
- Его старая операция и цели Rubi/Rox/SideStar уже закрыты. Старый template не запускать.
- Неизменённая локальная копия для сравнения: `reference/original-caption-template.php`. Она не входит в четыре публичных файла.

| Источник | Batch3 | Batch4 |
|---|---|---|
| Manifest commit | `cfd1acb33f19f9e9fa7a5ee45910653e6bf091bd` | `88036ab7fdb97e3f6c9713bea14c66bac3a87fa5` |
| Manifest Git blob | `48fe7174863fc73a61c58a2495bfb02be79045da` | `68104c34600ac60e62672992e8383d2131497553` |
| Sender run | [42366571943](https://github.com/pyatkoff/max-search-bot/actions/runs/42366571943) | [42368588925](https://github.com/pyatkoff/max-search-bot/actions/runs/42368588925) |
| Job | 128385778924 | 128391812460 |
| Artifact ID | 13572360372 | 13572078493 |
| Artifact name | `commercial-batch-20261107-3-publication` | `commercial-batch-20261107-4-publication` |
| Подлинные исходные counts | 12 posts / 12 attempts / 0 reused | 18 posts / 18 attempts / 0 reused |
| ZIP SHA256 | `6a1e7da61da2f55b94dff4d1ae6a67d18b1154a9f80298c2c0e9e1823d52f9f0` | `3235c02be5bb2b960bdecb5f6867a3ca2d59eaf1ba605d693115583a42044178` |
| `channel-preflight.json` SHA256 | `16ef835c505b6e2c225374a50526eb8ecb72213cbe0e1e0de411b5a3b593fde1` | `e81e69c817ca3469766ab613603fcb255c184b1df0563b6cee4b54586bf7f450` |

Manifest paths: `tools/direct_demand/operations/commercial-batch-20261107-3-manifest.json` и `tools/direct_demand/operations/commercial-batch-20261107-4-manifest.json` в указанных immutable commits.

Оба ZIP получены из настоящих GitHub artifacts; SHA256 ZIP и единственного NDJSON-файла проверены. Подготовщик читает последнюю JSON-строку каждого файла, сохраняет оба полных исходных result без изменения 12/18 counts и проверяет полный набор исходных hotel/platform identities без дубликатов. Из этих двух квитанций отдельно выводятся шесть целевых proofs. Объединённой «оригинальной квитанции на шесть публикаций» здесь нет.

## Точные исторические bindings

| Отель | Платформа | Publication ID | Version ID | Message ID | Пост |
|---|---|---:|---:|---|---|
| Sherwood1352 | Telegram | 467 | 943 | `183` | [Telegram183](https://t.me/any_toour/183) |
| Sherwood1352 | MAX | 473 | 949 | `mid.ffffb6caf0606fed01a1b5ae99d31052` | [MAX](https://max.ru/channel_anytour/AaG1rpnTEFI) |
| Gural1074 | Telegram | 479 | 955 | `189` | [Telegram189](https://t.me/any_toour/189) |
| Gural1074 | MAX | 488 | 964 | `mid.ffffb6caf0606fed01a1b5c8d7e82dab` | [MAX](https://max.ru/channel_anytour/AaG1yNfoLas) |
| Sphinx462 | Telegram | 485 | 961 | `195` | [Telegram195](https://t.me/any_toour/195) |
| Sphinx462 | MAX | 494 | 970 | `mid.ffffb6caf0606fed01a1b5c90d9725bf` | [MAX](https://max.ru/channel_anytour/AaG1yQ2XJb8) |

Полные old/new text SHA256, photo SHA256, channel/source identities и SHA256 исторической кнопки находятся в manifest. Недостающих полей для этой исторической привязки не обнаружено. Свежая canonical версия, текущий private operation state и свобода owner этим не доказаны.

## Что изменено в template

Новая единственная operation identity: `commercial-hotel-caption-review6706115450-20261107-1-v1`.

Изменены только точный operation ID, три hotel IDs, шесть publication/version IDs и active/UNKNOWN query для этих publication IDs; однопартийный proof block заменён на проверку двух настоящих исходных квитанций и отдельный выбор ровно шести целей. Добавлен явный **no-replay**: если каталог новой операции уже существует, выполнение прекращается до provider/configuration writes независимо от наличия, полноты или confirmed-статуса прежнего receipt. Историю не удалять, не затирать и не возобновлять.

Остальной transport, установленный source SHA, 21 принятый publishing blob, схема, channel identities/config_version, порядок полного preflight и edit loop сохранены. Это не generic edit-интерфейс и не второй publisher.

До первой provider mutation template:

1. Проверяет установленный SHA `a9b03a52bb4c4505640eae79541b7d48aa391083`, принятые source blobs, manifest bytes, caption SHA/UTF16-size и полный исходный proof scope.
2. Проверяет отсутствие claimed/write_started/unknown, claim_token и claimed_at для всех шести publication IDs, принятую схему и новую private operation directory; ведёт прежний durable journal.
3. Разрешает обе channel identities/права и **все шесть** canonical publications: точные platform/bot/channel/message/source, sent state, историческую expected version, старый текст, URL кнопки и поста, стабильную media identity.
4. Для всех трёх MAX сообщений делает provider GET и сравнивает его с canonical snapshot до входа в edit loop. Для Telegram используются canonical snapshot и последующая проверка тела ответа editMessageCaption; отдельный Telegram GET шаблон не обещает.

Перед каждым edit ещё раз проверяются version/state/channel/token. Durable intent сохраняется до вызова, ответ — сразу после. Telegram сохраняет existing keyboard и проверяет response identity/body/photo/button; MAX PUT меняет text/format без attachments и затем выполняет GET/readback. Проверки final registry, неизменного start token и его разрешения на новую версию сохранены. Неполный/неопределённый исход останавливает дальнейшие записи.

## Офлайн-подготовка

Нужны Python3, два указанных оригинальных ZIP и четыре review-файла. Подготовщик не импортирует network/subprocess/DB/provider clients, не выполняет PHP и не обращается к рабочему checkout.

Пример команды подготовки; оба ZIP и output должны оставаться в приватном рабочем каталоге вне Git:

```sh
python prepare-caption-correction.py \
  --batch3-zip /private/caption-review/batch3-original.zip \
  --batch4-zip /private/caption-review/batch4-original.zip \
  --output /private/caption-review/output/caption-correction.php
```

Родительский каталог output создаётся с 0700, если отсутствует; уже существующий каталог должен принадлежать текущему пользователю и иметь 0700. Шаблон и public manifest закреплены SHA256 внутри подготовщика. Изменённые ZIP/manifest/template или неполная привязка дают BLOCKED до создания output. Собранный PHP содержит base64 payload с реальными кнопками; печатать или коммитить его нельзя.

## Оставшийся конкретный шаг: действующий owner

Операции принадлежат существующему SOURCE1142/940. Последний наблюдённый audit head — `61bba24c12d5f9e65dfbed1f2307da4020dad4a1`; run42382925355 в 14:43:29UTC был in_progress. Это историческое наблюдение, а не утверждение о текущей активности. Checkpoint owner6704398648 с CLOSED/UNKNOWN0 относится к ранее закрытому выпуску и не даёт новую lease.

Поддерживаемый исполнительный путь остаётся тем же: `.github/workflows/content100-bot-publication-20261101.yml` в `audit/content100-bot-publication-20261101`, push по этому пути, общий `concurrency.group: anytour-authorized-channel-publication`, `cancel-in-progress: false`. Пакет не меняет этот workflow и не предоставляет dispatcher.

Передача owner должна содержать immutable review commit и ровно эту operation identity. После завершения своей активной операции owner проверяет актуальные active/UNKNOWN/journal state, наличие обязательных входов и отсутствие прежней новой операции. Затем принимает точный пакет в существующий путь с сохранением concurrency/credentials/guards, выполняет обязательный `php -l` подготовленного PHP и запускает единственное согласованное изменение. Полный свежий preflight всех шести целей встроен в этот template, поэтому отдельный fresh DB-reader workflow не нужен для подготовки.

Если версия/текст/фото/кнопка уже изменились, сообщения уже исправлены, операция существует или есть неопределённый исход, выполнение останавливается. Нельзя обновлять expected versions наугад, удалять journal, повторять sender или создавать другую operation identity для обхода STOP.

Закрытие требует настоящего result: шесть подтверждённых edits существующих message IDs, правильные новые text SHA, сохранённые media/button/token, 0 новых сообщений, 0 tour-search/price queries, подтверждённые final registry/provider readbacks. Подготовленный пакет и успешная компиляция такой квитанцией не являются.

## Выполненная локальная проверка

- Подлинность двух ZIP и NDJSON members, полные 12/18 scopes и шесть выбранных bindings проверены.
- Все old/new caption hashes и ограничение UTF16 проверены pure prepare на настоящих ZIP.
- Pure prepare успешно создал приватный PHP с шестью целями; PHP не выполнялся.
- ROOT отдельно сравнил все21принятый publishing Git blob из исходного template с точным maina9b03a52:21/21совпадают. Это source-проверка, не наблюдение production runtime.
- Независимый reviewer подтвердил штатную офлайн-сборку и5адресных отказов; публичные файлы не содержат полных button URLs из30исходных сообщений. Проверка кода не заменяет native PHP lint и свежий preflight.
- **PHP syntax check: SKIP — PHP в среде подготовки отсутствует.** Native `php -l` обязателен у существующего owner до исполнения.
- Fresh DB/provider preflight: NOT RUN. Свободная операция: NOT CONFIRMED. Изменения сообщений: NOT RUN.

SHA256 public manifest: `605999f63f3c4600a7c880ab1897169344985938717f03e6e989d3528356db07`.
SHA256 адаптированного template: `ce72f8f1e1731134552da61171c9573c1871db33ba0aee4c85cf39334c5ba70d`.
SHA256 приватного подготовленного payload: `bc42da11b7f4d806b4e7e2cff33ccbabf30333881df5c49581f7b59d8597b4e1`.
SHA256 приватного подготовленного PHP: `11261737e86a9a2edc25fdc0c7f37fc0ce108e1719a93684537bbc3b97145bbc`.

