# Manager2 tour selection constructor

Owner specification: AnyTour_selection_development_spec_2026-10-22.docx.
Coordination: issue #55, source claim 6335241336.

## First implementation slice: editable draft

Manager2 adds «Подборка» to the current conversation header. Its separate editor
supports up to 20 manually entered offers, party/child ages, departure/date/nights,
room/meal, total party price/currency, recommendation, compromise and source.
Managers can reorder, exclude, restore or remove offers. Existing reply text and
attachments remain owned by the conversation composer.

`ManagerTourSelectionDraft` validates editable input only; no submitted price
verification, external identity or publication flags are trusted.
`ManagerTourSelectionService` owns persistence, existing visibility/edit policy,
optimistic revision conflicts and manager-scoped save receipts. Migration 053
adds drafts and receipts without changing sent offers or tourist choices.
`manager/tour-selections-api.php` uses canonical ManagerHttp auth and CSRF.
The browser module is loaded only by the Manager2 opt-in shell.

Autosave occurs after a pause; explicit Save is available. Failed/uncertain
saves keep the exact command. An operation lookup checks its receipt before an
explicit identical retry. A stale revision never silently replaces a colleague's
draft. Unsaved edits are scoped to manager and conversation in session storage;
late responses from another conversation/authentication generation are discarded.

This slice has no customer sending, public page, verified-fare action, photo
downloader, AI/search import or browser extension. Stored draft revisions are
editable and are not immutable offer snapshots. These are subsequent portions
of the same owner-authorized constructor, not completion of the specification.

## Verification and next sequence

Required regression includes actual HTTP auth/CSRF/access and save recovery,
validation, conflicts and unchanged transcript/read markers. The real PHP shell
and production JS/CSS are used by synthetic Playwright cases at 390, 430, 768 and
1440 px, including lost responses and conflicting edits.

Next: immutable publication/offer versions and explicit send intention using
the existing card renderer/delivery/choice owners; per-card delivery receipts
and no unknown-result replay. Then shared revocable public page and the first
bounded TO extension adapter. Owner-only native acceptance precedes ordinary use.
