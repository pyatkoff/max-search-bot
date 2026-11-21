# MAX2: verify two new goals while preserving the old goals

Prepared single native read, not yet executed. Base:
`pyatkoff/max-search-bot@b678bc2f211c5f141751137453b199036491222d`.
Own scope is recorded in issue1140/comment7053831014 and the corresponding
MAX2 record tour-max#211/comment7054553165.

## Exact scope

One original creation of `audit/max2-new-goals-20261121`, attempt1 only, after
independent source review. No rerun, dispatch or branch replay. At most two GETs:

1. `/management/v1/counter/98615635`
2. `/management/v1/counter/98615635/goals`

No parameters, pagination, retries, stats reports, database, goal changes,
conversion sends, campaign access, application install or configuration changes.

The new action/exact targets are checked independently:

| Goal | Type | Exact target |
| --- | --- | --- |
| 687931224 | action | max2ok |
| 687941321 | action | anytour_modeled_value_v2_max2 |

The protected old definitions must match the accepted catalog observation from
run44889693953/source05d8604c0a092bd034f18da7fadc248b3238c074:

| Goal | Name | Type and condition |
| --- | --- | --- |
| 563541588 | max2 | url; contain `/max2/`; is_retargeting false |
| 678187097 | AnyTour: оценочная ценность обращения v1 | action; exact `anytour_modeled_value_v1`; is_retargeting false |

Only these baseline fields are compared. Campaign settings, advertising price,
code activation, monetary model, goal receipt and visit linkage are not checked
or changed by this read. A collected catalog is not a sent conversion.

## Transport and output

Retains the existing local READ_ONLY=true token bootstrap in the established
standby checkout, origin check, hostname/TLS verification, no redirects/proxies,
SSH host fingerprint and existing standby GitHub secret names. The native
workflow checks exact reader, carrier, tests and decrypt-helper hashes before
credentials. All request failures are terminal for this read.

The artifact contains only an AES-256-GCM/RSA-OAEP-SHA256 encrypted report; a fresh
RSA3072 public key is bound in the workflow. Its private key remains local and is
never included in source or artifacts. Do not search for older private keys.
Root supplies native run/job/artifact IDs and downloaded archive digest to the
local helper after verifying the actual original run. `source-pins.json` and
`expected-provenance-template.json` are templates/evidence inputs, not proof of
execution.

Run local synthetic checks with:

`python3 -B -m unittest discover -s audit/max2-new-goals-20261121 -p 'test_*.py'`

Twenty tests cover exact target isolation, old definition drift, missing and
duplicate IDs, counter identity, bounded projection, method/endpoint/order
restrictions, TLS/no redirect behavior, no retry or credential leakage, archive
source/digest/attempt, authenticated decryption, privacy and private-key custody.

Publish only the audit files and the workflow at
`.github/workflows/max2-new-goals-readonly-20261121.yml`. Local staging scripts,
`private/`, compiled files and decrypted results are not source inputs.
