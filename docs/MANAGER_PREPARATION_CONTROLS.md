# Managed draft preparation

These controls prepare a reply or checks for a manager. They do not send it,
change assignments, broaden the collector cohort or enable another worker.

The existing personal workspace JSON owns a manager's optional `ai_preparation`
rules. The existing API, workspace revision, operation ID and immutable operation
receipt own saving and recovery. A missing rule preserves legacy eligibility;
reading it never creates a settings row.

Managers control their own assigned conversations: one conversation or all their
current and future conversations, permanently or until a stated UTC deadline.
An individual exclusion overrides the all-own rule. An expired enable rule denies
background work; it does not silently inherit legacy/on. Clearing an override is
an explicit request to inherit. Its per-dialog generation remains as a tombstone,
so `all-on → dialog-off → clear` cannot revive work created before the exclusion.
Stop-all preserves exclusions and replaces positive exceptions with inheritance
tombstones. Appearance and other-dialog changes do not change this binding.

Only a fresh administrator in the canonical project scope can change primary
queue preparation. `manager_preparation_queue_policies` owns one project-keyed
rule with its own revision. It is a setting, not a task queue. Queue writes and
recovery use the existing personal-workspace API and operation receipts; receipt
readback rechecks role/project access. Per-admin personal rules never compete as
the shared queue owner. On assignment, a former queue job is no longer admitted;
the new manager policy applies without rewriting history, profile or checks.

Migration 085 adds a nullable policy fingerprint to the existing prepared job and
the project queue-rule table. NULL means legacy. There is no backfill. New jobs,
handled-input lookup, pending lookup, context/input issuance and completion use
the effective rule binding. Completion locks the policy owner before the job CAS.
Revocation/expiry fence pending work and passive readiness. Accepted ready
proposals stay immutable and available through explicit manual reads. The native
textarea, text, selection, File, quotes and manually requested AI keep their owners.

The synthetic controls cases extend the existing prepared-reply regression on
SQLite and native MySQL. `PREPARATION_CONTROLS_ONLY=1` selects just this new slice
for isolated development; ordinary required CI retains its normal coverage.
The assistant browser cases exercise the actual shell/assets with synthetic API
state and no provider or customer traffic.

## Future autonomous sending — documented boundary only

The user decided that a human manager reply pauses future autosend for that
conversation until an explicit authorized resume. That pause outranks all-own
rules and a new tourist message; neither event implicitly resumes it. This package
adds no sender, global send admission or unused sender switch. It does not apply
that future send pause to ordinary draft preparation after a new customer input.
