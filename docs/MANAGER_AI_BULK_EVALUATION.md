# Private bulk evaluation

This auxiliary tool replays historical cutoffs on the selected
`gpt-6.1-sol / low / fast` profile. It does not send customer messages, train
weights, approve company facts or modify the production prompt/config/database.

`manager_ai_bulk_prepare.py` reads an authorized Desktop export, its cumulative
review register and the previous private study. It checks that the source hashes
match, excludes identified reviewed/evaluated histories, freezes 400 development
and 100 reserved histories, and projects past-only bounded contexts. Late-edited
messages are excluded. Shared-account OUT direction is not individual authorship.
Outputs must be private and outside a checkout/document root.
Completed automatic batches are recorded separately from manual reviews in the
register's `mass_evaluation.batches`, with their exact source hash and aliases.
The next preparation excludes those same-export histories as well; this does not
inflate manual semantic-review coverage.

```sh
python3 tools/manager_ai_bulk_prepare.py \
  --archive /private/desktop-export.json \
  --register /private/review-register.json \
  --prior-study /private/completed-study.html \
  --output /private/new-bulk-study
```

The isolated workflow accepts only versioned public metadata and sealed
ciphertext. Verify source and synthetic checks before credentials. A fresh
one-use prepare lease publishes public sodium key/nonce bytes authenticated
against the fixed HTTPS origin and pins the SSH host key. Input is
libsodium sealed-box + gzip; the key is consumed before the first provider call.
No production config/API key is returned to the controller.

Five bounded PHP fork workers use the canonical draft service/client for
generation. Grading is a separate stage with a fixed rubric and strict schema,
the same profile/security caps and no tools, redirects or retries. A separate
grader is still automated model judgment, not independent human accuracy.
Calibrate on labeled synthetic anchors and audit disputed and randomly selected
successful real cases. Grade refusal/coverage separately; abstention alone is
not a quality improvement. Zero-call office policy is not a model answer.

Each started/completed case gets a private remote record. The SQLite controller
queue keys input + contract, preserves status across interruptions, reuses exact
completed work, and refuses automatic replay of submitted/unknown/error cases.
Primary generations and grader calls have separate conservative study budgets.
Record actual model/tier/usage, per-case durations and batch wall duration.
Do not describe the sum/wall ratio as a controlled sequential benchmark or a UI SLA.

Only authenticated RSA3072-OAEP-SHA256/AES256-GCM output leaves the runner.
Controller result validation binds recipient, task nonce, production/source
hashes, input bytes and case membership. Wrong-key/tamper failures must not
produce plaintext. Keep private key material separate from all reports/data packs.
Save owner-only results durably before deleting the ephemeral output identity;
collect retained records without provider replay and explicitly clean each lease.
The detached expiry is housekeeping, not a recurring evaluation schedule.

Never commit plaintext cases, outputs, contact details, private URLs or keys.
The task branch is allowlisted and evaluation-only. A production instruction
change requires its own evidence, review and normal publication gates.
