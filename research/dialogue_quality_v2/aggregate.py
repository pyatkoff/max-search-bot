"""Pure read-only cohort reducer for anonymized feature rows.

Caller must supply fully paginated, authorization-filtered, snapshot-consistent rows.
No database access, identifiers, messages, network or exports here.
"""
from collections import Counter
from datetime import datetime, timezone
from classifier import Evidence, classify

STAGES = ('D0','D1','D2','D3','D4','unknown')
PLATFORMS = ('max','telegram')
WINDOWS = (7,30)

def summarize(rows, as_of_unix):
    # No totals for incomplete or mismatched snapshots.
    if not isinstance(as_of_unix, int):
        raise ValueError('as_of_unix required')
    seen = set()
    output = {}
    for days in WINDOWS:
        for platform in PLATFORMS:
            output[(days,platform)] = dict(stage_counts=Counter(), records=0, complete=True, reasons=Counter())
    for row in rows:
        if not row.get('snapshot_complete',False):
            for v in output.values(): v['complete']=False;v['reasons']['incomplete_snapshot']+=1
            continue
        if row.get('snapshot_as_of_unix') != as_of_unix:
            for v in output.values(): v['complete']=False;v['reasons']['snapshot_mismatch']+=1
            continue
        platform = row.get('platform')
        ts = row.get('early_observed_unix')
        anchor = row.get('anonymous_request_key')
        if platform not in PLATFORMS or not isinstance(ts,int) or not isinstance(anchor,str) or not anchor:
            for v in output.values(): v['complete']=False;v['reasons']['invalid_record']+=1
            continue
        key=(platform,anchor)
        if key in seen: continue
        seen.add(key)
        features=row.get('early_features')
        if not isinstance(features,dict):
            for days in WINDOWS:
                output[(days,platform)]['stage_counts']['unknown']+=1
                output[(days,platform)]['records']+=1
            continue
        allowed=Evidence.__dataclass_fields__.keys()
        if any(k not in allowed for k in features):
            for days in WINDOWS: output[(days,platform)]['reasons']['unrecognized_features']+=1
            stage='unknown'
        else:
            stage=classify(Evidence(**features))['stage']
        age=as_of_unix-ts
        for days in WINDOWS:
            if 0 <= age < days*86400:
                v=output[(days,platform)]
                v['stage_counts'][stage]+=1
                v['records']+=1
    return {f'{days}d_{platform}': {'complete':v['complete'],
            'records':v['records'] if v['complete'] else None,
            'stage_counts':{s:v['stage_counts'][s] for s in STAGES} if v['complete'] else None,
            'reasons':dict(v['reasons'])}
            for (days,platform),v in output.items()}
