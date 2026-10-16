# Recorded trip summary in Manager2

`TripSummaryProjection` owns the display-only snapshot of the last confirmation
successfully sent and mirrored to a conversation. This is historical display
evidence, not the current editable trip/search state. Editing saved parameters
without sending another confirmation does not rewrite the snapshot. The current
client-card trip context keeps its existing owner.

`DialogueView::check` passes the already formatted summary lines, including the
existing exact-departure-date presentation, as local message metadata:

```json
{"trip_summary":{"version":1,"text":"✈️ Москва → Турция 👥 2 взрослых"}}
```

The optional `OutboundMetadataMessengerInterface` extends the original messenger
contract without changing its methods. MAX, Telegram and website adapters carry
metadata only to the existing successful-send recorder call. Provider payloads,
website responses, callback generations, status transitions and recording count
are unchanged. Failed delivery creates no new snapshot. A legacy implementation
of `MessengerInterface` remains compatible. Metadata is request-local function
data, so a later ordinary reply cannot inherit it.

The existing authorized-ID, AI/outbound summary query accepts typed records as
well as historical literal confirmations. It still runs once for the selected
conversations, independently of the latest transcript page. Descending message ID
chooses the latest recognized snapshot across both formats. A valid empty snapshot
clears an older display; malformed or unknown-version records do not shadow the
last recognized one. Untagged historical messages retain the previous literal
header/footer cleanup. Typed plain text is not decoded or stripped a second time.

There is no historical backfill, message rewrite, schema change, new HTTP request,
extra query per conversation, state change or new CRM behavior. The original
bounded query still reads matching historical rows; this change is a projection
contract, not a claim of SQL or production latency improvement. Existing access,
list scope, search and filters continue to use their canonical owners.

The existing `run_manager_workspace_v2_regression.php` executes the isolated
SQLite fixture and real producer/adapter/recorder/projection path, with local
transport callbacks. It covers changed copy/markup, current-versus-recorded
parameters, legacy and typed ordering, invalid metadata, >500 newer messages,
authorized IDs, read-only decoration, entity preservation, failed sends, original
interface compatibility and metadata exclusion from provider/website data.
No real customer send or production business/read-marker write is used for QA.
