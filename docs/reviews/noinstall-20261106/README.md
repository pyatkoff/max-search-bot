# Замороженный материал для единственного no-install пакета

**Это материал независимого review, не готовый кандидат на merge, не второй прототип и не новый PR.**

Действующая исходная работа принадлежит [SOURCE6677719548](https://github.com/pyatkoff/max-search-bot/issues/1122#issuecomment-6677719548), созданной 06.11.2026 в 05:28 UTC. Параллельная локальная подготовка текущего диалога началась по устаревшему снимку координации, её отметка появилась в 05:33. Пересечение обнаружено при свежем чтении; работа заморожена, собственные SOURCE/MAIN/DEPLOY/publisher не удерживаются. Семь путей активного [PR1364](https://github.com/pyatkoff/max-search-bot/pull/1364) не редактировались.

## Что сохранено

- Полный патч 28 файлов относительно `fc9d16f414378f2cb9ccd7435de2cb059f99b88f`.
- Существующие V2 classifier/preview и две исходные регрессии перенесены из прежнего кандидата без расширения allowlist.
- Подготовлены общий R/A adapter, read-only runtime snapshot, обе ветви Deploy, семь downstream consumers, независимый editor admission и существующий closer.
- Снимок не перебазирован на чужие новые релизы. Он должен использоваться только как материал для сопоставления с текущим пакетом его владельца.
- SHA256 патча и точное результирующее Git tree приведены в `manifest.json`. Проверена применимость патча к указанной базе отдельным временным Git index; это проверка целостности передачи, не подтверждение поведения.

Нельзя переносить патч целиком на main или принимать исторические локальные PASS за приёмку конечной версии. В конце работы осталась непроверенная общая правка manual/recovery resolver, а её fixtures ещё не адаптированы.

## Найденные границы, которые должен закрыть один действующий пакет

| Ситуация | Необходимый результат | Состояние замороженного материала |
| --- | --- | --- |
| Ручная полная установка R после принятого R→A | Более новая установка меняет фактическую A на R; старая квитанция не продолжает выдавать прежнюю A. Pending/failed/unknown новый Deploy не игнорируется. | Начат common fallback на exact installed decision, реальные jobs и schema-2 telemetry; финальная правка не проверена. |
| Полная установка push без publisher binding | Законный closer `not_opted_in` не должен навсегда отключать расписания. Подтверждение версии для потребителя не становится правом пропуска установки или закрытия publisher. | Входит в непроверенный fallback. |
| Разрешённый recovery настоящей установки, attempt 2 | Пока он активен, consumers получают явный pending. Повтор no-install не получает этих полномочий. | Начат узкий common admission, fixtures не обновлены. |
| Долго неизменный main | Пятиминутный monitor не переполняет окно обнаружения Deploy. Смотреть ограниченные списки конкретных release workflows, а не все Actions одного SHA. | Common переведён на workflow-specific lists; финальная правка не проверена. |
| Main изменился во время поиска baseline/V2/SSH | Перед возвратом ЛЮБОГО разрешающего решения снова проверить current main, включая ordinary fallback. | Finding остаётся неисправленным: свежая проверка пока есть только для unchanged return. |
| Во время runtime capture начался новый Deploy того же R | После capture снова прочитать Actions admission; main/PR/publisher без изменения недостаточно. | Closer исправлен; отдельный сценарий подтверждает отсутствие PATCH/receipt и один capture. |
| Accepted artifact истёк или удалён | Теряется право на оптимизацию. Нельзя молча подставлять R вместо потерянной A или бессрочно отключать наблюдение без описанного восстановления. | Остаётся открытой границей проекта; durable fallback не реализован. |
| Artifact загружен, но producer closer затем failed/cancelled | Его нельзя принимать за baseline. Известный непринятый producer не должен сам по себе навсегда блокировать законную обычную установку. | Finding остаётся неисправленным в `findAccepted`. |
| Public/editor package переиспользуется несколькими backend релизами | Сохранённый source компонента может отличаться от A; проверяются seal/package/backend compatibility, не ложное равенство всех SHA. | Snapshot допускает штатную прежнюю provenance. |

Эти случаи являются приёмкой одного пакета, а не отдельными задачами/очередями/релизами.

## Граница восстановления после истечения artifact

Возможно повторно доказать целевую установленную версию через существующие run/jobs и schema-2 telemetry, используя запись лишь как указатель на проверяемый исходный install. Но это пока предложение, не реализованный контракт.

До включения нужно явно покрыть R=A и R≠A, recovery исходной установки, более поздние pending/failed/manual Deploy, полный A..R и реальный server HEAD. Нельзя реконструировать `forced=false` и выдавать его за подлинный исходный push. Для совместимости используются чистые функции V2 сравнения состава и намерений.

Общий resolver используется не только наблюдателями: manual selection-host и независимый editor пишут на сервер. Поэтому название «observer fallback» само по себе не ограничивает полномочия. Потребители восстановления должны быть ограничены, либо существующие PR/CI, compatibility, CAS и protected operation proofs должны отдельно подтверждать допустимость записи. Fallback не становится accepted baseline и не закрывает publisher.

## Последние фактические проверки

| Поднабор | Фактический результат | Ограничение |
| --- | --- | --- |
| Common artifact/V2 adapter до последней manual/recovery правки | 36 assertions PASS; настоящая временная Git history, настоящий V2, strict ZIP/JSON reader с локальным API double | Это не PASS конечного замороженного common source. |
| Closer после исправления same-R race | 553 assertions PASS, Node syntax PASS | Локальная функциональная проверка с doubles; не GitHub/production release. |
| Editor admission | 30 tests: 27 выполнены успешно, 3 PHP tests skipped | PHP в окружении отсутствует. |
| Изменённые consumer/source/monitor checks | 488 assertions PASS | Отдельный focused вызов; не полный интегрированный suite. |
| Consumer workflow syntax | 7 YAML, 46 Bash blocks PASS | Не запуск Actions. |
| Deploy wiring | YAML, 22 Bash blocks, 36 исполняемых комбинаций двух реальных aggregates PASS | Native PHP contract tests не запускались. |
| Production-gate полный script | Изменённые проверки пройдены; прежний cron fixture остановился на недоступном `/proc/<parent>/cmdline` | Та же ошибка воспроизведена на исходной HEAD-функции; контракт не ослаблялся. |
| Существующий V2 | Восстановлены прежние 30+26 cases | Исторические результаты, не новый запуск в этом проходе. |

PHP lint, PHP 8.2/8.3, native MySQL/browser и конечный required CI пакета не выполнены. Новые production snapshot/SSH capture не запускались. Приложение, настройки и клиентские отправки не менялись.

## Подтверждённая форма реальных Actions и компонентов

При read-only review использованы [Deploy42166383153](https://github.com/pyatkoff/max-search-bot/actions/runs/42166383153), [selection-host42166873695](https://github.com/pyatkoff/max-search-bot/actions/runs/42166873695), [activation42166946044](https://github.com/pyatkoff/max-search-bot/actions/runs/42166946044) и [closer42166873618](https://github.com/pyatkoff/max-search-bot/actions/runs/42166873618).

Реальный Deploy содержит шесть jobs; пропущенные configure/activate/independent-editor возвращают пустые steps; checkout cleanup называется `Post Checkout …`; artifact metadata содержит digest и repository IDs. Public/editor packages на backend fc9d16f4 сохраняют source446531f0 при changed:false/reused:true. Это нормальное переиспользование, не drift. Полный новый PHP snapshot на production пока не подтверждён.

## Один следующий шаг

Действующий владелец SOURCE6677719548 сопоставляет этот материал со своей реализацией, закрывает перечисленные поведенческие границы и выполняет одну согласованную итоговую интеграцию. Семь файлов1364 остаются у его автора до освобождения SOURCE; соответствующие shared workflow assertions меняются только после этого.

Новых PR/CI/merge/deploy, резервирования выпуска, worker или расписания этот handoff не создаёт. Первый обычный выпуск законченной интеграции должен создать accepted baseline естественным образом; искусственный test-only production выпуск ради демонстрации не нужен. Реальную экономию оценивать по последующим обычным журналам с учётом частоты применимости и собственной стоимости admission.
