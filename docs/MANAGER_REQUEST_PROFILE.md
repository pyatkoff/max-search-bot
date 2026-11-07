# Current-trip request profile

Manager2 lead cards in AnyTour link to a separate profile page. An authorized manager explicitly chooses the first message of the current trip, edits known criteria and saves a new immutable version. The existing reply editor, wizard state, operator shifts, lead routing and external prepared-reply worker are unchanged. Profiles do not send messages or enable auto modes.

## What a saved field means

The profile supports departure, destination/exclusions, dates, duration, actual party, customer budget with its basis, hotel criteria, room/meal, essential conditions, compromises, refusals with reasons and open questions. Each value records hard/preferred/unknown importance, an optional manager note and source provenance. Importance and notes are always manager annotations. Literal customer values require an exact excerpt of an eligible inbound customer message. A manager interpretation may cite a manager message or remain explicitly without a citation. This is manager curation, not proof of semantic truth: a quotation alone does not prove agreement, completeness or current supplier conditions. Hotel/company facts belong to their existing reference owners.

A publication price is not automatically the customer's budget. Actual adults, children and ages are separate from a supplier's tariff occupancy. Unknown child ages remain null; zero children is explicit. No values are backfilled from wizard fields, search cards, old trips or model confidence.

The deliberately narrow suggestion recognizes only a complete explicit single-child party statement such as «На те же даты только на 3-х, 2 взрослых и один ребёнок 12 лет». The manager applies it to the local draft, then explicitly saves. It replaces only party; dates keep their own source. Hypotheticals, forwarded text, supplier tariffs, contradictory totals and unrecognized phrases produce no suggestion. Other families can be entered as a manager interpretation with their source. This is not general natural-language extraction.


The read-only `preparation` layer now offers literal fragments from the latest substantive eligible original customer message. An exact short thank-you can preserve that source; unknown, linked, unavailable or contradictory customer input stops fallback. Quoted publication/tariff/AI suggestions and past-trip reports do not supply current conditions; a literal question can still be proposed. It does not append a profile version. Labeled conditions, a literal holiday period, nights, the existing narrow party statement and a short explicit question can be proposed with exact citations and source hashes; conflicting, forwarded or unsupported sources are withheld. A holiday name never supplies a year or precise dates. Adults alone never implies zero children. All unrecognized conditions remain unknown.

The editor shows this proposal separately. An explicit action adds only missing fields to the local draft; confirmed fields, manual edits, pending writes and a new-trip draft are preserved. Saving remains the same separate manager command with context/version/source validation. Clean refreshes adopt the current canonical profile; dirty drafts remain local. The existing AI already reads the original customer messages and saved profiles; this editor-only proposal adds no AI reference key or conditional instruction and consumes no additional provider budget. This adds no worker, queue, provider call, model, schema migration or automatic sender.

## Freshness, history and recovery

Migration080 adds append-only versions and a per-manager operation key. Conversation retention cascades to its private profile history. Existing rows are not rewritten. Every citation binds message id, role, literal quote and raw message/metadata fingerprint. Reads include the latest40 messages and at most13 older cited/boundary messages, with byte and text bounds. Truncated/unsupported context is visible. A new message, source edit or access-scope change invalidates the saved context; changes outside the recent window still invalidate cited evidence. Fresh means only that the saved text context is unchanged. It never confirms price, availability, flights, completeness or permission to send.

Saving rechecks canonical project and conversation access, current actor, source integrity, expected version and context basis. A conversation lock serializes profile saves; stale editors cannot overwrite another version. Another trip has a new trip id, explicit boundary and empty fields; older citations cannot cross that boundary. The form keeps private unsaved edits in sessionStorage per actor/conversation for up to24hours. No private data is embedded in presentation HTML. Account/access mismatch clears the visible profile. Unknown write results are reconciled by operation id before an explicit retry of the same command; there is no background retry.

## Explicit sales-outcome links

The existing Manager2 outcome form can explicitly link a sale, refusal or reopened outcome to a saved trip and its exact manager-profile version. The latest profile is never selected automatically. An unknown trip remains valid; a later trip in the same conversation does not move an earlier link. The bounded choice list contains the latest version of each retained trip and the current historical pin, and indicates incomplete coverage.

`SalesPipelineService` remains the CRM writer. Migration094 adds private append-only binding history; it does not backfill historical outcomes. The CRM fields, optional completion stage and binding commit together. A revision includes the current outcome, stage, conversation scope, latest binding and profile-catalog revision. A stale card or revoked access cannot overwrite current data. The same manager operation can be reconciled after a lost response and explicitly retried with its original payload; replay and binding-only corrections do not emit another sale goal or outcome receipt. Older clients explicitly clear the link on their next successful save.

The form retains an uncertain command and later local edits separately in bounded per-actor/per-conversation sessionStorage. Reload never resends a command. An explicit receipt check precedes an explicit retry of the identical command; unavailable or corrupt recovery storage blocks a new write until reconciled. Readback of an old successful operation does not replace a newer CRM outcome.

These links identify manager assertions, not payment or commission. The existing modeled-conversion receipt v1 and its consumers remain unchanged. The binding is not yet used to change PRAETOR or advertising optimization.

## Existing AI context

The optional profile enters the existing canonical AI reference snapshot and draft revision. Profile changes invalidate cached context. Conditional instructions distinguish literal customer words, manager interpretations, supplier/company facts and stale profiles. A missing table or absent profile contributes no new key, preserving old reference hashes. Known corrupt stored profiles are represented as unavailable. Existing reference budgets and coverage flags still apply; an omitted/truncated reference is not full evidence. External prepared replies do not acquire a new host/model receipt or worker integration from this change.

The released, uninstalled PR1011 pure projection was reviewed for provenance/history conventions. This feature owns explicit manager curation and durable revisions; it does not claim that PR1011's proposed extraction/lifecycle is installed.

## Acceptance and remaining evidence

Focused service and HTTP regressions exercise canonical access, SQL persistence, exact source checks, party-only corrections, context/version races, operation reconciliation, bounded history and AI reference invalidation. Browser cases use byte-exact pages/assets and real local PHP/SQLite, synthetic dialogues and mocked transport faults at390/430/768/1440px. Native MySQL checks migration retry, Unicode hashes, version/operation constraints and retention.

These checks establish implementation behavior. Installation needs the normal required CI/deploy/migration/diagnostic gates. Natural manager use, semantic correction rates, preparation-time changes and independently held-out answer improvements remain separate unverified outcomes. No automatic sending admission is implemented here.
