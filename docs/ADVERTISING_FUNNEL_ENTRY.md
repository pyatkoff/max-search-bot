# Advertising post and offer context

This is the traffic/funnel transport slice. It does not establish a signed Mini
App roundtrip, bind a visitor to a dialogue, train a model or export a conversion.
Append-only advertising visits/cursors belong to the separate advertising-identity
workstream (SOURCE6400088159); the existing latest-chat traffic cache is not a
second visit store.

## Wire and existing callers

`TrafficAttributionService` accepts an optional `_ctx1_<base64url>` suffix after
the existing complete numeric paid payload, including the MAX25 positional
shape. This wire extension is not enabled in the current production receiver. The standard prefix remains
`YCLID_region_REGION_campaign_CAMPAIGN`; an existing `entry_...` source may precede
`region_...`. Old payload parsing and URLs without valid context remain unchanged.

The suffix is unpadded base64url of the exact compact JSON object, in this order:

```json
{"post_id":"55","offer_id":"anex:Alma_2027-01-12_BB"}
```

- `post_id`: existing canonical channel-publication registry ID, a positive
  decimal **string**, 1–20 digits. It is neither a hotel ID nor the public MAX
  message slug. A future resolver must check project, platform, bot and channel
  before obtaining that publication's real message link.
- `offer_id`: stable original offer reference, a string of at most128 ASCII
  letters/digits/underscore/dot/colon/hyphen, beginning with a letter or digit.
  Empty means publication-only; it never selects an arbitrary hotel offer.
- Unknown/duplicate keys, numeric coercion, invalid IDs and noncanonical encodings
  do not yield context. Encoding protects delimiters, not integrity or secrecy.

The complete extended payload stays within the official MAX Mini App limit of
512 characters and its URL-safe alphabet. Reference:
https://dev.max.ru/docs/webapps/introduction . This is a `startapp` contract;
MAX bot `start` links have their own shorter limit. Do not put this whole payload
in a post's existing `p_...` bot link.

The unchanged `MaxUpdateHandler::handleStarted` passes raw input to the existing
`save` call. The service retains valid context only when its parsed advertising
fields match all supplied YCLID/region/campaign fields. A subsequent ordinary or
static post start cannot borrow the previous offer. `buildMiniappUrl(..., includeContext: true)` roundtrips valid current context;
a separately supplied different latestYclid drops it. The new flag defaults to
false, so every existing caller retains its receiver-compatible URL. Only a
verified compatible receiver may opt in.
Legacy save signatures, stored fields and URL bytes remain compatible.

Exact callers: `MaxUpdateHandler::handleStarted`, the compatibility wrappers in
`maxsearchclass.php`, and `ChannelOfferService::startUrl`. The last deliberately
projects only the three currently accepted numeric fields. This change does not
enable an extended MAX2 greeting link or modify the neighbouring receiver.

## Next integration boundary

Existing `/new/max2/` documentation specifies a three-field receiver. Its actual
current source and signed-context handling must be inspected and changed under
that exact project's authorization before enabling the extended feed/miniapp
path. An untrusted URL hint cannot by itself authorize a redirect, bind a person
or prove an offer choice. The existing public-post registry and signed user
identity must remain the authorities.

One public hotel post can represent many offers. The static `p_...` token resolves
the publication, not a particular visitor or advertised offer. Latest-chat
metadata is mutable: two clicks overwrite that cache. It must never be used to
silently pick the latest offer when several candidates exist. The separate visit
history and a verified visit-to-dialogue bridge are still required. If there is
no unambiguous match, show the known candidate terms for a choice. Do not re-ask
all collected travel requirements or attribute an organic/forwarded entry to an
unrelated paid click.

Actual price/availability must be refreshed by the canonical offer owner before
quoting; the saved original offer remains historical context. Model features and
outcome labels may consume only genuinely observed linked events. This slice
changes no goals, analytics semantics, message/lead delivery, routing or shifts.

## Verification and release

`tests/run_manager_priority_regression.php` covers distinct offers of one post,
string/size/type boundaries, malformed encodings, old formats, exact roundtrips,
cache replacement/isolation, different-click suppression and unchanged source
attribution. Its existing fixture executes the real start handler and save path
with isolated SQLite/files and fake transports. The existing DialogueView and
channel-post entry regressions retain the three-field greeting and ownership
contracts. These checks do not prove two concurrent visits are retained or that
an actual person opened the advertised MAX post.

Required current-head CI and normal release/installed verification remain
mandatory. No production/paid launch is authorized by this document. Rollback is
a normal source revert; old readers ignore the extra optional cache keys and no
schema or historical business records need to be changed.
