# Selection context for manager AI

An explicit AI generation may read at most two latest distinct supported Qui-Quo
links in the authorized, retained transcript. The source message ID, sender type
and original timestamp accompany each observation. Merely opening/polling a chat,
the post-generation authorization check and pre-insert `validate` do not fetch
pages. Office-policy and invalid-context results return before the reader.

## Owners and trust

- `ManagerSelectionLinks` extracts server-only capabilities from literal links in
  customer/manager messages. Callback/system/AI text and truncated-away history do
  not authorize a read. Hidden hyperlink metadata and attachments stay unread.
- `ManagerSelectionSnapshot` reads once outside the session/DB locks, binds its
  immutable result to conversation/cutoff/message IDs, senders and projected text,
  and supplies it through a separate server callable to the pure draft service.
  Posted `selection_sources` or `selection_snapshots` fields are ignored.
- `ManagerSelectionPageParser` reads the observed card template without a browser,
  scripts, CSS requests, hotel links, booking links or clicks. It preserves separate
  variants and raw dates/nights/room/traveller labels, leaving missing fields empty.
  Explicit hidden/aria-hidden/inline-hidden fields are excluded. Full computed CSS
  visibility is not verified. Unknown templates remain unread.
- `ManagerAiTextRedactor` is shared by message and card fields. The extracted data
  excludes page URLs, HTML, contacts/footer, raw metadata and external identities.
  Field text remains untrusted evidence, never model instructions or company policy.

Only exact `qui-quo.ru`/`www.qui-quo.ru` links with the observed four-character /
four-character code path are supported. Requests use canonical HTTPS on
`qui-quo.ru:443`, a resolved public IPv4 address pinned to that host, certificate
and hostname validation, and no proxy, cookies, credentials, redirects or retries.
Queries/fragments, ports, userinfo, encoded/extra paths and other origins are not
fetched. The limit signal at 40 source references remains explicit. A network or
page failure leaves the existing unread fallback instead of failing generation.

Two transfers run concurrently with a 500ms connect/1400ms transfer timeout and a
1600ms multi-loop deadline; system DNS resolution precedes that transfer deadline.
Each decompressed page is capped at 1 MiB, parse depth64/nodes20000, and12 returned
variants per page. Additional model data is capped near12k Unicode characters;
omitted/truncated sources and variants remain explicit. These limits are not a
measured browser latency SLA. No response body or URL is logged or cached.

## Meaning of the observation

`observed_at` is when this click read the page; `sent_at` is the recorded source
message timestamp, whose original timezone representation is retained. The
observation does not establish the page contents at sending. Sender type does not
prove card authorship or tourist acceptance. A failed read does not prove manager
deletion, hiding or expiry; configured hide dates remain unknown.

`historical_send_snapshot=false` and `current_price_verified=false` are always
explicit. A displayed number/currency does not establish live availability,
booking or price basis. `price_basis=null` stays unknown; the tourist's whole-party
budget default does not silently become a rule for a page's price. Currency,
families, rooms and alternatives must not be mixed. The model uses scoped matches
and discrepancies in manager reason/checks without inventing current conditions.

This first integration retains observations only during generation. The existing
fingerprint still fences source-message edits/ownership/access/company knowledge,
not later changes to the remote page. Pre-insert validation does not make a price
fresh. Durable send-time snapshots and own-brand selection design are separate
future work; this feature does not archive or modify messages or offers.

## Verification

Required synthetic regressions cover parser/transport boundaries, private-address
and URL fencing, source binding, caps, redaction, hidden fields, failures, ambiguity
and the existing HTTP/session races. Private historical HTML may be used for
parser fidelity, never as current price evidence or public fixtures. Provider
behavior/manager usefulness needs separate observation; passing these tests is
not independent model-quality validation.
