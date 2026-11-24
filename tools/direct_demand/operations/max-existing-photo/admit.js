'use strict';

const R = require('../../../../accepted-release/tools/deployment_release');
const P = require('../../../../accepted-release/tools/release_priority');
const scope = require('./scope.json');
const expected = scope.native_source;
const api = R.makeReadApi(process.env.GITHUB_TOKEN);
const root = R.API_ROOT;
const assert = (condition, reason) => { if (!condition) throw Error(reason); };

async function original(runId, head, workflow, jobId, artifactId, artifactName, digest) {
    const path = root + '/actions/runs/' + runId;
    const [run, jobs, artifacts] = await Promise.all([api('GET', path), api('GET', path + '/attempts/1/jobs?per_page=100'), api('GET', path + '/artifacts?per_page=100')]);
    assert(run.id === runId && run.repository.id === 1342493152 && run.head_sha === head && run.event === 'push' &&
        run.run_attempt === 1 && run.path === workflow && run.status === 'completed' && run.conclusion === 'success', 'native_run_changed');
    assert(jobs.total_count === 1 && jobs.jobs[0].id === jobId && jobs.jobs[0].status === 'completed' && jobs.jobs[0].conclusion === 'success', 'native_job_changed');
    assert(artifacts.total_count === 1 && artifacts.artifacts.length === 1, 'native_artifact_set_changed');
    const a = artifacts.artifacts[0];
    assert(a.id === artifactId && a.name === artifactName && a.expired === false && a.digest === 'sha256:' + digest && a.workflow_run.id === runId && a.workflow_run.head_sha === head, 'native_artifact_changed');
}

async function current() {
    const main = (await api('GET',root+'/git/ref/heads/main')).object.sha;
    assert(main === expected.repository_sha,'main_changed');
    const slot = await api('GET',root+'/issues/comments/6818332618');
    assert(slot.user.id === 226193297 && slot.body === 'PR1694: установлено, выпуск свободен','publisher_busy_or_changed');
    const record = await R.readRecord(api);
    const binding = await R.resolveCurrent(api,{sha:main},record);
    assert(binding.ready && binding.application_sha === expected.application_sha && binding.repository_sha === main,'accepted_application_changed');
    await P.verifyPreviousRelease({repository:R.REPOSITORY,baseSha:main},process.env.GITHUB_TOKEN,fetch);
}

async function admit() {
    await Promise.all([
        original(45373389110,'e1e3d25e42522639e2b82b4bdf32b155a547a184','.github/workflows/read-1566-max-archive-20261123.yml',138373110912,14910511299,'issue-1566-main-max-archive-inventory',scope.archive_sha256),
        original(expected.run_id,expected.workflow_head,'.github/workflows/anytour-hotel-ads-readonly-20261117.yml',expected.job_id,expected.artifact_id,'max-seven-source-rights',expected.zip_sha256)
    ]);
    await current();
    console.log('original source, target archive and current HOTEL application verified');
}
admit().catch(error => {console.error(error.message);process.exitCode=1;});
