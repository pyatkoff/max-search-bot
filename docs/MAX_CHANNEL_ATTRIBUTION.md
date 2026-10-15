# MAX channel attribution

Owner decision, 2026-10-15: select the latest known ClientID; use the actual
occurrence time of each conversion event, reconciling tracker timezones separately.

## Selection

`MaxSubscriptionAttributionPolicy` accepts MAX subscription candidates normalized
by a future tracker adapter. Match the exact positive external MAX user ID and
effective counter. A nonempty `UF_METRIC_SOURCE` takes precedence over `UF_SOURCE`;
an unknown explicit override must not fall back to another counter.

Select the highest canonical decimal tracker record ID with a valid nonempty
ClientID. This matches the existing `ORDER BY ID DESC` tracker read. A newer empty
record does not erase a previously known ClientID. IDs remain strings, including
values larger than signed 64-bit integers. Conflicting copies of the winning
record fail closed. Observation time is optional metadata, not an ordering key.

The latest known ClientID may have become available after the event. Do not add a
pre-event ClientID cutoff to this owner-selected rule. When runtime enrichment is
connected, persist the resolved attribution once per event; retries must retain
that resolution and the original event time.

## Event time

Use the original message/manager-response event time, not queue insertion,
enrichment, retry or subscription time. Convert the instant to Unix seconds.
The ClientID selection policy never reads the clock or changes this timestamp.

Read-only source/clock inspection on 2026-10-15 confirmed:

- tracker FPM `date.timezone=Europe/London`, OS timezone `Europe/London`;
- Bitrix timezone conversion disabled, timestamp writers construct Bitrix DateTime;
- `UF_DATE` and `UF_SUB_DATE` are DATETIME without a CURRENT_TIMESTAMP default;
- MySQL uses SYSTEM/BST and was UTC+3600 during the capture; latest tracker time
  was consistent with that local clock;
- the conversation trial verified its message/start database clock was UTC.

Interpret tracker wall timestamps as `Europe/London`; never subtract a fixed hour.
London uses BST in summer and GMT in winter. For timezone-free historical values
inside a DST overlap, require an explicit offset or other reliable disambiguation.
Reject nonexistent local times rather than silently normalizing them.

Evidence: [tracker time inspection](https://github.com/pyatkoff/tour-max/actions/runs/38726287142),
[conversation trial](https://github.com/pyatkoff/max-search-bot/actions/runs/38724100239).

## Activation status

This slice contains pure policy and required executable regression coverage. It
does not wire a tracker endpoint, change existing YCLID exports, create goals,
upload conversions, or enable automatic ClientID attribution. Runtime integration
still needs an authenticated lookup and a durable event queue that preserves
original event time and prevents duplicate stage exports.
