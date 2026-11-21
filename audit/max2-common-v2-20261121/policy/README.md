# New common value goal: source policy preparation

This directory is isolated pure PHP source and synthetic fixtures. It performs
no lookup, signature authentication, DB write, migration, provider call, sender
activation or live binding. Inputs are INTERNAL facts already verified by their
future source adapters. `authenticated_actor`, `admitted_saved_profile` and an
opaque proof digest are not public authorization mechanisms. A browser or
arbitrary JSON caller must never be allowed to provide these facts directly.

## Business contract

Only new target 687941321, `anytour_modeled_value_v2_max2`, counter 98615635.

1. An inquiry with proven absence of an OWN NEW early reserve receives its full modeled amount on its first v2
   reservation. Subsequent updates reserve only positive increments beyond that
   inquiry's own existing v2 reservations.
2. An inquiry with exactly one eligible observed MAX2 linkage receives only the
   positive increment beyond its imported initial common reservation and all
   later v2 reservations.
3. Possible, ambiguous, incomplete, conflicting or unknown MAX2 evidence yields
   HOLD. It never falls back to the full modeled amount, an estimated subtraction
   or zero evidence. An empty post-filter is not proof of no applicable reserve.

The early `max2ok` goal is never repeated for an inquiry. Existing goal678187097,
its complete values, settings, outbox and sender are not changed, reused as a
reserve or deducted from. Existing saved valued inquiry signal is the input to
the new stream; it is not reassigned away from v1.

Money is canonical integer kopeks, with exact RUB conversion and no float input.
The current read-only source basis `ModeledConversionDealValue::CAP=100000` RUB
matches the10,000,000 kopek bound. Missing model amount is HOLD, not zero. An
admitted zero total or a decrease produces no negative event and frees no reserve.

Example, total100 RUB:

- no own new early reserve, no prior v2 reservation:10000 kopeks;
- linked MAX2, initial common2636 kopeks:7364 kopeks;
- same source profile again:0;
- new immutable profile total150 RUB after either completed reservation:5000;
- earlier attempt unknown/failed: its reserved amount still counts.

## Available source facts, pinned app b678bc2f211c5f141751137453b199036491222d

- `MaxTrackerLookupRepository.php:26–50` is latest-by-actor identity lookup,
  selecting ID/CID/UF_DATE with ORDER BY ID DESC LIMIT1. Its record_id is not a
  launch-to-inquiry proof. The bounded retained-click methods likewise lack the
  four value fields and exact post/offer; candidate selection can choose one of
  several rows sharing a click. They are not reused as a value binding.
- `MaxChannelConversionOutbox.php:242–246` stores that identity record_id as
  tracker_record_id. This is where raw tracker PK exists today. It is separate
  from post_entries.entry_key/publication_id/version_id.
- `ChannelPostEntryService.php:40–46,88–98` and
  `ChannelPostRegistryService.php:754–785` store an event-derived entry key,
  publication and version under a canonical conversation. The schema in
  migration033 has no raw tracker ID, typed offer_id or inquiry/receipt binding.
- `MaxUpdateHandler.php:101–163` supplies the authenticated MAX actor and exact
  p_ publication resolution. Its public CTA need not change. The future policy
  adapter must require the actually configured webhook-auth boundary; it cannot
  turn the handler's conditional secret check into proof when auth is absent.
- `ConversationRecorder.php:291–332` persists customer_channels.external_user_id,
  customer/channel IDs and conversation ownership. These are distinct from HL9
  UF_CHAT_ID (positive actor) and conversations.external_chat_id (negative actor).
- `ModeledConversionAcquisitionStatsReader.php:450–476` validates a real
  substantive_interest anchor journal:<id> against the saved customer-message
  source and native message. This is an observed inquiry anchor, not a new
  inquiry table or proof of a lifetime first contact. profile_version_id alone
  is not an inquiry episode ID.
- `ModeledConversionValueExport.php:979–1013` uses saved profile versions and
  cumulative totals. Its v1 reserved sum has no delivery-state filter. The new
  account uses the same reserve principle with its OWN target and ledger.

The new early producer freezes raw HL9 ID, signed actor, ClientID, metric project,
post/offer, model and four scalar fields. UF_CODE/HMAC keys stay private; the
app can bind a private PK plus exact immutable tuple fingerprint without
reissuing browser keys or reading/copying the bot token.

## Small executable continuation

Root confirmed the existing own coordination can claim new Max2*V2 source/test
paths. No changes are needed in held model/Questionnaire/TextSignals source or
the old exporter/sender.

1. Publish/verify these pure policy files and the separately prepared new
   Max2*V2 lookup module. The lookup uses a distinct purpose on the established
   signed read transport; a new guarded sidecar endpoint avoids edits to the
   existing MaxTracker endpoint/collector. The actual server installation still
   needs the normal exact source/runtime admission, without role/grant changes.
2. New numbered app migration, chosen after a fresh migration/head check, adds
   OWN v2 binding/accounts and events tables. Initial scope is one accounted
   observed inquiry per conversation; a separate inquiry anchor is retained.
   This conservative scope prevents applying the whole-conversation total twice
   to different inferred trips. Multi-inquiry episodes need explicit segmentation
   before that restriction can be removed.
3. New `Max2ValueBindingServiceV2` captures the exact post-entry ID during the
   canonical authenticated entry, then associates the real saved inquiry journal
   reference. It must not find a latest entry/profile by actor and call it proof.
   `Max2ValueLedgerV2` atomically enforces unique receipt↔inquiry and current v2
   account ownership. Concurrent planners return the same event key; only the
   database's unique reservation can permit one delivery attempt.
4. New `Max2ModeledValueReaderV2` reads already saved, allowed model/profile
   material with the complete same-subject/source checks. It carries the existing
   valued signal into the new account and does not call v1 tick/configure/send.
   Profile model/source versions and immutable source digests remain attached.
5. A new V2-only worker/transport adapter consumes only this target's positive
   reserved rows. It can use the audited generic transport utility, but cannot
   run a second v1 sender or touch v1 rows. Collector/readiness comes before
   separately admitted sending. Unknown delivery remains reserved; PROCESSED
   requires an actual provider receipt, never browser callback/JS issuance state.

Binding/account minimum fields: project/platform/internal customer/conversation,
inquiry journal+native message IDs, exact post entry/publication/version/offer,
HL9 PK+tuple fingerprint, frozen original ClientID, early model, initial common
kopeks, binding kind/proof/cutoff. Event fields: account+own target+event key,
immutable profile ID/digest/model, total/delta kopeks, separate delivery state.
Required unique keys include receipt source+PK+new target, inquiry identity+new
target, and account+source-profile event key. Initial HL9 common reserve is
imported once; later reserves have one owner in the app v2 ledger. No second
writer updates the HL9 balance in parallel.

## Genuine integration gaps and coverage boundaries

The app currently has no typed offer_id in post_entries. Exact publication→offer
mapping must come from a frozen canonical mapping/route receipt and be checked
against the observed publication/channel, not a hotel title or current latest
route. A snapshot's arbitrary metadata is not such a contract by itself.

A complete actor-scope zero-result proof can open the full-value branch. Under
the actual installed same-add/all-four/readback producer contract, historical
rows with all four new fields blank mean ZERO own new early reserve. This is
`PROVEN_NO_NEW_EARLY_RESERVE`, not a claim that the person never used MAX2.
The required descriptor SHA256 is
`3a563c1b7921915fd8b17c08f585cde88127904170a086c291a5677d49cf365e`;
it pins the original installation at 2026-11-21T12:04:36.510319Z and subsequent
acceptance, with the installed four runtime source hashes. An arbitrary boolean
or unverified schema does not stand in for that producer proof.

The normalized policy proof uses version2, exact descriptor, own_reserve_only,
complete=true and coverage=all_exact_actor_own_early_reserves_at_observation.
The signed reader's checked_at_unix and complete_within_bound are normalized by
the app adapter, which also proves the actor-to-internal-subject relationship.
Partial or malformed value fields always block the absence proof. Bounds,
timeout, missing scope/index, ambiguous clocks or two positive possible receipts
also produce HOLD. A zero result only after post filtering cannot prove absence.
A separately admitted non-MAX2 source can provide the full-value branch for other
platforms; this code does not invent that source detector or treat a channel name
as cross-channel proof. No historical backfill is part of this slice.

Reverse order is deliberately not disguised as global per-trip deduplication.
An inquiry first accounted at full value has an immutable account binding. A
later independent launch can still create its own early producer reservation,
because that producer does not read the app account. The later receipt cannot
rebind the prior inquiry, and the policy returns HOLD rather than full late
fallback. Multiple launches remain ambiguous/unbound unless exact association
can be proved. Existing reservations are never rewritten or subtracted from v1.

## Verification status

`php tests/policy.php` exercises broad non-MAX2 and linked MAX2 values, complete
empty versus partial/ambiguous evidence, exact subject/actor/pair, one receipt per
inquiry, same-profile conflict, all reserved states, decreases, units/caps and
rejection of old v1 accounts/reserves. It makes no DB/provider/runtime calls.

Fixtures include all-four-blank legacy rows under the actual producer descriptor,
partial evidence, reverse event order, immutable full-versus-linked accounts,
and zero-delta evaluations retained against later same-profile mutation.

PHP is not installed in the local preparation container. The fixture is prepared
but not executed here; native source CI is still required. Filesystem/source
checks do not stand in for PHP execution, a durable claim or a delivered goal.
