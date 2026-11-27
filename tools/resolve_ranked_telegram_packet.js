'use strict';

const crypto = require('node:crypto');
const R = require('./deployment_release');
const {API_ROOT, REPOSITORY, requireValue, runIdentity, boundedList} = R;
const WORKFLOW = R.PREFIX + 'edit-1566-ranked-46-historical-telegram.yml';
const NATIVE_READER = R.PREFIX + 'read-1566-telegram-46-20261123.yml';
const NATIVE_BRANCH = 'ops/1566-telegram-46-native-read-20261123';
const APPROVAL_LIST = 'config/anytour_ranked_approved_indexes.json';
const OWNER_ID = 226193297;
const REPOSITORY_ID = 1342493152;
const OWNER = 'pyatkoff';

// These independently managed catalogue surfaces do not control the Telegram
// writer, its routes, credentials, database contract, or installation locks.
const INDEPENDENT_CATALOGUE_FILES = new Set([
  'docs/APPLESERVICE39_PILOT.md',
  'services/AppleService39PilotCatalog.php',
  'tests/run_repair_max_webhook_regression.php',
  'tests/support/appleservice39_catalogue_switch.php',
  'tools/appleservice39_catalogue_release.js',
  'tools/catalogue_release_transaction.js',
  'tools/check_appleservice39_catalogue_package.php',
  'tools/deploy_hotel_catalogue_guard.sh',
  'tools/hotel_catalogue_package.js',
  'tools/hotel_catalogue_release.js',
]);

async function requireCompatibleRankedMain(api, application, main) {
  if (application === main) return;
  const diff = await api('GET', API_ROOT + '/compare/' + application + '...' + main);
  requireValue(diff?.status === 'ahead' && diff.merge_base_commit?.sha === application
    && Number.isSafeInteger(diff.total_commits) && diff.total_commits > 0 && diff.total_commits <= 250
    && Array.isArray(diff.commits) && diff.commits.length === diff.total_commits
    && diff.commits.at(-1)?.sha === main && Array.isArray(diff.files) && diff.files.length < 300
    && diff.files.every(file => ['added','modified','removed'].includes(file.status)
      && INDEPENDENT_CATALOGUE_FILES.has(file.filename)), 'ranked_data_release_affects_editor');
}

function owned(run) {
  return run.repository?.id === REPOSITORY_ID && run.repository.full_name === REPOSITORY
    && run.head_repository?.id === REPOSITORY_ID && run.head_repository.full_name === REPOSITORY
    && run.actor?.id === OWNER_ID && run.actor.login === OWNER
    && run.triggering_actor?.id === OWNER_ID && run.triggering_actor.login === OWNER;
}

async function authenticateReader(api, packet) {
  const source = packet.native_source;
  const path = API_ROOT + '/actions/runs/' + packet.native_run;
  const run = runIdentity(await api('GET', path), {id:packet.native_run, attempt:1, head:source.head_sha,
    workflow:NATIVE_READER, event:'push', branch:NATIVE_BRANCH});
  requireValue(owned(run), 'ranked_data_reader_actor_changed');

  const artifacts = boundedList(await api('GET', path + '/artifacts?per_page=100'),
    'artifacts', 'ranked_data_reader_inventory_invalid');
  const item = artifacts[0];
  requireValue(artifacts.length === 1 && item.id === packet.native_artifact && item.expired === false
    && item.name === source.artifact_name && item.size_in_bytes === packet.native_zip_bytes
    && item.digest === 'sha256:' + packet.native_zip_digest && item.workflow_run?.id === run.id
    && item.workflow_run.repository_id === REPOSITORY_ID && item.workflow_run.head_sha === run.head_sha
    && item.workflow_run.head_branch === NATIVE_BRANCH, 'ranked_data_reader_artifact_changed');

  const jobs = boundedList(await api('GET', path + '/attempts/1/jobs?per_page=100'),
    'jobs', 'ranked_data_reader_job_invalid');
  const job = jobs[0];
  const step = job?.steps?.filter(entry => entry.name === source.step);
  requireValue(jobs.length === 1 && job.id === source.job_id && job.name === 'registry'
    && job.run_id === run.id && job.run_attempt === 1 && job.head_sha === run.head_sha
    && job.status === 'completed' && job.conclusion === 'success'
    && step?.length === 1 && step[0].status === 'completed' && step[0].conclusion === 'success',
    'ranked_data_reader_job_invalid');
}

async function resolveRankedPacket({env, event, api}) {
  const ref = env.GITHUB_REF;
  const match = /^refs\/heads\/(ops\/anytour-hotel-ranked-data-([a-f0-9]{64}))$/.exec(ref || '');
  requireValue(match && env.GITHUB_REPOSITORY === REPOSITORY && env.GITHUB_EVENT_NAME === 'push'
    && env.GITHUB_WORKFLOW_REF === REPOSITORY + '/' + WORKFLOW + '@' + ref
    && env.GITHUB_JOB === 'transfer' && env.GITHUB_RUN_ATTEMPT === '1'
    && env.GITHUB_ACTOR === OWNER && env.GITHUB_TRIGGERING_ACTOR === OWNER
    && R.sha(env.GITHUB_SHA) && R.positive(env.GITHUB_RUN_ID)
    && event?.repository?.id === REPOSITORY_ID && event.repository.full_name === REPOSITORY
    && event.sender?.id === OWNER_ID && event.sender.login === OWNER && event.ref === ref
    && event.after === env.GITHUB_SHA && event.created === true && event.before === '0'.repeat(40)
    && event.deleted === false && event.forced === false, 'untrusted_ranked_data_writer');
  const [, branch, indexDigest] = match;
  const head = env.GITHUB_SHA;
  const runId = Number(env.GITHUB_RUN_ID);
  const sourceRunPath = API_ROOT + '/actions/runs/' + env.GITHUB_RUN_ID;
  const branchPath = API_ROOT + '/git/ref/heads/' + branch;
  const mainPath = API_ROOT + '/git/ref/heads/main';
  const main = (await api('GET', mainPath))?.object?.sha;
  requireValue(R.sha(main) && main !== head && (await api('GET', branchPath))?.object?.sha === head,
    'ranked_data_ref_changed');
  const original = runIdentity(await api('GET', sourceRunPath), {id:env.GITHUB_RUN_ID, attempt:1, head,
    workflow:WORKFLOW, event:'push', completed:false, branch});
  requireValue(owned(original) && original.status === 'in_progress', 'ranked_data_writer_not_active');
  const history = boundedList(await api('GET', API_ROOT + '/actions/workflows/' + WORKFLOW.slice(R.PREFIX.length)
    + '/runs?branch=' + encodeURIComponent(branch) + '&per_page=100'), 'workflow_runs', 'ranked_data_history_invalid');
  requireValue(history.length === 1 && history[0].id === runId, 'ranked_data_replay_forbidden');
  const writerJob = boundedList(await api('GET', sourceRunPath + '/attempts/1/jobs?per_page=100'),
    'jobs', 'ranked_data_job_invalid');
  requireValue(writerJob.length === 1 && R.positive(writerJob[0].id) && writerJob[0].run_id === runId
    && writerJob[0].run_attempt === 1 && writerJob[0].name === 'transfer' && writerJob[0].head_sha === head
    && writerJob[0].status === 'in_progress' && writerJob[0].conclusion === null, 'ranked_data_job_invalid');

  // Reuse the installed index-format parser. The real detached ref was authenticated above.
  const packet = await R.approvedRankedInput(api, head, indexDigest, 'ops/anytour-hotel-ranked-plan-' + indexDigest);
  const content = await api('GET', API_ROOT + '/contents/' + packet.plan_path + '?ref=' + head);
  requireValue(content?.type === 'file' && content.path === packet.plan_path
    && content.sha === packet.plan_blob && content.encoding === 'base64' && typeof content.content === 'string',
    'ranked_data_index_changed');
  const bytes = Buffer.from(content.content.replace(/\n/g, ''), 'base64');
  requireValue(bytes.length === packet.plan_size && R.gitBlob(bytes) === packet.plan_blob
    && crypto.createHash('sha256').update(bytes).digest('hex') === indexDigest, 'ranked_data_index_changed');
  const index = R.parseJson(bytes);
  const planPath = index.plans[0].path;
  requireValue(typeof planPath === 'string' && /^config\/anytour_[a-z0-9_]+\.json$/.test(planPath)
    && planPath !== packet.plan_path && planPath !== APPROVAL_LIST && packet.plan_path !== APPROVAL_LIST,
    'ranked_data_plan_invalid');

  const location = await R.readRecord(api);
  const installedRepository = location.record.repository_release?.repository_sha ?? location.record.sha;
  requireValue(R.sha(installedRepository), 'ranked_data_application_not_installed');
  const application = await R.resolveCurrent(api, {sha:installedRepository,id:env.GITHUB_RUN_ID,attempt:1,
    event:'push',workflow:WORKFLOW,job:'transfer'}, location);
  const base = application.application_sha;
  requireValue(application.ready === true && application.perform_actions === true && R.sha(base)
    && application.repository_sha === installedRepository, 'ranked_data_application_not_installed');
  requireValue(index.native_preflight.application_sha === base && index.native_preflight.repository_sha === base,
    'ranked_data_native_base_changed');
  if (installedRepository !== base) await R.requireAncestry(api, installedRepository, main);
  await requireCompatibleRankedMain(api, base, main);

  const commit = await api('GET', API_ROOT + '/git/commits/' + head);
  requireValue(commit?.sha === head && Array.isArray(commit.parents) && commit.parents.length === 1
    && commit.parents[0].sha === base, 'ranked_data_parent_changed');
  const comparison = await api('GET', API_ROOT + '/compare/' + base + '...' + head);
  const expected = new Map([[packet.plan_path,'added'],[planPath,'added'],[APPROVAL_LIST,'modified']]);
  requireValue(comparison?.status === 'ahead' && comparison.total_commits === 1
    && comparison.merge_base_commit?.sha === base && Array.isArray(comparison.files)
    && comparison.files.length === expected.size && new Set(comparison.files.map(file=>file.filename)).size === expected.size
    && comparison.files.every(file=>expected.get(file.filename) === file.status), 'ranked_data_scope_changed');
  await authenticateReader(api, packet);

  const current = await R.readRecord(api);
  requireValue(current.blob === location.blob && current.record_digest === location.record_digest,
    'deployment_result_changed');
  requireValue((await api('GET', mainPath))?.object?.sha === main
    && (await api('GET', branchPath))?.object?.sha === head, 'ranked_data_ref_changed');
  return {...application,write_operation:packet.operation,ranked_data_sha:head,ranked_data_index_path:packet.plan_path,
    ranked_index_digest:indexDigest,ranked_artifact_id:String(packet.native_artifact),ranked_control_sha:main};
}

if (require.main === module) {
  const env = process.env;
  (async () => R.outputs(await resolveRankedPacket({env,event:R.readJson(env.GITHUB_EVENT_PATH),
    api:R.makeReadApi(env.GITHUB_TOKEN)})))().catch(error => {
    process.stderr.write('ranked_data=refused reason=' + (error instanceof R.Refusal ? error.code : 'internal_failure') + '\n');
    process.exitCode = 1;
  });
}
