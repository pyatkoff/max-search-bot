# Manager company knowledge

The AI reply button receives a versioned company snapshot from
`knowledge/company.json`. The initial approved content is deliberately narrow:
the AnyTour public name and its customer-facing tour-search website, both grounded
in the existing versioned `project.php`. These facts do not establish legal identity,
office availability, geography of service, booking, payment or document policies.

## Ownership and updates

`ManagerCompanyKnowledge` is the only reader. It accepts at most 50 records from
a repository-owned file of at most 64 KiB. Eligible records must have an exact
project key, `approved` status, unique ID, bounded text/topic, approval provenance,
UTC review/expiry timestamps and a matching source hash. Unknown projects, future
reviews, expired/withdrawn/candidate records and changed sources yield no facts.
The first source allowlist contains only `project.php` identity fields. Supporting
another source or a commercial policy is an explicit reviewed code/data change.

To update knowledge, establish the exact source and project, document who approved
the literal claim, set its review/expiry dates and submit the fact with the matching
source hash through the normal PR checks. A changed source withdraws its facts
until re-reviewed. A new catalog version alone never approves a candidate.
Do not copy manager messages, archived offer prices or shared-account signatures
into approved company policy. A transport namespace is not a brand identity: ANEX
archives collected under an AnyTour transport must not be projected into an
AnyTour manager conversation as an approval shortcut.

## Runtime boundary

The authorized context builder selects the conversation's project and returns an
immutable server-owned knowledge object separately from the transcript. The pure
draft use-case accepts it as a separate typed argument. Caller-supplied
`verified_company_facts` remain ignored. Offline callers without an explicit server
snapshot still get an empty company base; no live project is guessed from text.

The knowledge revision is part of the existing context fingerprint. Catalog,
approval and source changes, or crossing an eligibility/expiry boundary, invalidate
an outstanding proposal through the existing post-generation and pre-insert checks.
Ordinary capture time changes do not invalidate a stable fact set. No database,
network, model or workspace action is used for knowledge retrieval.

Facts are bounded evidence in the user payload, never instructions. Source and
approval strings cannot override the prompt. The model may use only the literal
claim; a company name does not authorize an office, payment or booking assertion.
The deterministic unknown-office check remains active. Customer-facing response
shape and human review/insertion/sending are unchanged.

## Next content work

Gather individually sourced and approved rules for booking, payment, documents,
support contacts and service geography. Keep unapproved material out of the active
catalog. Selection snapshots are a separate future context source: record what was
observed and when, bind it to its message and distinguish observation time from
send time and current price/availability. No selection content is read in this slice.
