"""Collect authenticated route candidates produced by the existing public-read workflow."""
import argparse
from datetime import datetime, timedelta, timezone
import hashlib
import io
import json
import re
import subprocess
import tempfile
import zipfile
from pathlib import Path

REPO = 'pyatkoff/max-search-bot'
OBSERVER = '.github/workflows/read-ranked-telegram-public.yml'
CANDIDATE = 'ranked-route-candidate-'
LIMIT = 2_000_000


def api(path, *, binary=False):
    result = subprocess.run(['gh', 'api', '--method', 'GET', f'repos/{REPO}/{path}'],
                            stdout=subprocess.PIPE, check=True, timeout=40)
    return result.stdout if binary else json.loads(result.stdout)


def verified(artifact, observer, body):
    digest = artifact.get('digest', '')
    if not re.fullmatch(r'sha256:[0-9a-f]{64}', digest) or digest[7:] != hashlib.sha256(body).hexdigest():
        raise ValueError('route artifact digest mismatch')
    if len(body) != artifact['size_in_bytes'] or len(body) > LIMIT:
        raise ValueError('route artifact size mismatch')
    with zipfile.ZipFile(io.BytesIO(body)) as archive:
        files = archive.infolist()
        expected = {'route-candidate.json', 'hotel-posts-telegram-main19.php'}
        if len(files) != 2 or {entry.filename for entry in files} != expected:
            raise ValueError('unexpected route archive members')
        if any(entry.file_size > LIMIT or entry.flag_bits & 1 for entry in files):
            raise ValueError('invalid route archive size or encoding')
        raw = archive.read('route-candidate.json')
        resolver = archive.read('hotel-posts-telegram-main19.php')
    report = json.loads(raw)
    producer = report['public']
    if str(producer['run_id']) != str(observer['id']) or producer['repository_sha'] != observer['head_sha']:
        raise ValueError('public observer identity mismatch')
    if artifact['name'] != CANDIDATE + str(report['native']['run_id']):
        raise ValueError('native run identity mismatch')
    if hashlib.sha256(resolver).hexdigest() != report['resolver']['candidate_sha256']:
        raise ValueError('source candidate digest mismatch')
    if not isinstance(report['changes'], list):
        raise ValueError('route row list missing')
    return raw


def collect(out, feed):
    out.mkdir(parents=True, exist_ok=False)
    collected = []
    recovered_native = {}
    # Producer artifacts have seven days of retention; include one extra day at the UTC boundary.
    oldest = (datetime.now(timezone.utc) - timedelta(days=8)).date().isoformat()
    if oldest <= '2026-11-26':
        from merge_ranked_telegram_routes import LATE_PUBLIC_RECEIPTS, stage_original
        for run_id, receipt in LATE_PUBLIC_RECEIPTS.items():
            try:
                with tempfile.TemporaryDirectory() as work:
                    report = stage_original(run_id, feed.resolve(), Path(work))
                (out / f'{run_id}-late-original.json').write_text(json.dumps(report) + '\n')
                collected.append({'observer_run': int(run_id), 'original_public_artifact': report['public']['artifact_id']})
                recovered_native[receipt['early_observer']] = receipt['native']
            except (ValueError, RuntimeError, OSError, KeyError, subprocess.SubprocessError, zipfile.BadZipFile) as err:
                collected.append({'observer_run': int(run_id), 'rejected': type(err).__name__})
    # The archive must come from this exact completed observer, including partial native success.
    for page in range(1, 6):
        runs = api(f'actions/workflows/read-ranked-telegram-public.yml/runs?event=workflow_run&status=completed&created=%3E%3D{oldest}&per_page=100&page={page}')['workflow_runs']
        for run in runs:
            if run.get('event') != 'workflow_run' or run.get('path') != OBSERVER or run.get('status') != 'completed':
                continue
            if run.get('repository', {}).get('full_name') != REPO or run.get('head_repository', {}).get('full_name') != REPO:
                continue
            if not re.fullmatch('[0-9a-f]{40}', run['head_sha']):
                continue
            packs = api(f"actions/runs/{run['id']}/artifacts?per_page=100")
            if packs['total_count'] > len(packs['artifacts']):
                raise ValueError('unexamined observer artifacts')
            candidates = []
            for artifact in packs['artifacts']:
                if artifact['expired'] or not re.fullmatch(CANDIDATE + '[1-9][0-9]*', artifact['name']):
                    continue
                candidates.append(artifact)
            if not candidates:
                originals = [a for a in packs['artifacts'] if not a['expired']
                             and re.fullmatch(r'ranked-public-readback-[1-9][0-9]*', a['name'])]
                if not originals:
                    continue
                # Older public observers produced the authenticated readback before the candidate output existed.
                from merge_ranked_telegram_routes import stage_original
                try:
                    with tempfile.TemporaryDirectory() as work:
                        report = stage_original(str(run['id']), feed.resolve(), Path(work))
                    (out / f"{run['id']}-original.json").write_text(json.dumps(report) + '\n')
                    collected.append({'observer_run':run['id'], 'original_public_artifact':report['public']['artifact_id']})
                except (ValueError, RuntimeError, OSError, KeyError, subprocess.SubprocessError, zipfile.BadZipFile) as err:
                    collected.append({'observer_run':run['id'], 'rejected':type(err).__name__})
            for artifact in candidates:
                try:
                    if artifact['size_in_bytes'] <= 0 or artifact['size_in_bytes'] > LIMIT:
                        raise ValueError('route artifact exceeds size limit')
                    body = api(f"actions/artifacts/{artifact['id']}/zip", binary=True)
                    raw = verified(artifact,run,body)
                    info = json.loads(raw)
                    public = info['public']
                    expected_public = f"ranked-public-readback-{info['native']['run_id']}"
                    companion = [p for p in packs['artifacts'] if not p['expired'] and p['name']==expected_public
                                 and str(p['id'])==str(public['artifact_id']) and p['digest']=='sha256:'+public['artifact_sha256']]
                    if len(companion) != 1:
                        raise ValueError('original public readback artifact mismatch')
                    if (recovered_native.get(run['id']) == info['native']['run_id']
                            and all(info.get(key) == [] for key in ('changes', 'already_configured', 'held'))):
                        collected.append({'observer_run':run['id'], 'artifact_id':artifact['id'],
                                          'superseded_empty_candidate':True})
                        continue
                    (out / f"{run['id']}-{artifact['id']}.json").write_bytes(raw)
                    collected.append({'observer_run': run['id'], 'artifact_id': artifact['id'], 'digest': artifact['digest']})
                except (ValueError, RuntimeError, OSError, KeyError, subprocess.SubprocessError, zipfile.BadZipFile) as err:
                    collected.append({'observer_run':run['id'], 'artifact_id':artifact['id'], 'rejected':type(err).__name__})
        if len(runs) < 100:
            return collected
    raise ValueError('observer history exceeded search limit')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    parser.add_argument('inventory', type=Path)
    parser.add_argument('feed', type=Path)
    args = parser.parse_args()
    args.inventory.write_text(json.dumps(collect(args.directory, args.feed), indent=2) + '\n')
