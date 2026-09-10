# max-search-bot — current autonomous handoff

Repository: `pyatkoff/max-search-bot`

Roadmap/checkpoint thread: GitHub issue [#55](https://github.com/pyatkoff/max-search-bot/issues/55)

Primary language with the user: Russian.

Read this file first. Then read `AGENTS.md` and only the durable documents relevant to the selected slice.

## Authority and evidence ownership

Do not trust a SHA, branch name or unfinished task copied from an old chat/export. Facts and authority have different owners:

- explicit current user instructions define authorized scope; production safety remains the stop condition;
- `AGENTS.md` and `docs/PRODUCT.md` own stable scope and product policy;
- `docs/ARCHITECTURE.md` and `docs/ARCHITECTURE_MAP.md` own dependency and code-ownership direction;
- current GitHub `main`, production/deploy diagnostics and fresh live customer/manager evidence own current factual state;
- newest comments in issue #55 own completed checkpoints and verification evidence;
- this file owns the current cross-document handoff sequence;
- historical handoffs and superseded roadmap sequences are context only.

Live evidence can confirm and reprioritize a defect, but it cannot authorize a hard-scope or product-policy change. When current facts make documentation stale, update the documentation in a docs-only PR; do not restart completed phases.

## First actions in every new session

1. Fetch current `main`, issue #55 and its newest comments.
2. Read `AGENTS.md`, `docs/AUTOPILOT.md`, `docs/OPERATIONS.md`, `docs/ARCHITECTURE.md`, `docs/ARCHITECTURE_MAP.md` and the relevant roadmap section.
3. Confirm `main`, deploy status, ops status and production snapshot resolve to the same full SHA.
4. Confirm migrations have zero pending/checksum failures.
5. Confirm required production workflows: deploy, diagnostics, strict MAX TLS, MAX webhook and Telegram webhook.
6. Review fresh live evidence and operational warnings before selecting roadmap work.
7. Create a fresh branch from current `main`; never revive a historical branch merely because an old handoff names it.

## Confirmed completed position

- Phase C Manager technical structure is complete for the current caller inventory through PRs #656–#659. Do not reopen it without a new caller or confirmed defect.
- The month/date/show-tours InteractionGuard work from the old handoff is merged and production-proven. Do not continue the historical `refactor/month-change-interaction-guard` branch.
- P0 public AI-log exposure was contained in PR #660:
  - raw AI debug/error writers are outside the document root;
  - runtime directory/file modes are `0700/0600`;
  - legacy AI-log paths are publicly inaccessible with an accepted 403/404 response; the #660 deployment returned 404;
  - this containment must never be removed by rollback.
- P0 public MAX webhook-log exposure was contained in PR #715:
  - raw MAX request bodies are no longer persisted by the webhook handler;
  - compatibility webhook input/output events use a bounded logger outside the document root with directory/file modes `0700/0600` and a 1 MiB cap;
  - deploy removes legacy `tmp_in.txt` / `tmp_out.txt` and requires both public paths to return 403/404; the #715 deployment returned 404 for both;
  - authorized diagnostics and delivery inspection use only the external log path;
  - no rollback may restore raw request-body logging or either public legacy file.
- The read-only MAX event idempotency inventory shipped production-green in PR #717:
  - `message_callback`, `message_created` and `bot_started` key ownership, claim ordering, local-store bounds and generated-surface secondary guards are executable contracts;
  - the inventory explicitly records fail-open missing IDs/storage, local `/tmp` durability, multi-host, crash-after-claim, unchecked write/flush and non-atomic conversation-mirror gaps;
  - it authorizes no runtime change or migration; aggregate repeated-callback flags alone are not a confirmed defect;
  - do not add a durable ledger, change claim timing or alter manager-request paths without separate narrow authorization plus evidence and rollback criteria.
- Phase D is complete through the selected low-risk slices: D1–D6, adults callback, stars callback and the observe-only nights → date transition shipped in PRs #663–#671. The meal callback contract and update-only runtime slice shipped in PRs #680 and #681. Do not restart those slices.
- The coupled children/child-age inventory shipped in PR #683. It confirms one comma-space age-status value and keeps free-text age migration blocked until an exact array-to-storage projection is executable.
- The `child_*` callback update-only runtime slice shipped in PR #685. It preserves the existing child-age value for `child_0`, fails closed when the existing child step is missing and keeps free-text child ages out of scope. Do not repeat that runtime slice.
- The executable child-age parser/storage contract shipped production-green in PR #687. It preserves the current separator behavior and exact comma-space storage projection without wiring either contract into runtime.
- The free-text child-age update-only runtime slice shipped in PR #689. It preserves the PR #687 parser/storage contract, fails closed for missing or pre-start age rows and keeps `NeedValueResolver`, AI completion and other fields unchanged. Do not repeat it.
- The read-only departure-city contract inventory shipped production-green in PR #691. It records callback, wizard free-text, explicit-departure and AI/default paths; exact directory-ID and no-flight `99` semantics; edit/back behavior; downstream `UF_CITY` → `from` projection; and the current unchecked wizard write result. It changed no runtime behavior. Do not repeat it.
- The side-effect-free `DepartureCityValueContract` shipped production-green in PR #693. The guarded `pick_city_<id>` callback-only update slice shipped production-green in PR #695 with exact decimal-string storage, update-only missing/pre-start rejection, no-flight `99`, edit/back and stale/duplicate coverage. Do not repeat either slice.
- The departure-city free-text update-only slice shipped production-green in PR #697. It preserves primary-directory-before-resolver lookup order, exact decimal-string IDs including no-flight `99`, unknown-city validation, rich-request AI routing, normal/edit progression and missing/pre-start fail-closed behavior. Departure-city callback and wizard free-text migration are complete; do not repeat them.
- The read-only country-flow contract inventory shipped production-green in PR #699. It records popular/manual callbacks, exact active-directory free-text lookup, current unchecked write/missing-step behavior, AI aliases/parser/hints/default owners, edit/back behavior and the `UF_COUNTRY` → search `country` projection. It changed no runtime behavior; do not repeat it.
- The side-effect-free `CountryValueContract` shipped production-green in PR #701. It accepts only positive canonical integer or decimal-string directory IDs, parses only `pick_country_<positive canonical id>` and remains disconnected from every runtime caller. Do not repeat the value-contract slice.
- The guarded country callback-only update slice shipped production-green in PR #703. It preserves exact country IDs, `country_selected`, manual/back/edit/adults behavior and fails closed for invalid, missing/pre-start, stale and duplicate callbacks. Do not repeat it.
- The country free-text update-only slice shipped production-green in PR #705. It preserves exact active-directory lookup, short validation, rich AI routing and normal/edit progression while failing closed for invalid, missing and pre-start country steps. Country callback and wizard free text now share `CountryValueContract`; do not repeat either runtime slice.
- The read-only date-flow contract inventory shipped production-green in PR #707. It records the distinct date-selection and month-navigation guards, direct wizard writes, AI upsert/future validation, pending-month context, edit/back/calendar behavior, exact `DD.MM.YYYY` storage and downstream claim/search/summary projections. It changed no runtime behavior; do not repeat it.
- The side-effect-free `DateValueContract` shipped production-green in PR #709. It preserves exact calendar-valid `DD.MM.YYYY`, parses only `pick_date_DD.MM.YYYY` and does not own today/future policy. The guarded date-selection callback and wizard free-text update-only slices shipped production-green in PRs #711 and #713 with malformed, missing/pre-start, stale/duplicate, pending-month and edit coverage. Do not repeat any of these slices.
- Phase E's read-only handoff inventory and the first two proven consolidations shipped in PRs #672, #675 and #676. They centralized queue-decision application and contact send/status application without changing handoff policy.
- The Manager notification activation incident reported by Anna was repaired in PRs #673, #674 and #677. PR #678 added redacted reason counts, and Anna's healthy green state was naturally confirmed after deployment.
- The exact current SHA and run IDs belong in live GitHub/diagnostics and the latest issue #55 checkpoint, not hard-coded here.

Known operational signals are evidence to investigate, not permission to change policy:

- older overdue/stuck assigned leads may remain while `routing_blocked_count=0`;
- a working manager may lack a usable browser push subscription;
- a historical Telegram 500 may remain visible while current pending updates and controller smoke are healthy.

Do not infer a routing, shift, lead-delivery or webhook defect from those aggregate signals alone.

## Current execution point

Private read-only dialogue review shipped and was authenticated on real evidence
in PR #719. Follow `docs/PRIVATE_DIALOGUE_DIAGNOSTICS.md` for bounded encrypted
message tails, owner-held key custody and exact-SHA/freshness verification. Public
redaction and production data remain unchanged. Do not repeat this completed
diagnostics slice. Its original one-PR manager-response exception was limited to
#719; the owner's later independent direction is recorded below.

The first fresh text review is recorded in #55. The standalone explicit-date
separator-spacing defect was repaired production-green in PR #720 with unchanged
calendar/year/range policy and AI/wizard coverage. Do not repeat that slice.

The `tours_checked` phone-return state-boundary repair shipped and was production
verified in PR #721. A successfully rendered return moves phone input to normal AI
text handling; failed delivery keeps phone state. Saved trip/phone values and the
protected handoff/search contracts are preserved. Do not repeat this slice.

The explicit post-tour link-help response shipped and was production verified in
PR #722 under the owner's operational-backlog exception. After results, the
statusCheck text path previously ignored "Ссылка не работает". The controller now
handles bounded opening complaints in self-service check/AI state with an existing
claim through `PostTourService` and `DialogueView`. It sends guidance with the
existing manager/edit callbacks; it does not generate/resend URLs, create claims,
call AI, change state, delete messages or request a manager automatically. Phone
input and upstream manager ownership are untouched. Required executable regression
covers MAX/Telegram, state/phrase boundaries and failed delivery. Natural post-deploy
help confirmation is still pending; production verification is not live confirmation.
Do not repeat this slice.

On 2026-09-06 the owner explicitly instructed "Забей на это. Продолжай работать
дальше" in response to the unanswered-manager backlog (authority recorded in #55).
Delayed human replies / accepted-without-reply backlog alone no longer stop
independent development. Keep `manager_response_ok` and counts truthful: this is
an operational warning, not a green response gate. Do not repeatedly notify or
pause solely for the known backlog's age/count. This exception does not cover a
confirmed delivery failure, handoff-integrity regression, security exposure or any
technical CI/deploy/provenance/migration/TLS/webhook/diagnostic-publication failure.
It authorizes no shift, routing, lead-delivery, phone-policy or business-data change.
The already-known no-subscription baseline remains a separate documented fact, not
permission to ignore new push failures.

The numeric-date suffix corruption was repaired and production verified in PR #724.
A full numeric interval could be misread as a shorthand day range beginning at its
month/year suffix, inventing an unrelated midpoint. `DateParser` now rejects that
partial match and retains the existing first-literal-date fallback. Valid shorthand
midpoints/endpoints and downstream search-window policy are unchanged. This does not
implement arbitrary full-endpoint or cross-month ranges. Do not repeat this slice.

The silent statusCheck text path was repaired and production verified in PR #725
after fresh evidence of unanswered country-correction text. Nonblank check-state
text receives neutral guidance and the existing `edit_params` button, explicitly
saying the parameters have not changed. Specific post-tour link help still takes
priority. This adds no automatic AI interpretation, trip write, state/generation
change, message deletion, URL/claim creation or manager request. Phone/wizard and
upstream manager ownership remain unchanged. Do not repeat this slice.

The owner-reported Manager Workspace mobile reply visibility defect shipped and
was production verified in PR #727. Mobile workspace/zones no longer impose a
large-viewport minimum height over the dynamic visible height. Existing reply
ownership, sending, safe-area and fallback behavior are preserved. Executable
tests cover expanded browser controls and reduced visible heights; those tests
are not real-iPhone keyboard or natural manager confirmation. Do not repeat it.

The literal bot bold-tag display defect visible on the same owner screenshot
shipped and was production verified in PR #728. The transcript renderer recognizes
only exact attribute-free `<b>plain text</b>` spans for explicit AI messages and
builds text/strong nodes without parsing message HTML. Customer, manager and
unknown-sender text stays literal; unknown markup and entities remain literal.
Stored messages, send behavior and attachments are unchanged. Do not expand this
into a general rich-HTML renderer without separately proven need and safeguards.

Natural manager/device confirmation of #727/#728 remains pending. Refreshing the
workspace loads the versioned assets, but deployment and automated tests alone do
not prove that the reporting manager can reply on her device or that a customer
received a reply. Keep the actual response/push indicators and technical gates
truthful; these display repairs do not resolve or waive notification failures.

The same-manager session-recovery composer repair shipped and was production
verified in PR #730. After successful reauthentication, the selected conversation
is refreshed through protected detail requests before reply controls return.
Fresh ownership/status/suspension remain authoritative; expired responses and
newer navigation are guarded. Same-account recovery preserves unsent text and the
selected attachment, while an account change clears the prior context. No send or
lifecycle action is replayed. Do not repeat this repair.

The owner-authorized reload continuity improvement shipped and was production
verified in PR #731. Unsent reply text and selected conversation survive reload
within account-scoped tab sessionStorage, after fresh authentication and authorized
matching detail. Storage is bounded to 24 hours and up to 20 complete drafts, each
up to 20,000 characters; larger text remains in memory only, without truncation. Credentials,
transcript history and attachments are not persisted. Account changes, loss of
reply ownership, a 403/404 while restoring detail, successful sends and explicit clearing
remove the relevant saved text. Late responses cannot erase a newer/other-account
draft; mobile Back/Forward and repeated reload preserve coherent navigation.
Do not extend this into cross-device/server draft synchronization without a new
authorized product requirement.

The authenticated desktop browser check in #55 confirmed visible reply controls,
restoration of the same conversation and exact synthetic unsent text after reload,
and an empty field after clearing and reloading again. No customer message or
attachment was sent. This is controlled browser verification, not natural iPhone
keyboard/session-expiry confirmation or proof of customer delivery. Do not repeat
#730/#731 or turn the remaining device confirmation into a speculative runtime fix.
Continue the authorized workspace/dialogue review from fresh evidence; select the
next independent repair only after another exact failing scenario is confirmed.

The open-transcript auto-refresh repair shipped and was production verified in
PR #733. The existing 15-second Inbox/focus/visibility cycle now refreshes the
visible conversation through protected detail, preserving unsent reply text,
selected attachment, lead/task/outcome editor DOM and history-reading position.
Unchanged messages retain their DOM. Hidden tabs and hidden mobile conversation
panes are not polled because the detail endpoint retains its existing mark-read
behavior. Navigation, authentication and send/lifecycle boundaries reject stale
responses; fresh access loss locks reply controls. No transport, handoff or
business-data behavior changed. Canonical regression and controlled browser
verification are distinct from natural customer-arrival/iPhone confirmation.
Do not repeat this repair; continue from a newly confirmed independent scenario.

The upload-result context repair shipped and was production verified in PR #734.
Late upload responses are pinned to their original file, conversation and
authenticated session generation. They cannot clear a newer selected attachment
or reopen sign-in after successful reauthentication. The post-send conversation
refresh preserves a replacement file; fresh ownership loss removes unavailable
reply attachments. Existing upload transport, multipart fields, retries and
lifecycle behavior remain unchanged, and no operation is replayed. Ten synthetic
upload/context cases and the existing conversation/session suites cover these
boundaries; natural slow-upload/session-expiry confirmation remains separate.
Do not repeat this repair or turn it into a transport redesign.

The Inbox AI-preview formatting repair shipped and was production verified in
PR #736. Conversation lists now project the exact last-message sender type. The
preview removes only exact attribute-free `<b>plain text</b>` spans from explicit
AI messages before applying the existing escaping; customer, manager, unknown
sender and malformed/unsafe markup remain literal text. Stored messages, transcript
rendering, transport and lifecycle behavior are unchanged. Controlled authenticated
browser verification found three formatted AI fallback previews without literal
bold tags after reload. Do not repeat this repair or expand it into general preview
HTML rendering without a separately proven need and safeguards.

The confirmed child-age whitespace defect shipped and was production verified in
PR #738. Authenticated original message text proved that the two-child wizard
rejected `10  14` with two ASCII spaces. `ChildAgeValueContract` now treats repeated
internal ASCII spaces between digits in comma-free input as one separator, without
inventing a zero-age child. Comma/mixed-input behavior, other separators, age bounds,
child count, exact comma-space storage, update-only missing/pre-start rejection and
normal/edit progression remain unchanged. This is a narrow evidence-backed exception
to the historical parser parity contract, not a new child-age migration. It adds no
birth-year/range interpretation or downstream search change. Required parser and
application regressions and exact production technical gates passed; natural
post-deploy input confirmation remains pending. Do not repeat this repair or expand
separator/age interpretation without another confirmed scenario and scope review.

The queued post-tour reminder ownership repair shipped and was production verified
in PR #740. Fresh private chronology showed an AI handoff offer after a manager had
already replied. The cron now rechecks the existing read-only
`ConversationControlService::shouldRouteToAi` policy immediately before reminder
telemetry/delivery and discards due reminders in `manager`/`waiting_manager`, including
reminders re-enqueued by opening an existing tour URL. Self-service/legacy behavior,
phone suppression and the separate five-minute phone fallback remain unchanged.
Ownership lookup failures propagate without sending or deleting the pending reminder.
Eight synthetic cases exercise the real cron loop; the required regression was red
before the guard and green afterward. Exact production technical verification is in
issue #55. Natural post-deploy recurrence confirmation remains pending; absence in a
bounded window is not proof. Do not repeat this repair or expand it into scheduling,
closed-conversation, concurrency, routing or handoff-policy changes without new
evidence and scope review. No lead delivery, trip payload, URL or business-data change
was made.

The recorded tour-button Inbox preview repair shipped and was production verified
in PR #742. The exact inbound customer callback metadata and existing
`CallbackGeneration` codec now project a readable `Нажата кнопка «Показать туры»`
label for `show_tours` only. Raw message text/history/search, callback payloads and
dispatch are unchanged. Ordinary typed codes, unknown actions, missing/malformed
metadata and other senders remain literal; raw metadata is removed from the list
response. Existing wait/delivery warning prefixes and preview escaping remain intact.
The display regression was red before the fix; boundary/privacy/prefix regressions,
required CI, responsive layout checks and exact production technical gates passed.
Controlled authenticated reload verified one readable label and no raw generated
show-tours preview among 100 visible rows. This proves rendering of the existing
recorded case, not a new natural post-deploy button event. Do not repeat this repair
or expand callback labeling/dispatch behavior without another confirmed scenario.

The pre-#742 private/browser triage did not confirm a zero-age child storage defect:
the inspected original `child_0` selection and current lead card both represented
two adults and no children. A manager's question alone does not prove corrupt data.
The inspected `tours_checked` path while waiting for a manager is the existing
explicit handoff-cancellation/self-service-resume policy, not a recurrence of the
#740 cron reminder defect. Neither observation authorizes a child payload or handoff
policy change.

The quick-reply draft preservation repair shipped and was production verified in
PR #744. A synthetic reproduction of the actual production-bound button handler
proved that a quick reply replaced and persisted over existing unsent text. Quick
replies now append on a new line while preserving the exact draft and selected
attachment. Buttons follow the existing busy/suspended/access-loss locks; hidden
or disabled composers reject insertion. Template wording, draft storage policy,
sending and upload transport remain unchanged, and insertion never sends a message.
Five new red-to-green cases cover insertion, reload, attachment and session/send
boundaries; the 31-case reply-session suite, 13-case authentication suite, required
CI, responsive visual evidence and exact production technical gates passed.
Synthetic editor and fixture verification are not natural manager/device or
individual production-conversation confirmation. Do not repeat this repair or
expand it into new template policy or cross-device/server draft synchronization.

The exact natural adult-pair answer repair shipped and was production verified in
PR #746. Fresh private chronology showed the bot repeating its adults question
after the exact answer `Я и жена`. The canonical `AdultsParser` now maps only the
normalized exact form `я и жена` to two adults. Existing numeric and word forms are
unchanged; unrelated pairs and phrases containing additional party details remain
unresolved so no children are invented or discarded. Required resolver regression,
CI and exact production technical gates passed. Natural recurrence after deployment
remains separate from release verification. Do not repeat this repair or broaden
spouse/party interpretation without another exact failing phrase and boundary tests.

The bounded check-state price-guidance repair shipped and was production verified
in PR #748. Fresh private chronology showed the exact messages `Цена` and
`Какая цена` receiving generic parameter-edit guidance after the completed search
summary. Only those normalized exact forms now point the tourist to the existing
«Показать туры» control. The reply does not start a search, create a URL, add a
callback, change status or saved needs, or touch Tourvisor/payload and handoff
behavior. Mixed and broader price phrases remain on the previous safe path. Required
MAX/Telegram boundary regression, CI and exact production technical gates passed.
Natural recurrence after deployment remains separate from release verification. Do
not repeat this repair or broaden price interpretation without a new exact failing
phrase and boundary tests.

The exact post-tour `Не показывает` help repair shipped and was production
verified in PR #752. Fresh private chronology showed repeated presses of the
existing generated `show_tours` control, followed by that exact text receiving
generic parameter-edit guidance instead of opening-problem help. The link-help
classifier now accepts only the normalized exact phrase when the existing
controller boundary already has check/AI state and a prior claim. Unrelated and
mixed phrases remain on their previous paths. Existing help copy and manager/edit
controls are reused; URL generation, callback payload/generation behavior,
Tourvisor/search, saved needs/status and automatic handoff are unchanged. Required
regression, CI and exact production technical gates passed. Natural recurrence
after deployment remains separate. Do not repeat or broaden this phrase repair,
and do not infer from it that every repeated `show_tours` callback has the same
cause; callback-generation changes still require separate exact evidence and
authorization.

The MAX25 positional region-attribution repair shipped and was production
verified in PR #754. Owner-provided producer evidence confirmed the complete
`YCLID_REGION_campaign_CAMPAIGN` handoff shape; the canonical parser previously
retained YCLID and campaign but dropped its numeric region. It now recognizes
only that complete ASCII-numeric shape and passes the region into the existing
attribution storage path. Existing incoming payloads, outbound miniapp URL
generation, raw payload and string-ID preservation, legacy formats, Metrica,
lead delivery, shifts, routing/bonuses, webhook registration, schemas and
historical records are unchanged. No producer-side MAX25 file was changed.
Natural MAX25 traffic remains separate confirmation. Do not repeat or broaden
this parser repair without another exact producer/live shape and boundary tests;
producer-side fallback behavior remains a separately authorized project slice.

The owner-authorized paid MAX entry integration shipped and was production
verified in PR #756. The owner explicitly selected `/new/max2/`, not the retired
MAX2 entry path. Only fresh MAX `bot_started` metadata can add the optional
channel-subscription button to the first greeting. The owner subsequently selected
a two-action advertising screen: exact MAX2 invitation, subscription first, then
`Подобрать тур` revealing the existing AI/wizard choices. No 30-second timer or
subscription prerequisite is used. Both screens accept immediate free text through
the existing AI pipeline; the chooser does not reset state/data and ignores stale
callbacks after the user progresses. See #55 for release verification of this
owner-authorized refinement. Ordinary restarts cannot reuse old ad
metadata, and Telegram/organic/invalid/missing-config entries keep their previous
greeting. `ChannelOfferService::startUrl` uses the configured subscription bot's
Mini App and projects only the three numeric fields accepted by `/new/max2/`.
Search keeps `entry_channel` in attribution and its existing suppression policy;
the Mini App receives YCLID/region/campaign with its documented missing-field
defaults. Exact bounds, caller ownership and rollback are in
`docs/MAX2_PAID_ENTRY.md`.

`channel_offer_start` means only a successfully delivered offer, never a click,
subscription or Metrica conversion. Signed MAX user matching, existing persistence
and regional channel opening remain in MAX2; actual membership monitoring belongs
to the separate bot recorded in the neighboring cutover evidence. No neighboring
runtime, registration, exporter, Metrica/goal, lead-delivery, shift, routing or
Tourvisor/search-URL change was included. Natural ad entry through Mini App to an
actual subscription and conversion receipt remains unconfirmed. Do not repeat #756,
copy MAX2's backend, restore its retired webhook or infer permission to change those
protected mechanisms from this completed integration.

The Manager Workspace task-draft lifecycle repair shipped and was production
verified in PR #750. The isolated persistence module previously tried to wrap an
`options.onCreate` callback that the canonical task renderer never accepted, so a
successfully submitted title/deadline could be restored during the lead refresh and
remain available for accidental duplicate creation. The existing create path now
marks only that lead-scoped draft pending before refresh and clears its persisted and
visible fields after confirmed success. Failed creation retains the exact retryable
draft. Server task mutations, reminders, task policy, lead ownership and routing are
unchanged. A real-module behavior regression covers successful refresh, failed
follow-up refresh and failed creation; required CI, responsive visual QA and exact
production technical gates passed. Do not repeat this repair or broaden task draft
storage/synchronization policy without another confirmed defect.

Natural post-deploy confirmation of #724/#725 remains pending; executable regression
and production verification are not natural live confirmation. Review fresh bounded
private dialogue evidence first. A separate natural-language cross-month phrase with
an omitted starting month remains an interpretation/policy question, not a proven
canonical-owner defect or permission to redesign date ranges. Before any next runtime
repair, capture exact input/state and prove a failing regression. No speculative
date-window, URL/payload/Tourvisor or handoff-policy change is authorized.

The confirmed public MAX webhook-log exposure interrupted roadmap work and was contained production-green in PR #715. The containment is now part of the security baseline, not an instruction for follow-up cleanup. Do not restore raw webhook-body persistence, document-root runtime logs or public access to `tmp_in.txt` / `tmp_out.txt`.

The MAX ingress idempotency inventory in PR #717 is also complete. Its documented gaps are risk evidence only: they do not authorize a runtime or schema slice. Keep the current implementation unchanged unless a concrete duplicate/lost event is confirmed or the user explicitly authorizes one narrowly scoped hardening change.

Phase D's selected slices, including the contract-backed meal callback, and Phase E's first two consolidations are complete. The detailed sections below remain as historical acceptance and rollback contracts, not as an instruction to rerun them.

The remaining Phase E inventory was re-audited and no further exact duplicate responsibility was proven, so handoff consolidation is stopped. The two `ManagerAvailabilityService::withinWorkingHours()` calls in `ManagerPhoneFallbackService` are intentionally separate: one bounds candidate selection, while the second rechecks policy inside the per-conversation lock immediately before external delivery. Do not collapse that safety check merely to reduce occurrence count.

The departure-city inventory, value contract, guarded callback and free-text runtime slices are complete in PRs #691, #693, #695 and #697 and must not be repeated. No additional departure-city runtime caller is authorized.

The country-flow inventory, value contract, guarded callback and wizard free-text runtime slices are complete in PRs #699, #701, #703 and #705 and must not be repeated. No additional country runtime caller is authorized.

The date-flow inventory, value contract, guarded date-selection callback and wizard free-text update-only slices are complete in PRs #707, #709, #711 and #713 and must not be repeated. Callback and wizard message paths now share exact calendar-valid `DD.MM.YYYY` projection and current-session update-only application while preserving their distinct guards, pending-month ownership and progression. The AI date path intentionally remains on `NeedApplicationService::applyParameters`: it has upsert semantics and an explicit `NativeDateService::isTodayOrFuture` policy, so it is not equivalent to either migrated wizard writer and must not be mechanically moved to the existing-step boundary.

The direct mutation inventory is now 27 caller groups and 47 occurrences. The confirmed after-tours phone-return repair adds one classified transition caller without changing trip-value storage. The remaining trip-value entries are canonical storage/application wrappers (`ConversationStateRepository`, `MysqlDialogueStateRepository`, `ExistingWizardStepApplicationService`, `NeedApplicationService` and `MaxSearchApi` compatibility methods), not a caller-proven bypass. Phase D's authorized mutation cleanup and Phase E's proven handoff consolidations are therefore stopped at their current production-green boundary. Do not create another cleanup PR merely to reduce counts. Select new code work only from a confirmed production/customer defect or a newly authorized product priority after fresh evidence; keep all protected mechanisms unchanged.

## Advertising daily reporting

The owner requested daily advertising-only dialogue statistics and accepted a
simple attribution approximation: a conversation is paid when its chat currently
has a saved nonempty `yclid`. `paid_daily` in the full autopilot snapshot reports
seven local calendar-day cohorts for the current project, MAX only, excluding
explicit tests. Outcomes use the existing LiveSessionAnalyzer definitions and
only evidence from the conversation start through that local day's end (today
through capture). Each stage counts a conversation once. `tours_opened` is the
recorded show-tours action, not proof the external website rendered; `site_opened`
is a separate recorded event. Missing YCLID is unattributed, not proven organic.
Repeated conversations can inherit current attribution; metadata can be overwritten
by later starts. This is not historical click-level or first-touch attribution.
Only aggregates leave the collector. Legacy production resolves the current saved
YCLID from its canonical Bitrix highload-block in one bounded read-only batch;
standalone runtime uses the canonical traffic files. It performs no writes and
does not change URLs, goals or messages; no workflow/security boundary changes.
The full
publisher refreshes this report; the hourly live publisher does not. A collection
failure exposes `paid_daily.ok=false` with no fabricated zero totals; it does not
change existing technical/manager gate meanings. See #55 for release evidence.
Rollback is a forward revert through normal gates; no data migration is needed.

The fresh #760 production capture proved the active runtime is standalone, not
legacy Bitrix, and therefore could not recover historical YCLIDs from HL34. Paid
MAX `bot_started` handling must persist the canonical traffic metadata before any
optional legacy YCLID mirror, and the mirror must run only when Bitrix is loaded.
This preserves the accepted current-saved-YCLID approximation and prevents a paid
standalone start from aborting before its greeting. It does not reconstruct past
click attribution: advertising cohorts become measurable only from a subsequent
real start carrying a supported payload. Do not classify missing YCLID as organic.

## Customer search origin incident — 2026-09-10

The owner supplied a complete search URL whose query fields and YCLID were intact,
but whose origin was `https://app.anytoour.ru`. The earlier #768–#770 checks did
not prove the runtime-generated destination: their fixture omitted
`MAX_SEARCH_PUBLIC_BASE_URL`, while the website browser smoke used a manually
constructed URL without YCLID. Do not cite those checks as end-to-end attribution
verification.

`ProjectConfig::searchUrl()` must use versioned `search.base_domain` for the
customer website, independently of the deployment/application override in
`baseDomain()`. Application and tracking origin overrides remain valid for their
own callers. The owner-confirmed target is `https://anytoour.ru/poisk-turov/`
with the original query preserved. `tools/search_destination_smoke.php` loads the
real production config and asserts the exact supplied fixture through MAX button
serialization without creating a claim, sending a message or recording a visit.
A successful smoke proves generation under production config, not a natural
customer click or Yandex attribution. Old already-sent buttons are not rewritten
by this generator fix. Confirm required CI and exact deploy evidence in #55 before
calling this release verified.

## Repeated channel invitation incident — 2026-09-10

The owner showed the old pre-results MAX and Telegram subscription buttons inside
an already-started MAX conversation and clarified that this repeated choice is
reserved for the website online consultant. PR #772 gates both the pre-results
offer and post-tour/lead promotion by the actual request-scoped messenger. Native
MAX/TG preserve preparation, acknowledgements and return-to-tours actions without
repeated subscription copy or its artificial delay. The paid MAX first invitation
and AI/wizard chooser remain unchanged. See `docs/MAX2_PAID_ENTRY.md` for policy.

Required adapter-level tests cover MAX, Telegram and website while project config
still selects MAX. CI and exact production evidence belong in #55; neither tests
nor deployment alone prove a natural customer interaction. Do not reintroduce the
old all-transport promotion or infer active transport from attribution metadata.

## Advertising analytics delivery and direct search links

The owner-confirmed analytics incident is complete through PRs #765–#769 and must
not be restarted. PRs #765–#767 installed the existing `cron_metrika.php` uploader
as one production cron entry and added read-only runtime evidence. Production
proved the daemon active, the queue and processing files drained to zero, and
Yandex accepted the accumulated 175 offline events with `status=UPLOADED`, followed
by additional accepted batches as new events arrived. This proves delivery to
Yandex; reporting UI appearance can lag and is not a reason to replay events or
change goal semantics.

PR #768 implements the owner's direct-link experiment: newly generated active and
legacy tour-result buttons use the canonical
`https://anytoour.ru/poisk-turov/?...&yclid=...` destination directly. They no
longer pass through `open_tours.php`; that endpoint remains only for already-issued
links and backward compatibility. Therefore a new direct click is not expected to
produce the old endpoint-owned `site_open`, offline `max_show_tours`, or its
click-triggered follow-up. Do not diagnose their absence after #768 as lost website
traffic. The website's online `V2_SEARCH_STARTED` and `V2_SEARCH_COMPLETE` goals
are the authoritative evidence that the destination search actually began and
completed. A controlled public production smoke confirmed counter `98615635`,
PageView, both goals, and rendered search results; it did not submit a lead or
manager request and used no YCLID.

PR #769 fixes the diagnostics provenance boundary for Metrika delivery evidence.
The full publisher stages the just-captured production diagnostic outside the
diagnostics checkout and uploads that exact fresh file. Do not restore the previous
tracked-path artifact behavior: it could publish stale queue/cron contents even
when the production capture itself was healthy. The fresh artifact must show the
runtime cron, queue/processing sizes and upload-log tail from the same capture.

These releases changed neither goal names/semantics nor lead delivery, routing,
manager shifts, Tourvisor requests or production business data. Any future analytics
repair requires fresh evidence that delivery or online goal emission has failed;
a delayed/filtered Metrika report alone is not enough.

## All-channel daily activity

PR #762 production-verifies the read-only `channel_daily` report in the full
autopilot snapshot. It reports seven Europe/Kaliningrad calendar days for the
current project across `max`, `telegram`, `website` and a fail-closed `other`
bucket, excluding explicit tests. For each channel it separates new conversations
from conversations started earlier but active that day, and records inbound,
manager-request and any outbound-manager-reply conversation counts. These are
conversation aggregates, not people, visits, subscriptions, sales or matched
request-to-reply conversions. A reply recorded today can answer an older request.
The report does not redefine the existing manager response, delivery, handoff or
webhook gates and exports no transcript, user ID, source label or YCLID.

The first production capture showed real manager requests were not absent: on
2026-09-08 MAX recorded 13 requesting conversations and Telegram recorded 2.
It also showed zero non-test website conversation activity for every captured day
from 2026-09-03 through 2026-09-09. That zero is a diagnostic lead, not proof of
zero website visitors or a bot-runtime defect. Read-only inspection confirmed the
live search page loads the existing consultant widget, while the live homepage and
its release source do not. The minimal homepage loader belongs to the neighbouring
website project and requires its own explicit authorization and coordinated release;
do not change or deploy that project from this repository. Until such a release,
do not present the website channel as connected site-wide. Do not create a
synthetic production conversation merely to turn the aggregate nonzero.


## Protected behavior

A confirmed defect permits only a narrow fix that preserves the values and policies below. Changing any of them requires explicit user authorization for that exact change:

- Yandex Metrica counters, goals or goal semantics;
- the existing lead-delivery destination/mechanism;
- operator-controlled manager shifts/`is_working`;
- routing eligibility or bonus values;
- neighbouring repositories, domains or projects; every exact neighbouring project always requires separate explicit authorization.

The fixed handoff product policy remains owned by `AGENTS.md`: 10:00–20:00 Europe/Kaliningrad working window, live manager handoff without mandatory phone during working hours, one phone offer after five minutes without reply, and truthful self-service/optional-phone presentation outside hours.

## Risk and business priority

Use the canonical priority order in `AGENTS.md`; do not maintain a competing numbered order here. A confirmed security exposure or broken release/deploy is a production-safety interruption. Confirmed customer/manager message loss or handoff failure is lead-loss interruption. Proactive control-plane hardening precedes optional roadmap cleanup but does not displace an active customer/business defect.

An aggregate metric is a diagnostic lead, not automatically a code defect. Capture the message/state evidence and a failing regression before changing behavior.

## Completed execution record after Phase C and P0

The items in this section are complete and retained so their original acceptance and rollback contracts are not lost. Do not execute them again. Keep every future item independent and do not combine neighbouring cleanup.

### Documentation truth checkpoint — covered by the introducing PR

The PR that introduces this file also synchronizes the first-read pointers, marks the obsolete `docs/REFACTOR_ROADMAP.md` immediate sequence as superseded and extends the required operating-contract regression. Treat this checkpoint as complete only after its required CI is green, it is merged, the exact merged SHA is production-verified and issue #55 records that evidence. Do not repeat it unless a new contradiction is found.

CI: full required suite, including the operating-contract regression and PHP syntax gate.

Production: exact merged SHA across `main`, deploy status, ops status and production snapshot; migrations, strict MAX TLS and both webhook checks green; no runtime behavior change.

Rollback: revert the introducing docs/test PR if the handoff is materially wrong, then deploy and verify the revert SHA through the same gates.

### Control-plane checkpoint — no application PR

- Enable a GitHub ruleset/branch protection for `main`: require PR, required green checks, and prevent force-push/deletion.
- Verify exact check contexts before requiring them. Keep `Regression tests` and `Retired domain guard`; require Workspace V2 visual QA only after it reliably reports a successful no-op for unaffected changes or through the existing UI-change policy.
- Review and close stale/conflicting PRs as superseded; do not reuse old branches as the base for new work.

Acceptance: ordinary changes cannot reach `main` without the selected checks.

Rollback: relax only the misconfigured rule that blocks legitimate green PRs; do not disable all protections.

### PR S2 — `hardening/deploy-main-provenance`

Goal: a manual or automatic production deploy may deploy only the exact current authoritative GitHub `main` SHA.

Scope:

- fetch the authoritative current `main` ref before bundle/sync and fail if the deployment SHA is not exactly that fresh SHA; never trust a stale local `origin/main`;
- cover current-main and non-current SHA cases in deployment contract regression;
- retain the deploy's full repeated required suite because `main` protection is not yet assumed.

CI: full required suite plus executable provenance cases.

Production: exact-main deploy; all existing stages and status artifacts must agree on the merged SHA.

Rollback: revert this PR if it blocks a legitimate current-main deploy; rollback production remains a new revert commit on `main`, not arbitrary old-SHA deployment.

### PR D1 — `audit/dialogue-mutation-inventory`

Add a machine-readable, required inventory of direct dialogue/trip mutations, including:

- `setStatus`;
- `saveLastValue`;
- `upsertStatusValue`;
- `deleteAll`;
- `applyAiParameters`.

Classify each caller as trip value, transition, reset, metadata or Manager technical state. CI must fail on a new unclassified writer.

CI: inventory behavior and full required suite.

Production: exact SHA and unchanged diagnostics baseline; no runtime behavior change.

Rollback: revert the inventory/guard if its classification is wrong, without touching runtime state.

### PR D2 — `fix/ai-completion-contract`

Define and repair the `AiNeedCompletionService::resolveApplyAndAdvance()` return contract so `applied` has one stable type and meaning.

Required behavior tests:

- recognized and applied;
- rejected input does not advance;
- progression occurs exactly once;
- caller compatibility;
- stable `applied` contract.

No intentional dialogue behavior change. Roll back by reverting the single PR if a caller incompatibility appears.

### PR D3 — `refactor/resolver-application-bypasses`

Move only the caller-proven resolver-side direct `applyAiParameters()` calls through the existing `NeedApplicationService::applyParameters()` boundary where the underlying storage semantics remain identical.

CI must prove the caller list and preserved applied values. Production verification must show no new dialogue/live flags. Roll back by reverting this PR.

### PR D4 — wizard existing-step application contract

Introduce an explicit application contract for an already-existing wizard step. It must preserve update-only/no-insert behavior and the current start boundary.

Required tests cover both storage modes, zero children, exact value representation, stale/missing step and absence of a hidden status transition. Couple it to at most one caller if an otherwise-unused abstraction would be created.

### PR D5 — free-text nights only

Move only free-text nights in `StateMessageHandler` through the deterministic resolver and existing-step application contract.

Required executable cases:

- `На 6`;
- `7-10`;
- `3,4`;
- invalid text;
- normal flow to calendar;
- edit flow back to check;
- no insert on a stale/missing step.

Do not claim live confirmation until a natural post-deploy case occurs.

### PR D6 — `nights_*` callback only

Move only the nights callback through the same application contract while preserving `InteractionGuard`, duplicate/stale/concurrent suppression, edit behavior and the next calendar view.

Required tests must execute the callback action and assert exact stored value and next UI. Roll back on repeated prompts, wrong value, lost edit state or weakened suppression.

### Later Phase D slices

- Migrate adults and stars one field per PR after nights is stable.
- Meal is complete through the contract-backed callback slice in PRs #680 and #681.
- Defer children, child ages, city, country and date until their ID/directory/pending-month/edit semantics have explicit contracts.
- Add state-machine validation observe-only for one transition first, such as nights → date; introduce blocking only after clean production evidence.
- Never mechanically replace `saveLastValue` with an upsert: code rollback cannot undo status rows already written.

Phase D non-negotiable semantics:

- `saveLastValue` updates an existing wizard row, while `NeedApplicationService::applyParameters()` may upsert; do not equate them without an explicit application contract;
- classic wizard/button progression is not automatically equivalent to AI free-text missing-field progression;
- callback payload IDs are not automatically equivalent to resolved semantic values;
- preserve `InteractionGuard`, edit return targets and duplicate/stale/concurrent suppression in every callback slice.

## Phase E — handoff consolidation

Phase E started only after the selected Phase D slices were stable. Its read-only inventory and first two caller-proven consolidations are complete; use the current execution point above for the next decision.

First create a read-only caller/policy inventory for:

- working hours and manager availability;
- five-minute fallback;
- outside-hours behavior;
- presentation versus mutation;
- golden cases for 10:00–20:00 Europe/Kaliningrad, the five-minute boundary and outside hours.

Only then centralize one proven duplicate per PR. A confirmed defect may justify a narrow policy-preserving fix, but changing lead delivery, operator shifts, routing eligibility/bonuses or the fixed product handoff policy still requires explicit user authorization.

## Required gate for every PR

Before merge:

- `bash tests/run_required_checks.sh`;
- green aggregate Regression tests and Retired domain guard;
- Workspace visual QA for relevant UI changes.

For a runtime change, also require an exact caller inventory and a behavior regression, not only source-string assertions.

After every merge, before starting another PR:

- `main = deploy_status = ops_status = autopilot production SHA`;
- deploy verify/bundle/sync/migrations/webhook/smoke/diagnostics stages all succeed;
- migrations: pending 0, checksum failures 0;
- strict MAX TLS on the exact SHA: API and upload HTTP 200, curl errno 0, SSL verify 0;
- MAX and Telegram webhook checks succeed;
- manager visibility, lead detail, handoff integrity, admin project access and website attribution do not regress;
- no new operational failure or count regression outside the owner's documented human-response-backlog exception; keep its raw counts/flags and do not present them as green;
- natural live confirmation is stated only after a real post-deploy case.

## Rollback contract

- Prefer a new revert PR for one merge commit, with the same CI and exact production deploy.
- Do not migrate down or rewrite applied migrations.
- Do not delete/repair production business data merely to make diagnostics green.
- Treat update-versus-upsert semantics as irreversible enough to test before merge.
- Never restore public AI logs or weaken the external AI-log boundary from PR #660.
- Never restore raw MAX webhook-body logging, public `tmp_in.txt` / `tmp_out.txt` or weaken the external webhook-log boundary from PR #715.

## Stop conditions

Stop the current slice and investigate before another merge when any of these occurs:

- `main` and production SHA differ unexpectedly;
- required CI, deploy, migration, strict TLS or webhook verification is red;
- manager/customer message delivery or lead delivery regresses;
- pending Telegram updates grow or a current-SHA 500 repeats;
- a new security exposure appears;
- a protected product-policy or hard-scope change seems necessary without explicit user authorization;
- rollback would require destructive data or migration reversal.

After each material, production-verified change, update issue #55 with the PR, CI runs, merge SHA, deploy/diagnostic/TLS/webhook evidence, remaining uncertainty and the next safe item.
