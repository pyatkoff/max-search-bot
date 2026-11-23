'use strict';

// Read-only collection completeness check. It does not grant admission or
// acquire a lease; the caller still checks every returned run for conflicts.
const STATUSES = Object.freeze(['in_progress', 'queued', 'waiting', 'requested', 'pending']);
const DELAYS_MS = Object.freeze([250, 750]);
const defaultSleep = ms => new Promise(resolve => setTimeout(resolve, ms));

function inspect(response) {
  const total = typeof response?.total_count === 'number' && Number.isFinite(response.total_count)
    ? response.total_count : null;
  const runs = Array.isArray(response?.workflow_runs) ? response.workflow_runs : null;
  const returned = runs === null ? null : runs.length;
  const state = {total, returned, complete: false, reason: '', retry: false, runs};
  if (!Number.isInteger(total) || total < 0) return {...state, reason: 'invalid_workflow_runs_total'};
  if (total >= 100 || (returned !== null && returned >= 100)) return {...state, reason: 'workflow_runs_cap_exceeded'};
  if (runs === null) return {...state, reason: 'invalid_workflow_runs'};
  const seen = new Set();
  for (const run of runs) {
    if (!Number.isSafeInteger(run?.id) || run.id <= 0) return {...state, reason: 'invalid_workflow_run_id'};
    if (seen.has(run.id)) return {...state, reason: 'duplicate_workflow_run_ids'};
    seen.add(run.id);
  }
  if (returned !== total) return {...state, reason: 'incomplete_workflow_runs', retry: true};
  return {...state, complete: true, reason: 'complete'};
}

function failure(reason, status, attempt, total, returned) {
  // Never include the response body, run fields, URL credentials or transport
  // exception text. All diagnostics are fixed labels and numeric counts.
  const error = new Error(`${reason} status=${status} attempt=${attempt} total=${total ?? 'invalid'} returned=${returned ?? 'invalid'}`);
  error.code = reason;
  error.status = status;
  error.attempt = attempt;
  error.total = total;
  error.returned = returned;
  return error;
}

/**
 * get(path) is the caller's authenticated read-only GitHub transport.
 * Returns all five complete collections; never returns partial observations.
 * A count mismatch gets at most three attempts (initial + two GET retries).
 * Each response must independently have unique positive integer run IDs.
 * The five reads are not claimed to be an atomic GitHub status snapshot.
 */
async function readActiveInventory(get, {sleep = defaultSleep, observe = () => {}} = {}) {
  if (typeof get !== 'function' || typeof sleep !== 'function' || typeof observe !== 'function') {
    throw new TypeError('active_inventory_callback_required');
  }
  const active = [];
  for (const status of STATUSES) {
    for (let attempt = 1; attempt <= 3; attempt++) {
      const started = Date.now();
      let state;
      try {
        state = inspect(await get('/actions/runs?status=' + status + '&per_page=100'));
      } catch (_) {
        state = {total: null, returned: null, complete: false, reason: 'workflow_runs_read_failed', retry: false};
      }
      await observe(Object.freeze({
        status, attempt, observed_at_utc: new Date().toISOString(),
        elapsed_ms: Math.max(0, Date.now() - started),
        total: state.total, returned: state.returned,
        complete: state.complete, reason: state.reason,
      }));
      if (state.complete) {
        active.push(...state.runs);
        break;
      }
      if (!state.retry || attempt === 3) {
        throw failure(state.reason, status, attempt, state.total, state.returned);
      }
      await sleep(DELAYS_MS[attempt - 1]);
    }
  }
  return active;
}

module.exports = Object.freeze({readActiveInventory});
