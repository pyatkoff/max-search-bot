# Project supervision and dialogue analytics

Owner direction: issue55 comment6349106885,22October2026. This is a narrow
read/explicit-AI capability, not a global administrator role, routing permission,
new CRM state model or resumption of private-learning.

## Capability and explicit grant

Migration056 adds `manager_supervision` and `manager_supervision_sources`.
It grants nobody by default. An active account must already belong to the active
project. Its enabled grant selects explicit active sources from that project,
optionally including conversations without a source. New sources are not silently
included. Project/account/source revocation is effective on fresh reads. No login
is special in runtime ACL; role, project membership, shift and sending stay intact.

`ManagerSupervisorAccessService::set` is the canonical command. A current active
admin with that project's access selects an exact existing active account/project,
source IDs, enabled flag and expected version. The short transaction uses an
optimistic version, locks current administrative authority on MySQL and inserts a
mandatory audit row before commit. Audit failure rolls back permission changes.
An old version cannot replay the command. Reading the directory is privileged;
the grant form at `/manager2/analytics.php` displays exact login/ID and requires
readback before another write, including after an uncertain response.

For the requested account `manager`, use that explicit protected form/API only
after resolving its actual active ID and AnyTour project in the directory. Do not
run `saveManager(role=admin)`, reset a password, auto-seed a named account, alter
source groups, grant other projects or issue unreviewed production SQL. Source
publication and a confirmed production grant/readback are separate results. This
change does not itself establish a privileged signed-in session.

## Workspace owner

The owner's explicit correction on 23 October 2026 supersedes the supervisor-only
scope for the existing administrator account. `admin` already owns broad workspace
administration and project access; its AI, analytics and team Assistant reads now
use all of its current active project memberships without a separate employee
grant. No role is assigned or widened by publication, and no login is special.
Ordinary employee supervision still requires the explicit audited source grant.
The administrative role and project list are reread for each request; source
filters do not silently turn into employee permissions. Historical and unsourced
conversations in an owned project remain readable to its administrator.

Owner AI can explicitly analyze waiting, self-service and closed clients without
taking or reopening them. For an active assigned employee, ready results retain
that employee's canonical context/TTL/identity. A waiting client's ready result
retains zero assignment. Other states can be explicitly analyzed but do not reuse
an incompatible historical ready result. Existing provider, budgets, account and
late-context fences remain unchanged. Viewing creates no foreign feedback,
read mark or customer send. Cross-project ready reads are restricted to freshly
verified owner access; worker enqueue, completion and ordinary employee runtime
project restrictions remain unchanged.

## Read owner versus viewer

`ManagerConversationAccessPolicy::canView` remains the legacy mutation/queue
visibility predicate. `canRead` is an explicit read-only extension. Canonical
`visibleConversation(...,true)`, transcript detail, read-only pipeline detail and
protected media GET opt in; mutation callers keep the original default. A grant
therefore cannot become new permission to take/reopen/reassign/send, edit tasks,
write another manager's notes or alter a pipeline. Ordinary assigned-manager
reply checks, auth, CSRF and source/project fences remain canonical.

Supervisor transcript detail never marks another conversation read, including
the direct service default. The browser also leaves Inbox unread badges intact.
Opening Analytics or its conversation links in a separate tab preserves the
original tab's local reply text, File and reading position; it creates no second
message-draft store. The personal Assistant's decisions and tasks remain owned
by the assigned manager; supervision does not widen that mutation surface.

`ManagerAiDraftContextService::viewerSnapshot` verifies the viewer's fresh grant,
resolves the actual active assigned owner, and obtains that owner's unchanged
canonical snapshot/revision/photo context. An unassigned or non-manager-state
conversation does not acquire a synthetic owner. Ready results are looked up via
existing `ManagerPreparedReplyService::prepared` with the real owner, full
revision/instruction/latest/TTL checks. Viewer identity never replaces stored
owner identity or the canonical revision. The separate access revision detects
grant/source changes while a model request is pending.

Explicit AI in a foreign conversation uses the same existing provider/service,
model/quota path and session-unlock/fresh-session checks. It may show the proposal
or generate on that explicit click; it does not mark an insertion, record another
manager's feedback, mutate a ready proposal, validate insertion or send. The
preview is labelled as supervision, and has no Add action. Late account,
assignment, conditions, session or permission changes discard the result.
Automatic readiness polling remains limited to the own-compose path.

## Analytics definitions and coverage

`ManagerDialogueAnalyticsService` is a read-only server model independent of
Inbox200 and board100 caps. `/manager/supervision-api.php` uses canonical auth,
CSRF, session identity and fresh capability checks. Analytics uses one short
read-only repeatable snapshot (MySQL) and rechecks access outside it before
returning. No AI, attachment downloads, preparation, read markers or business
writes occur in this path. Current-owner filters always intersect allowed
project/source/channel scope. Explicit tests and fixed owner demos are excluded.

- Date boundaries are calendar days in Europe/Moscow, inclusive UI end and
  exclusive next midnight in UTC. At most93calendar days per request.
- **Started cohort:** conversations started within that period. **First-request
  cohort:** conversations whose first recorded waiting-manager event falls
  within the period. **Current active:** all currently waiting/manager-state
  conversations, regardless of period; dates apply only to its events block.
- Funnel counts are distinct conversations in that full selected cohort:
  conversation → first recorded manager request → first successfully recorded
  manager reply at/after that request → subsequent real customer message.
  Callback/start service messages are not customer continuation. A failed-send
  event is not a reply. The original message recorder's success boundary is
  reused; recording is not independent proof of customer receipt.
- First-response N/mean/nearest-rank p50/p95 concern the first recorded request
  and reply over the selected conversation's lifetime, not each repeated handoff.
  Missing requests/replies are excluded from N and remain missing. Repeated
  requests count separately in period events. Timestamp resolution is the
  canonical recorded resolution; no subsecond latency is inferred.
- **Period events** independently count requests, assignment records, successfully
  recorded reply messages/distinct replied conversations and failed-delivery
  events across the whole allowed population. Replies to old conversations can
  appear here without belonging to a newly started cohort. These event counts
  are not a matched conversion denominator.
- Manager grouping/filtering uses the **current assigned manager**. After a
  transfer the conversation is grouped with its new owner; the original first
  reply sender is separately returned/displayed. This is not a historical
  employee-performance ranking.
- Waiting is the current waiting state, an unanswered latest recorded handoff,
  or a later real customer message than the last successful manager message.
- Sales show only explicit existing won/lost outcomes with an update timestamp;
  recorded stages require a matching canonical stage-history row. Default new,
  unknown outcome and reply do not establish a sale. Missing coverage is shown.
- AI coverage is separate: visible cohort N versus **candidate ready** rows
  matching owner/latest/instruction/TTL. Full reference/context revision is checked
  on AI open, so verified-fresh full-cohort count is explicitly null, not falsely
  labelled complete. Analytics never generates a missing analysis.
- Aggregates/group totals cover the whole allowed cohort. Drilldown pages use a
  descending conversation-ID keyset,50rows and explicit continuation. Each request
  is a fresh authorized snapshot; concurrent live changes are not a frozen export.
- Editing any filter invalidates the visible report and its continuation until
  Show is applied. Each request is bound to the applied filter/metric signature;
  a late page from the previous cohort cannot append rows or update its timestamp. Access
  revision is also pinned; a changed grant/source scope clears earlier rows and
  requires a fresh first page. Same-scope continuation refreshes aggregate counts.

## Acceptance

Existing prepared regression runs the same added grant/owner/full206-row cohort
suite on isolated SQLite and native MySQL, including mandatory-audit rollback,
revocation, unchanged role/shift/projects, source/project denial, transfer,
full pagination/N/percentiles, repeated handoff, old-request/new-period reply,
failed delivery, timezone boundary and unconfirmed stage. The actual HTTP
endpoint/session/CSRF/grant/readback suite runs from the existing AI endpoint
regression; synthetic provider tests retain late logout/account/scope checks.
Existing browser AI suite additionally covers readonly foreign preview, own
draft/File retention, Analytics responsive states/pages and explicit grant
readback at390/430/768/1440 and short390x420. Artifacts contain synthetic data.

Required exact-head CI and exact installed deployment remain mandatory. Browser
fixtures and synthetic provider responses are not a real production grant,
physical-device acceptance, natural AI usage, customer send or production p50/p95.
