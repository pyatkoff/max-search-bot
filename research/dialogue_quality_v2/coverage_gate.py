"""Pure eligibility gate: missing profiles are UNKNOWN, never D0."""
def assess_inventory(records, inventory_exhausted=False, frozen_snapshot=False):
    reasons = {}
    eligible = 0
    for record in records:
        if not record.get('saved_profile_ref'):
            key='no_saved_profile'
        elif not record.get('last_message_clock',{}).get('at'):
            key='unknown_message_clock'
        else:
            key='profile_available_not_yet_verified'
            eligible+=1
        reasons[key]=reasons.get(key,0)+1
    complete=bool(inventory_exhausted and frozen_snapshot)
    return {
        'coverage_complete':complete,
        'eligible_for_profile_review':eligible,
        'inventory_records_seen':len(records),
        'reasons':reasons,
        'stage_distribution':None,
        'warning':None if complete else 'partial_or_nonfrozen_inventory_no_cohort_totals',
    }
