"""Exercise actual Git source and archive admission without production writes."""
import importlib.util
import copy
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import textwrap
import unittest
import zipfile

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[3]

def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

admission = load('editor_admission', ROOT / 'modules/tour-selections/admit-editor.py')
pack = load('editor_builder', ROOT / 'modules/tour-selections/build-editor.py')

class AdmissionTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name).resolve()
        self.backend = self.base / 'backend'
        self.backend.mkdir()
        *_, tracked = pack.inputs(ROOT)
        for name in [*tracked, 'modules/tour-selections/admit-editor.py']:
            p = self.backend / name
            p.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / name, p)
        if getattr(self, 'base_version', None):
            manifest = self.backend / pack.MODULE
            spec = json.loads(manifest.read_bytes())
            spec['version'] = self.base_version
            manifest.write_bytes(pack.encoded(spec))
        self.git(self.backend, 'init', '-q')
        self.git(self.backend, 'config', 'user.name', 'Synthetic admission test')
        self.git(self.backend, 'config', 'user.email', 'admission@example.invalid')
        self.commit(self.backend)
        self.backend_sha = self.git(self.backend, 'rev-parse', 'HEAD')
        self.original = self.base / 'original'
        self.current = pack.build(self.backend, self.original)['package_id']
        self.candidate = self.base / 'candidate'
        subprocess.run(['git', 'clone', '-q', '--no-hardlinks', str(self.backend), str(self.candidate)], check=True)
        self.git(self.candidate, 'config', 'user.name', 'Synthetic admission test')
        self.git(self.candidate, 'config', 'user.email', 'admission@example.invalid')
        p = self.candidate / pack.FILES['editor.js']
        p.write_bytes(p.read_bytes() + b'\n// synthetic independent component\n')
        m = self.candidate / pack.MODULE
        spec = json.loads(m.read_bytes())
        major, minor, patch = map(int, spec['version'].split('.'))
        spec['version'] = f'{major}.{minor}.{patch+1}'
        m.write_bytes(pack.encoded(spec))
        self.commit(self.candidate)
        self.output = self.base / 'output'
        self.receipt = pack.build(self.candidate, self.output)

    def git(self, root, *args):
        return pack.git(root, *args)

    def commit(self, root):
        self.git(root, 'add', '.')
        self.git(root, 'commit', '-qm', 'synthetic revision')

    def admit(self, **overrides):
        args = dict(backend=self.backend, candidate=self.candidate, output=self.output,
                    backend_sha=self.backend_sha, source_sha=self.receipt['source_commit'],
                    current_package=self.current, operation='synthetic-operation-1')
        args.update(overrides)
        return admission.admit(**args)

    def rebuild(self):
        self.commit(self.candidate)
        shutil.rmtree(self.output)
        self.receipt = pack.build(self.candidate, self.output)

    def test_new_component_on_unchanged_backend(self):
        before = self.git(self.backend, 'rev-parse', 'HEAD')
        seal = self.admit()
        self.assertEqual(before, self.git(self.backend, 'rev-parse', 'HEAD'))
        self.assertNotEqual((self.backend / pack.FILES['editor.js']).read_bytes(), (self.candidate / pack.FILES['editor.js']).read_bytes())
        self.assertEqual(seal['backend_sha'], self.backend_sha)
        self.assertEqual(seal['source_sha'], self.receipt['source_commit'])
        self.assertEqual(seal['expected_current_package'], self.current)
        self.assertEqual(seal['backend_fingerprint'], self.receipt['backend_fingerprint'])
        self.assertEqual(seal, self.admit())
        self.assertEqual(self.git(self.backend, 'status', '--porcelain'), '')

    @unittest.skipUnless(shutil.which('php'), 'native PHP required')
    def test_native_independent_install_cas_and_preservation(self):
        second = self.base / 'next-candidate'
        subprocess.run(['git', 'clone', '-q', '--no-hardlinks', str(self.candidate), str(second)], check=True)
        self.git(second, 'config', 'user.name', 'Synthetic admission test')
        self.git(second, 'config', 'user.email', 'admission@example.invalid')
        manifest = second / pack.MODULE
        v = json.loads(manifest.read_bytes()); pieces = list(map(int, v['version'].split('.'))); pieces[2] += 1
        v['version'] = '.'.join(map(str, pieces)); manifest.write_bytes(pack.encoded(v))
        asset = second / pack.FILES['editor.js']; asset.write_bytes(asset.read_bytes() + b'\n// synthetic failed activation candidate\n')
        self.commit(second)
        second_output = self.base / 'next-output'; second_receipt = pack.build(second, second_output)
        program = self.base / 'native.php'
        program.write_text(r'''<?php
require $argv[1].'/modules/tour-selections/deploy-editor.php';
[$script,$root,$backend,$candidate,$original,$output,$backendSha,$source,$expected,$second,$secondOutput,$secondSource]=$argv;
$site=dirname($backend).'/site';$releases=dirname($backend).'/releases';mkdir($site,0700);
$d=new TourSelectionEditorDeployment($backend,$site,$releases);
$request=static function($origin,$path,$method,$body)use($backend,$releases){
 $i=ManagerTourSelectionEditorHost::installed($backend,$releases);
 $h="Cache-Control: private, no-store\nContent-Security-Policy: frame-src https://app.anytoour.ru; frame-ancestors https://tours.anytoour.ru";
 if($method==='POST')return ['status'=>401,'headers'=>$h,'body'=>'{"error":"unauthorized"}'];
 parse_str((string)parse_url($path,PHP_URL_QUERY),$q);
 if(isset($q['asset']))return ['status'=>200,'headers'=>$h,'body'=>file_get_contents($i['directory'].'/'.$q['asset'])];
 return ['status'=>200,'headers'=>$h,'body'=>'Manager2 id="editorLauncher" https://app.anytoour.ru/manager/tour-selection-editor.php?conversation_id=1&amp;editor_actor=1'];
};
$r=ManagerTourSelectionEditorHost::json($original.'/receipt.json');$d->install($original,$r['source_commit']);$d->activate($request);
$old=ManagerTourSelectionEditorHost::installed($backend,$releases);$before=hash_file('sha256',$backend.'/manager/assets/workspace-v2-tour-selection.js');
$rejected=false;try{$d->installIndependent($output,$candidate,$backendSha,$source,str_repeat('f',64),'synthetic-stale-cas');}catch(RuntimeException $e){$rejected=$e->getMessage()==='editor_current_package_changed';}
if(!$rejected||file_exists($releases.'/operations/synthetic-stale-cas.json'))throw new RuntimeException('stale CAS wrote intent');
$d->installIndependent($output,$candidate,$backendSha,$source,$expected,'synthetic-upgrade-one');
if(ManagerTourSelectionEditorHost::entry($backend,$releases)!==null)throw new RuntimeException('staged upgrade active before smoke');
$foreign=false;try{$d->rollbackIndependent('synthetic-foreign-operation');}catch(RuntimeException $e){$foreign=$e->getMessage()==='editor_operation_changed';}if(!$foreign)throw new RuntimeException('foreign operation rollback allowed');
$d->activateIndependent('synthetic-upgrade-one',$request);$new=ManagerTourSelectionEditorHost::installed($backend,$releases);
if($new['source']!==$source||$new['package_id']===$old['package_id']||$before!==hash_file('sha256',$backend.'/manager/assets/workspace-v2-tour-selection.js'))throw new RuntimeException('backend or provenance changed');
foreach(['workspace-v2-tour-selection.js','workspace-v2-tour-selection.css','workspace-v2-tour-selection-host.js'] as $name){$url=ManagerTourSelectionEditorHost::workspaceAsset($name,$new);parse_str(parse_url($url,PHP_URL_QUERY),$q);if($q['package']!==$new['package_id'])throw new RuntimeException('Manager2 core differs');}
if(!ManagerTourSelectionEditorHost::assetPackage($old['package_id'],$backend,$releases))throw new RuntimeException('accepted page lost its pinned bundle during upgrade');
if(ManagerTourSelectionEditorHost::assetPackage(str_repeat('f',64),$backend,$releases)!==null)throw new RuntimeException('unaccepted arbitrary package exposed');
if(!$d->install($original,$r['source_commit'])['preserved_independent'])throw new RuntimeException('normal backend deployment downgraded component');
if($d->installIndependent($output,$candidate,$backendSha,$source,$expected,'synthetic-upgrade-one')['changed']!==false)throw new RuntimeException('known operation replayed');
$shared=$backend.'/manager/assets/manager-http-client.js';$oldShared=file_get_contents($shared);file_put_contents($shared,$oldShared."\n// synthetic shared drift\n");
$refused=false;try{$d->install($original,$r['source_commit']);}catch(RuntimeException $e){$refused=$e->getMessage()==='editor_independent_backend_changed';}
if(!$refused)throw new RuntimeException('incompatible common deploy overwrote independent editor');file_put_contents($shared,$oldShared);
$activeBefore=file_get_contents($releases.'/active.json');$pointerBefore=readlink($releases.'/current');$sealBefore=file_get_contents($pointerBefore.'/deployment.json');
$d->installIndependent($secondOutput,$second,$backendSha,$secondSource,$new['package_id'],'synthetic-failed-upgrade');
$failed=false;try{$d->activateIndependent('synthetic-failed-upgrade',static fn()=>['status'=>200,'headers'=>'Set-Cookie: forbidden','body'=>'Manager2']);}catch(RuntimeException $e){$failed=$e->getMessage()==='editor_tours_smoke_failed';}if(!$failed)throw new RuntimeException('failed smoke accepted');
$d->rollbackIndependent('synthetic-failed-upgrade');
if(readlink($releases.'/current')!==$pointerBefore||file_get_contents($releases.'/active.json')!==$activeBefore||file_get_contents($pointerBefore.'/deployment.json')!==$sealBefore)throw new RuntimeException('rollback changed prior accepted release');
$consumed=false;try{$d->installIndependent($secondOutput,$second,$backendSha,$secondSource,$new['package_id'],'synthetic-failed-upgrade');}catch(RuntimeException $e){$consumed=$e->getMessage()==='editor_operation_consumed';}if(!$consumed)throw new RuntimeException('consumed operation replayed');
$failedReceipt=ManagerTourSelectionEditorHost::json($secondOutput.'/receipt.json');if(ManagerTourSelectionEditorHost::assetPackage($failedReceipt['package_id'],$backend,$releases)!==null)throw new RuntimeException('rejected package assets exposed after rollback');
if(ManagerTourSelectionEditorHost::entry($backend,$releases)===null)throw new RuntimeException('new release not active');
echo json_encode(['native_independent'=>true,'backend_unchanged'=>true,'stale_cas_rejected'=>true,'three_shared_assets'=>true,'normal_deploy_preserves'=>true,'known_receipt_reused'=>true]);
''')
        result = subprocess.run(['php', str(program), str(ROOT), str(self.backend), str(self.candidate), str(self.original), str(self.output), self.backend_sha, self.receipt['source_commit'], self.current, str(second), str(second_output), second_receipt['source_commit']], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue(all(json.loads(result.stdout).values()))

    def test_real_shared_change_is_refused_even_with_valid_archive(self):
        p = self.candidate / pack.FILES['http.js']
        p.write_bytes(p.read_bytes() + b'\n// shared transport drift\n')
        self.rebuild()
        with self.assertRaisesRegex(ValueError, 'editor_shared_change'):
            self.admit()

    def test_other_manifest_field_is_refused(self):
        p = self.candidate / pack.MODULE
        v = json.loads(p.read_bytes()); v['name'] = 'foreign-editor'
        p.write_bytes(pack.encoded(v)); self.commit(self.candidate)
        with self.assertRaisesRegex(ValueError, 'editor_manifest_contract_changed'):
            self.admit(source_sha=self.git(self.candidate, 'rev-parse', 'HEAD'))

    def test_rehashed_provenance_is_not_authority(self):
        p = self.output / 'receipt.json'
        v = json.loads(p.read_bytes()); v['source_commit'] = 'a' * 40
        p.write_bytes(pack.encoded(v))
        with self.assertRaisesRegex(ValueError, 'editor_artifact_provenance_mismatch'):
            self.admit()

    def test_ci_is_bound_to_exact_open_pr_source_and_backend(self):
        source = self.receipt['source_commit']; backend = self.backend_sha
        binding = {'number': 31, 'head': {'sha': source}, 'base': {'sha': backend}}
        workflows = admission.REQUIRED_WORKFLOWS | {'.github/workflows/workspace-sound.yml', '.github/workflows/channel-post-context.yml'}
        rows = [{'id': n+10, 'path': path, 'head_sha': source, 'head_repository': {'full_name': 'pyatkoff/max-search-bot'}, 'event': 'pull_request', 'status': 'completed', 'conclusion': 'success', 'pull_requests': [binding]} for n, path in enumerate(sorted(workflows))]
        original = {'pulls/31': {'state': 'open', 'draft': False, 'head': {'sha': source, 'repo': {'full_name': 'pyatkoff/max-search-bot'}}, 'base': {'sha': backend, 'ref': 'main'}}, 'commits/main': {'sha': backend}, 'runs': {'total_count': len(rows), 'workflow_runs': rows}}
        def verify(data):
            return admission.verify_ci(31, source, backend, [pack.FILES['editor.js']], lambda path: data['runs'] if path.startswith('actions/runs?') else data[path])
        self.assertEqual(len(verify(original)), len(workflows))
        for kind in ['missing', 'failure', 'pending', 'old-base', 'old-source', 'foreign', 'draft', 'main-moved', 'truncated', 'new-failed-attempt']:
            data = copy.deepcopy(original)
            if kind == 'missing': data['runs']['workflow_runs'].pop()
            if kind == 'failure': data['runs']['workflow_runs'][0]['conclusion'] = 'failure'
            if kind == 'pending': data['runs']['workflow_runs'][0]['status'] = 'in_progress'
            if kind == 'old-base': data['runs']['workflow_runs'][0]['pull_requests'][0]['base']['sha'] = 'f' * 40
            if kind == 'old-source': data['runs']['workflow_runs'][0]['head_sha'] = 'f' * 40
            if kind == 'foreign': data['pulls/31']['head']['repo']['full_name'] = 'foreign/repo'
            if kind == 'draft': data['pulls/31']['draft'] = True
            if kind == 'main-moved': data['commits/main']['sha'] = 'f' * 40
            if kind == 'truncated': data['runs']['total_count'] = 101
            if kind == 'new-failed-attempt':
                row = copy.deepcopy(data['runs']['workflow_runs'][0]); row.update(id=999, conclusion='failure'); data['runs']['workflow_runs'].append(row)
            with self.subTest(kind=kind), self.assertRaises(ValueError): verify(data)

    def test_exact_source_argument_not_taken_from_artifact(self):
        with self.assertRaisesRegex(ValueError, 'editor_commit_mismatch'):
            self.admit(source_sha='a' * 40)

    def test_dirty_candidate_and_backend_refused(self):
        p = self.candidate / pack.FILES['editor.js']
        original = p.read_bytes(); p.write_bytes(original + b'\n// dirty\n')
        with self.assertRaisesRegex(ValueError, 'dirty_editor_source'):
            self.admit()
        p.write_bytes(original)
        q = self.backend / pack.FILES['editor.js']; q.write_bytes(q.read_bytes() + b'\n// dirty backend\n')
        with self.assertRaisesRegex(ValueError, 'dirty_editor_source'):
            self.admit()

    def test_no_version_bump_is_refused(self):
        p = self.candidate / pack.MODULE
        p.write_bytes((self.backend / pack.MODULE).read_bytes()); self.rebuild()
        with self.assertRaisesRegex(ValueError, 'editor_version_not_newer'):
            self.admit()

    def test_executable_component_is_refused(self):
        path = self.candidate / pack.FILES['editor.js']
        path.chmod(0o755)
        self.git(self.candidate, 'add', pack.FILES['editor.js'])
        self.git(self.candidate, 'commit', '-qm', 'synthetic mode')
        with self.assertRaisesRegex(ValueError, 'editor_component_mode'):
            self.admit(source_sha=self.git(self.candidate, 'rev-parse', 'HEAD'))

    def test_symlink_component_is_refused(self):
        p = self.candidate / pack.FILES['editor.js']; p.unlink(); p.symlink_to('foreign.js')
        self.commit(self.candidate)
        with self.assertRaisesRegex(ValueError, 'editor_shared_change'):
            self.admit(source_sha=self.git(self.candidate, 'rev-parse', 'HEAD'))

    def test_candidate_git_config_cannot_execute_a_monitor(self):
        marker = self.base / 'monitor-executed'
        script = self.base / 'monitor.sh'
        script.write_text('#!/bin/sh\ntouch "' + str(marker) + '"\n')
        script.chmod(0o700)
        self.git(self.candidate, 'config', 'core.fsmonitor', str(script))
        self.admit()
        self.assertFalse(marker.exists())

    def test_duplicate_manifest_field_is_refused(self):
        p = self.candidate / pack.MODULE
        raw = p.read_text(); p.write_text(raw.replace('{', '{"schema":1,', 1)); self.commit(self.candidate)
        with self.assertRaisesRegex(ValueError, 'duplicate_editor_json_key'):
            self.admit(source_sha=self.git(self.candidate, 'rev-parse', 'HEAD'))

    def test_invalid_cas_or_operation_rejected(self):
        for values in ({'current_package': ''}, {'operation': '../foreign'}, {'backend_sha': 'main'}):
            with self.subTest(values=values), self.assertRaises(ValueError):
                self.admit(**values)

    def test_payload_and_archive_are_still_source_bound(self):
        (self.output / 'payload/editor.js').write_bytes(b'foreign')
        with self.assertRaisesRegex(ValueError, 'editor_package_bytes_mismatch'):
            self.admit()

class CommonAdmissionTest(unittest.TestCase):
    """A newer main artifact may replace an accepted, now-incompatible editor."""
    base_version = '0.2.1'
    setUp = AdmissionTest.setUp
    git = AdmissionTest.git
    commit = AdmissionTest.commit

    def prepare_common(self):
        self.base_backend_sha = self.backend_sha
        manifest = self.backend / pack.MODULE
        spec = json.loads(manifest.read_bytes())
        spec['version'] = '0.2.3'
        manifest.write_bytes(pack.encoded(spec))
        # Main includes the previously accepted component change and a genuine
        # shared dependency change, rather than relabelling the old package.
        (self.backend / pack.FILES['editor.js']).write_bytes(
            (self.candidate / pack.FILES['editor.js']).read_bytes())
        shared = self.backend / pack.FILES['http.js']
        shared.write_bytes(shared.read_bytes() + b'\n// synthetic main transport revision\n')
        self.commit(self.backend)
        self.common_sha = self.git(self.backend, 'rev-parse', 'HEAD')
        self.common_output = self.base / 'common-output'
        self.common_receipt = pack.build(self.backend, self.common_output)
        self.prior_package = self.receipt['package_id']

    def admit_common(self, **overrides):
        args = dict(backend=self.backend, output=self.common_output,
                    backend_sha=self.common_sha, current_package=self.prior_package,
                    operation='synthetic-common-1')
        args.update(overrides)
        return admission.admit_common(**args)

    def common_cli(self, *extra):
        return subprocess.run([
            sys.executable, str(self.backend / 'modules/tour-selections/admit-editor.py'),
            '--common-release', '--backend', str(self.backend),
            '--candidate', str(self.backend), '--output', str(self.common_output),
            '--backend-sha', self.common_sha, '--source-sha', self.common_sha,
            '--current-package', self.prior_package, '--operation', 'synthetic-common-cli',
            *extra,
        ], capture_output=True, text=True)

    def test_exact_new_main_artifact_is_admitted_without_source_mutation(self):
        self.prepare_common()
        old_receipt = (self.output / 'receipt.json').read_bytes()
        grant = self.admit_common()
        self.assertEqual(grant['kind'], 'selection-editor-common-release-v1')
        self.assertEqual(grant['backend_sha'], self.common_sha)
        self.assertEqual(grant['source_sha'], self.common_sha)
        self.assertEqual(grant['source_tree'], self.common_receipt['source_tree'])
        self.assertEqual(grant['version'], '0.2.3')
        self.assertEqual(grant['expected_current_package'], self.prior_package)
        self.assertEqual(grant['operation'], 'synthetic-common-1')
        for key in ('package_id', 'archive_sha256', 'backend_fingerprint'):
            self.assertEqual(grant[key], self.common_receipt[key])
        self.assertNotEqual(grant['backend_fingerprint'], self.receipt['backend_fingerprint'])
        self.assertEqual((self.output / 'receipt.json').read_bytes(), old_receipt)
        self.assertEqual(self.git(self.backend, 'status', '--porcelain'), '')
        self.assertEqual(self.git(self.backend, 'rev-parse', 'HEAD'), self.common_sha)
        self.assertEqual(grant, self.admit_common())

    def test_common_cli_requires_same_exact_backend_and_source(self):
        self.prepare_common()
        result = self.common_cli()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(json.loads(result.stdout)['kind'], 'selection-editor-common-release-v1')
        for extra in (('--candidate', str(self.candidate)),
                      ('--source-sha', self.receipt['source_commit']),
                      ('--pull-request', '31')):
            with self.subTest(extra=extra):
                result = self.common_cli(*extra)
                self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertEqual(result.stdout.strip(), '')

    def test_common_runtime_head_and_tracked_bytes_must_match(self):
        self.prepare_common()
        with self.assertRaises(ValueError):
            self.admit_common(backend_sha=self.base_backend_sha)
        alias = self.base / 'backend-alias'
        alias.symlink_to(self.backend, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'invalid_editor_source_root'):
            self.admit_common(backend=alias)
        path = self.backend / pack.FILES['http.js']
        path.write_bytes(path.read_bytes() + b'\n// uncommitted main change\n')
        with self.assertRaisesRegex(ValueError, 'dirty_editor_source'):
            self.admit_common()

    def test_common_receipt_cannot_supply_authority(self):
        self.prepare_common()
        path = self.common_output / 'receipt.json'
        original = path.read_bytes()
        replacements = {
            'release_eligible': False,
            'source_commit': 'a' * 40,
            'source_tree': 'b' * 40,
            'backend_fingerprint': 'c' * 64,
            'archive_sha256': 'd' * 64,
            'package_id': 'e' * 64,
        }
        for key, value in replacements.items():
            with self.subTest(key=key):
                receipt = json.loads(original)
                receipt[key] = value
                path.write_bytes(pack.encoded(receipt))
                with self.assertRaises(ValueError):
                    self.admit_common()
                path.write_bytes(original)

    def test_common_payload_archive_and_preview_remain_bound_to_main(self):
        self.prepare_common()
        for relative in ('payload/editor.js', 'selection-editor.zip', 'preview/preview.css'):
            with self.subTest(relative=relative):
                path = self.common_output / relative
                original = path.read_bytes()
                path.write_bytes(b'foreign artifact bytes')
                with self.assertRaises((ValueError, zipfile.BadZipFile)):
                    self.admit_common()
                path.write_bytes(original)
        self.admit_common()

    def test_common_current_package_operation_and_semver_are_validated(self):
        self.prepare_common()
        for replacement in ({'current_package': ''}, {'operation': '../foreign'},
                            {'backend_sha': 'main'}):
            with self.subTest(replacement=replacement), self.assertRaises(ValueError):
                self.admit_common(**replacement)
        manifest = self.backend / pack.MODULE
        spec = json.loads(manifest.read_bytes())
        spec['version'] = '00.2.3'
        manifest.write_bytes(pack.encoded(spec))
        self.commit(self.backend)
        self.common_sha = self.git(self.backend, 'rev-parse', 'HEAD')
        shutil.rmtree(self.common_output)
        self.common_receipt = pack.build(self.backend, self.common_output)
        with self.assertRaisesRegex(ValueError, 'invalid_editor_version'):
            self.admit_common()

    @unittest.skipUnless(shutil.which('php'), 'native PHP required')
    def test_native_common_release_recovery_uses_actual_admission_and_strict_activation(self):
        self.prepare_common()
        orphan_grant = self.admit_common(operation='synthetic-common-orphan')
        orphan_file = self.base / 'orphan-grant.json'
        orphan_file.write_bytes(pack.encoded(orphan_grant))

        manifest = self.backend / pack.MODULE
        spec = json.loads(manifest.read_bytes())
        spec['version'] = '0.2.2'
        manifest.write_bytes(pack.encoded(spec))
        self.commit(self.backend)
        older_sha = self.git(self.backend, 'rev-parse', 'HEAD')
        older_output = self.base / 'not-newer-main'
        pack.build(self.backend, older_output)
        self.git(self.backend, 'reset', '--hard', self.common_sha)
        spec['version'] = '0.2.4'
        manifest.write_bytes(pack.encoded(spec))
        asset = self.backend / pack.FILES['editor.js']
        asset.write_bytes(asset.read_bytes() + b'\n// fresh common release after rejected smoke\n')
        self.commit(self.backend)
        next_sha = self.git(self.backend, 'rev-parse', 'HEAD')
        next_output = self.base / 'next-common-output'
        next_receipt = pack.build(self.backend, next_output)
        self.git(self.backend, 'reset', '--hard', self.common_sha)

        program = self.base / 'native-common.php'
        program.write_text(r'''<?php
require $argv[1].'/modules/tour-selections/deploy-editor.php';
[$script,$root,$backend,$candidate,$original,$independent,$baseSha,$independentSha,$basePackage,$commonOutput,$commonSha,$olderOutput,$olderSha,$nextOutput,$nextSha,$orphanFile]=$argv;
$site=dirname($backend).'/common-site';$releases=dirname($backend).'/common-releases';mkdir($site,0700);
$checks=0;
function commonCheck(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);++$checks;}
function commonError(callable $call):?string {try{$call();return null;}catch(Throwable $e){return $e->getMessage();}}
function commonReset(string $backend,string $sha):void {
 $process=proc_open(['git','-c','core.fsmonitor=false','-c','core.hooksPath=/dev/null','-C',$backend,'reset','--hard',$sha],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('fixture git unavailable');
 fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);
 if(proc_close($process)!==0)throw new RuntimeException('fixture git reset failed: '.$error);
}
function commonNoIntent(callable $call,string $operation,string $reason,?string $expected=null):void {
 global $releases;
 $pointer=readlink($releases.'/current');$active=file_get_contents($releases.'/active.json');
 $error=commonError($call);
 commonCheck($error!==null&&($expected===null||$error===$expected),$reason.' was not refused: '.(string)$error);
 commonCheck(!file_exists($releases.'/operations/'.$operation.'.json')&&!file_exists($releases.'/pending.json'),$reason.' wrote an intent');
 commonCheck(readlink($releases.'/current')===$pointer&&file_get_contents($releases.'/active.json')===$active,$reason.' changed current state');
}
commonReset($backend,$baseSha);
$deploy=new TourSelectionEditorDeployment($backend,$site,$releases);
$calls=0;
$request=static function($origin,$path,$method,$body)use($backend,$releases,&$calls):array {
 ++$calls;$installed=ManagerTourSelectionEditorHost::installed($backend,$releases);
 $headers="Cache-Control: private, no-store\nContent-Security-Policy: frame-src https://app.anytoour.ru; frame-ancestors https://tours.anytoour.ru";
 if($method==='POST')return ['status'=>401,'headers'=>$headers,'body'=>'{"error":"unauthorized"}'];
 parse_str((string)parse_url($path,PHP_URL_QUERY),$query);
 if(isset($query['asset']))return ['status'=>200,'headers'=>$headers,'body'=>file_get_contents($installed['directory'].'/'.$query['asset'])];
 return ['status'=>200,'headers'=>$headers,'body'=>'Manager2 id="editorLauncher" https://app.anytoour.ru/manager/tour-selection-editor.php?conversation_id=1&amp;editor_actor=1'];
};
$deploy->install($original,$baseSha);$deploy->activate($request);
commonCheck($deploy->commonStatus()['mode']==='normal','ordinary active release unexpectedly requires common admission');
$deploy->installIndependent($independent,$candidate,$baseSha,$independentSha,$basePackage,'synthetic-prior-independent');
commonCheck(commonError(fn()=>$deploy->commonStatus())==='unfinished_editor_operation','unactivated prior component admitted');
commonCheck(commonError(fn()=>$deploy->rollbackCommon('synthetic-prior-independent'))==='editor_operation_changed','common rollback consumed an independent pending operation');
$deploy->activateIndependent('synthetic-prior-independent',$request);
$prior=ManagerTourSelectionEditorHost::installed($backend,$releases);$priorPackage=$prior['package_id'];$oldPointer=readlink($releases.'/current');
$oldActive=file_get_contents($releases.'/active.json');$oldSeal=file_get_contents($oldPointer.'/deployment.json');$oldAccepted=file_get_contents($oldPointer.'/accepted.json');
$oldOperation=file_get_contents($releases.'/operations/synthetic-prior-independent.json');
commonCheck($prior['source']===$independentSha&&$prior['release_kind']==='independent','prior independent provenance missing');
commonCheck($deploy->commonStatus()['mode']==='normal','compatible independent release should be preserved');
commonReset($backend,$commonSha);
commonCheck(ManagerTourSelectionEditorHost::installed($backend,$releases)===null&&ManagerTourSelectionEditorHost::entry($backend,$releases)===null,'old runtime seal ignored current backend drift');
$status=$deploy->commonStatus();
commonCheck($status['mode']==='common'&&$status['expected_current_package']===$priorPackage,'accepted prior package was not selected for explicit common release');
commonCheck(commonError(fn()=>$deploy->install($commonOutput,$commonSha))==='editor_independent_backend_changed','ordinary installation bypassed independent seal');
commonCheck(readlink($releases.'/current')===$oldPointer&&file_get_contents($oldPointer.'/deployment.json')===$oldSeal,'ordinary refusal modified the old seal');

commonNoIntent(fn()=>$deploy->installCommon($commonOutput,$commonSha,str_repeat('f',64),'synthetic-common-stale'),'synthetic-common-stale','stale prior CAS','editor_current_package_changed');
file_put_contents($oldPointer.'/accepted.json','{}');
commonCheck(commonError(fn()=>$deploy->commonStatus())==='editor_prior_not_accepted','tampered accepted marker treated as authority');
commonNoIntent(fn()=>$deploy->installCommon($commonOutput,$commonSha,$priorPackage,'synthetic-common-marker'),'synthetic-common-marker','tampered prior accepted marker');
file_put_contents($oldPointer.'/accepted.json',$oldAccepted);
file_put_contents($releases.'/active.json','{}');
commonNoIntent(fn()=>$deploy->installCommon($commonOutput,$commonSha,$priorPackage,'synthetic-common-active'),'synthetic-common-active','unmatched prior active receipt');
file_put_contents($releases.'/active.json',$oldActive);
file_put_contents($releases.'/operations/synthetic-prior-independent.json',json_encode(['state'=>'activated','package_id'=>str_repeat('f',64),'source'=>$independentSha]));
commonNoIntent(fn()=>$deploy->installCommon($commonOutput,$commonSha,$priorPackage,'synthetic-common-prior-op'),'synthetic-common-prior-op','unmatched prior operation receipt');
file_put_contents($releases.'/operations/synthetic-prior-independent.json',$oldOperation);
$oldAsset=file_get_contents($oldPointer.'/editor.js');file_put_contents($oldPointer.'/editor.js',$oldAsset."\nforeign");
commonNoIntent(fn()=>$deploy->installCommon($commonOutput,$commonSha,$priorPackage,'synthetic-common-payload'),'synthetic-common-payload','tampered prior payload');
file_put_contents($oldPointer.'/editor.js',$oldAsset);
$receiptPath=$commonOutput.'/receipt.json';$receiptBytes=file_get_contents($receiptPath);$receipt=json_decode($receiptBytes,true);$receipt['source_commit']=str_repeat('a',40);file_put_contents($receiptPath,json_encode($receipt));
commonNoIntent(fn()=>$deploy->installCommon($commonOutput,$commonSha,$priorPackage,'synthetic-common-artifact'),'synthetic-common-artifact','tampered main artifact source','editor_source_not_admitted');
file_put_contents($receiptPath,$receiptBytes);
commonReset($backend,$olderSha);
commonNoIntent(fn()=>$deploy->installCommon($olderOutput,$olderSha,$priorPackage,'synthetic-common-version'),'synthetic-common-version','common version not newer than accepted prior','editor_version_not_newer_than_active');
commonReset($backend,$commonSha);

$orphan=ManagerTourSelectionEditorHost::json($orphanFile);$orphanPath=$releases.'/operations/synthetic-common-orphan.json';
$orphanBytes=json_encode(['state'=>'reserved','admission'=>$orphan]);file_put_contents($orphanPath,$orphanBytes);
commonCheck(!file_exists($releases.'/pending.json'),'unknown-ledger fixture unexpectedly has pending state');
commonCheck(commonError(fn()=>$deploy->commonStatus())==='unfinished_editor_operation','UNKNOWN reservation disappeared when pending file was absent');
commonNoIntent(fn()=>$deploy->installCommon($commonOutput,$commonSha,$priorPackage,'synthetic-common-after-unknown'),'synthetic-common-after-unknown','new operation after UNKNOWN reservation','unfinished_editor_operation');
commonCheck(file_get_contents($orphanPath)===$orphanBytes,'UNKNOWN reservation was rewritten');
unlink($orphanPath);
$lock=fopen($releases.'/operation.lock','c+b');commonCheck(is_resource($lock)&&flock($lock,LOCK_EX|LOCK_NB),'fixture could not take publication lock');
commonNoIntent(fn()=>$deploy->installCommon($commonOutput,$commonSha,$priorPackage,'synthetic-common-locked'),'synthetic-common-locked','concurrent common install','editor_operation_busy');
flock($lock,LOCK_UN);fclose($lock);

$installed=$deploy->installCommon($commonOutput,$commonSha,$priorPackage,'synthetic-common-failed');
commonCheck($installed['changed']===true&&($installed['source']??null)===$commonSha&&($installed['operation']??null)==='synthetic-common-failed'&&ManagerTourSelectionEditorHost::entry($backend,$releases)===null,'staged common install receipt is incomplete or activated before smoke');
$staged=ManagerTourSelectionEditorHost::installed($backend,$releases);
commonCheck($staged['release_kind']==='common'&&$staged['source']===$commonSha&&$staged['backend_source']===$commonSha,'common seal does not bind exact main source');
commonCheck($staged['previous_package']===$priorPackage&&$staged['previous_seal_sha256']===hash('sha256',$oldSeal),'common seal lost prior immutable identity');
commonCheck(commonError(fn()=>$deploy->rollbackCommon('synthetic-common-foreign'))==='editor_operation_changed','foreign common rollback changed the operation');
$badRequest=static fn()=>['status'=>200,'headers'=>"Cache-Control: no-store\nSet-Cookie: forbidden",'body'=>'Manager2'];
commonCheck(commonError(fn()=>$deploy->activateCommon('synthetic-common-failed',$badRequest))==='editor_tours_smoke_failed','strict common activation accepted cookie leak');
commonCheck(ManagerTourSelectionEditorHost::entry($backend,$releases)===null,'failed common smoke enabled entry');
commonCheck($deploy->rollbackCommon('synthetic-common-failed')['rolled_back']===true,'owned common rollback failed');
commonCheck(readlink($releases.'/current')===$oldPointer&&file_get_contents($releases.'/active.json')===$oldActive,'rollback did not restore exact old pointer and active receipt');
commonCheck(file_get_contents($oldPointer.'/deployment.json')===$oldSeal&&file_get_contents($oldPointer.'/accepted.json')===$oldAccepted,'rollback rewrote old immutable seal or acceptance');
commonCheck(ManagerTourSelectionEditorHost::entry($backend,$releases)===null,'rollback incorrectly declared old package compatible with new backend');
commonCheck(commonError(fn()=>$deploy->installCommon($commonOutput,$commonSha,$priorPackage,'synthetic-common-failed'))==='editor_operation_consumed','rolled-back common operation replayed');
commonCheck(commonError(fn()=>$deploy->installCommon($commonOutput,$commonSha,$priorPackage,'synthetic-common-relabel'))==='immutable_editor_operation_changed','different operation relabelled a consumed immutable package');
commonCheck(!file_exists($releases.'/operations/synthetic-common-relabel.json'),'relabel rejection wrote intent');

commonReset($backend,$nextSha);
$deploy->installCommon($nextOutput,$nextSha,$priorPackage,'synthetic-common-success');
$activated=$deploy->activateCommon('synthetic-common-success',$request);
$current=ManagerTourSelectionEditorHost::installed($backend,$releases);
commonCheck($activated['active']===true&&($activated['operation']??null)==='synthetic-common-success'&&ManagerTourSelectionEditorHost::entry($backend,$releases)==='https://tours.anytoour.ru/editor.php','fresh common release did not restore working editor entry with its exact operation receipt');
commonCheck($current['source']===$nextSha&&$current['backend_source']===$nextSha&&$current['previous_package']===$priorPackage,'accepted common provenance is not exact main plus prior package');
$acceptedSeal=file_get_contents($current['directory'].'/deployment.json');$beforeCalls=$calls;
commonCheck($deploy->installCommon($nextOutput,$nextSha,$priorPackage,'synthetic-common-success')['changed']===false,'known common operation was replayed');
commonCheck($deploy->activateCommon('synthetic-common-success',$request)['reused']===true&&$calls===$beforeCalls,'known activated operation repeated strict smoke');
commonCheck(file_get_contents($current['directory'].'/deployment.json')===$acceptedSeal&&file_get_contents($oldPointer.'/deployment.json')===$oldSeal,'known receipt reuse relabelled immutable provenance');
commonCheck($deploy->commonStatus()['mode']==='normal','accepted common release still requests migration');
commonCheck($deploy->install($nextOutput,$nextSha)['changed']===false,'ordinary deploy cannot reuse accepted exact main release');
commonCheck(!file_exists($releases.'/pending.json'),'successful common release retained pending operation');
echo json_encode(['common_lifecycle'=>true,'checks'=>$checks,'package_id'=>$current['package_id']]);
''')
        result = subprocess.run([
            'php', str(program), str(ROOT), str(self.backend), str(self.candidate),
            str(self.original), str(self.output), self.base_backend_sha,
            self.receipt['source_commit'], self.current, str(self.common_output),
            self.common_sha, str(older_output), older_sha, str(next_output), next_sha,
            str(orphan_file),
        ], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        observed = json.loads(result.stdout)
        self.assertTrue(observed['common_lifecycle'])
        self.assertGreaterEqual(observed['checks'], 50)
        self.assertEqual(observed['package_id'], next_receipt['package_id'])


class CommonPublicationWorkflowTest(unittest.TestCase):
    """Execute the shipped common/normal host shell with no external commands."""
    @staticmethod
    def shell():
        workflow = (ROOT / '.github/workflows/tour-selection-host.yml').read_text()
        host = workflow.split('\n  independent-editor:\n', 1)[0]
        step = host.split('      - name: Install only the owned tours host and enable links after strict hosted smoke', 1)[1]
        return textwrap.dedent(step.split('        run: |\n', 1)[1].split('      - uses:', 1)[0])

    def test_real_common_shell_cas_receipts_and_unknown_result_boundaries(self):
        shell = self.shell()
        parsed = subprocess.run(['bash', '-n'], input=shell, text=True, capture_output=True)
        self.assertEqual(parsed.returncode, 0, parsed.stderr)
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp).resolve()
            bin_dir = base / 'bin'
            bin_dir.mkdir()
            double = bin_dir / 'double.py'
            double.write_text('''import json,os,sys
name=sys.argv[1];args=sys.argv[2:]
with open(os.environ['CALLS'],'a') as f:f.write(json.dumps([name,args])+'\\n')
if name!='ssh':sys.exit(0)
command=args[-1];scenario=os.environ['SCENARIO']
if 'deploy-editor.php common-status' in command:
 if scenario=='status-command-failure':sys.exit(45)
 if scenario=='malformed-status':print('{');sys.exit(0)
 if scenario=='invalid-status-mode':print(json.dumps({'ok':True,'mode':'foreign'}));sys.exit(0)
 if scenario=='missing-common-cas':print(json.dumps({'ok':True,'mode':'common'}));sys.exit(0)
 mode='normal' if scenario.startswith('normal') else 'common'
 print(json.dumps({'ok':True,'mode':mode,'expected_current_package':os.environ['OLD_PACKAGE']}));sys.exit(0)
if command.startswith('mktemp '):print('/tmp/selection-host-ABC12345');sys.exit(0)
if 'deploy-editor.php install-common ' in command:
 if scenario=='common-install-failure':sys.exit(43)
 result={'ok':True,'package_id':os.environ['NEW_PACKAGE'],'source':os.environ['EXPECTED_SHA'],'operation':os.environ['EDITOR_COMMON_OPERATION']}
 if scenario=='common-lost-install-result':sys.exit(0)
 if scenario=='common-wrong-package':result['package_id']='e'*64
 if scenario=='common-wrong-source':result['source']='e'*40
 if scenario=='common-wrong-operation':result['operation']='foreign-operation'
 print(json.dumps(result));sys.exit(0)
if 'deploy-editor.php activate-common ' in command:
 if scenario=='common-activate-failure':sys.exit(44)
 print(json.dumps({'ok':True,'active':True,'operation':os.environ['EDITOR_COMMON_OPERATION']}));sys.exit(0)
if 'deploy-editor.php activate ' in command and scenario=='normal-activate-failure':sys.exit(46)
print(json.dumps({'ok':True,'active':True}))
''')
            for name in ('ssh', 'scp', 'ssh-keygen'):
                path = bin_dir / name
                path.write_text('#!/bin/sh\nexec "' + sys.executable + '" "' + str(double) + '" ' + name + ' "$@"\n')
                path.chmod(0o700)
            scenarios = {
                'normal-success': 0,
                'normal-activate-failure': 46,
                'common-success': 0,
                'common-install-failure': 43,
                'common-lost-install-result': 1,
                'common-wrong-package': 1,
                'common-wrong-source': 1,
                'common-wrong-operation': 1,
                'common-activate-failure': 44,
                'malformed-status': 1,
                'invalid-status-mode': 1,
                'missing-common-cas': 1,
                'status-command-failure': 45,
            }
            rejected_status = {'malformed-status', 'invalid-status-mode', 'missing-common-cas', 'status-command-failure'}
            unknown_install = {'common-install-failure', 'common-lost-install-result', 'common-wrong-package',
                               'common-wrong-source', 'common-wrong-operation'}
            for scenario, expected_code in scenarios.items():
                with self.subTest(scenario=scenario):
                    run = base / scenario
                    run.mkdir()
                    package = run / 'selection-editor'
                    package.mkdir()
                    new_package = 'b' * 64
                    (package / 'receipt.json').write_text(json.dumps({'package_id': new_package}))
                    calls = run / 'calls.jsonl'
                    operation = 'common-123456-1'
                    sha = 'a' * 40
                    old_package = 'c' * 64
                    env = dict(os.environ, PATH=str(bin_dir) + os.pathsep + os.environ['PATH'],
                               RUNNER_TEMP=str(run), CALLS=str(calls), SCENARIO=scenario,
                               DEPLOY_KEY='PRIVATE KEY synthetic fixture', DEPLOY_USER='synthetic',
                               DEPLOY_HOST='example.invalid', EXPECTED_SHA=sha,
                               EDITOR_COMMON_OPERATION=operation, OLD_PACKAGE=old_package, NEW_PACKAGE=new_package)
                    result = subprocess.run(['bash', '-c', shell], env=env, capture_output=True, text=True)
                    self.assertEqual(result.returncode, expected_code, result.stdout + result.stderr)
                    observed = list(map(json.loads, calls.read_text().splitlines()))
                    commands = [args[-1] for name, args in observed if name == 'ssh']
                    common_installs = [c for c in commands if 'deploy-editor.php install-common ' in c]
                    common_activations = [c for c in commands if 'deploy-editor.php activate-common ' in c]
                    common_rollbacks = [c for c in commands if 'deploy-editor.php rollback-common ' in c]
                    private_rollbacks = [c for c in commands if c.endswith('deploy-editor.php rollback')]
                    public_installs = [c for c in commands if 'modules/tour-selections/deploy.php install ' in c]
                    self.assertEqual(sum('deploy-editor.php common-status' in c for c in commands), 1)
                    self.assertFalse((run / 'selection-host-key').exists(), 'private fixture key survived shell cleanup')
                    if scenario in rejected_status:
                        self.assertEqual(public_installs, [])
                        self.assertEqual(common_installs + common_activations + common_rollbacks + private_rollbacks, [])
                        self.assertFalse(any(name == 'scp' for name, _ in observed))
                        self.assertFalse(any(c.startswith('mktemp ') for c in commands))
                        continue
                    self.assertEqual(len(public_installs), 1)
                    if scenario.startswith('normal'):
                        self.assertEqual(common_installs + common_activations + common_rollbacks, [])
                        self.assertEqual(sum('deploy-editor.php install ' in c for c in commands), 1)
                        self.assertEqual(sum('deploy-editor.php activate ' in c for c in commands), 1)
                        self.assertEqual(len(private_rollbacks), int(scenario == 'normal-activate-failure'))
                        continue
                    self.assertEqual(len(common_installs), 1, 'common installation was replayed')
                    self.assertIn("'" + sha + "' '" + old_package + "' '" + operation + "'", common_installs[0])
                    self.assertIn('$(git rev-parse HEAD)', common_installs[0])
                    self.assertEqual(private_rollbacks, [], 'common failure used a generic private rollback')
                    self.assertEqual(len(common_activations), int(scenario not in unknown_install))
                    self.assertEqual(len(common_rollbacks), int(scenario == 'common-activate-failure'))
                    if common_activations:
                        self.assertIn("activate-common '" + operation + "'", common_activations[0])
                    if common_rollbacks:
                        self.assertTrue(common_rollbacks[0].endswith("rollback-common '" + operation + "'"))
                        self.assertIn('$(git rev-parse HEAD)', common_rollbacks[0])
                        self.assertIn("'" + sha + "'", common_rollbacks[0])


class PublicationWorkflowTest(unittest.TestCase):
    """Run the real publication shell against local, side-effect-free SSH doubles."""
    @staticmethod
    def workflow():
        text = (ROOT / '.github/workflows/tour-selection-host.yml').read_text()
        return text.split('\n  independent-editor:\n', 1)[1]

    @staticmethod
    def shell():
        step = PublicationWorkflowTest.workflow().split('      - name: Install admitted component', 1)[1]
        return textwrap.dedent(step.split('        run: |\n', 1)[1].split('      - uses:', 1)[0])

    def test_only_explicit_main_dispatch_admits_before_credentials(self):
        workflow = self.workflow()
        self.assertIn("if: github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main' && inputs.source_sha != ''", workflow)
        admission_at = workflow.index(' --pull-request "$EDITOR_PR"')
        self.assertLess(workflow.index('run: bash tools/verify_deploy_main_provenance.sh'), admission_at)
        self.assertLess(admission_at, workflow.index('DEPLOY_KEY:'))
        self.assertIn("root/'modules/tour-selections/build-editor.py'", workflow)
        self.assertEqual(workflow.count('persist-credentials: false'), 2)
        self.assertNotIn('run: python3 editor-candidate/', workflow)
        for foreign in ['git pull', 'git reset', 'rsync ', 'migrate', 'composer install']:
            self.assertNotIn(foreign, self.shell())
        result = subprocess.run(['bash', '-n'], input=self.shell(), text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_actual_shell_never_replays_unknown_install_or_rolls_back_foreign_operation(self):
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp); bin_dir = base / 'bin'; bin_dir.mkdir()
            double = bin_dir / 'double.py'
            double.write_text('''import json,os,sys
name=sys.argv[1];args=sys.argv[2:]
with open(os.environ['CALLS'],'a') as f:f.write(json.dumps([name,args])+'\\n')
if name=='ssh':
 command=args[-1]
 if command.startswith('mktemp '): print('/tmp/selection-host-ABC12345')
 if 'install-independent' in command and os.environ['FAIL_AT']=='install':sys.exit(43)
 if 'activate-independent' in command and os.environ['FAIL_AT']=='activate':sys.exit(44)
''')
            for name in ['ssh','scp','ssh-keygen']:
                path = bin_dir / name
                path.write_text('#!/bin/sh\nexec '+sys.executable+' '+str(double)+' '+name+' "$@"\n')
                path.chmod(0o700)
            for failure, expected_code in [('none', 0), ('install', 43), ('activate', 44)]:
                with self.subTest(failure=failure):
                    run = base / failure; run.mkdir()
                    calls = run / 'calls.jsonl'
                    env = dict(os.environ, PATH=str(bin_dir)+os.pathsep+os.environ['PATH'], RUNNER_TEMP=str(run),
                               CALLS=str(calls), FAIL_AT=failure, DEPLOY_KEY='PRIVATE KEY synthetic fixture',
                               DEPLOY_USER='synthetic', DEPLOY_HOST='example.invalid', EXPECTED_SHA='a'*40,
                               EDITOR_SOURCE='b'*40, CURRENT_PACKAGE='c'*64, EDITOR_OPERATION='owned-operation-1')
                    result = subprocess.run(['bash', '-c', self.shell()], env=env, capture_output=True, text=True)
                    self.assertEqual(result.returncode, expected_code, result.stdout+result.stderr)
                    commands = [args[-1] for name,args in map(json.loads,calls.read_text().splitlines()) if name=='ssh']
                    rollback = [c for c in commands if 'rollback-independent' in c]
                    self.assertEqual(len(rollback), int(failure=='activate'))
                    if rollback:self.assertTrue(rollback[0].endswith("rollback-independent 'owned-operation-1'"))
                    install = next(c for c in commands if 'install-independent' in c)
                    self.assertIn("'"+'a'*40+"' '"+'b'*40+"' '"+'c'*64+"' 'owned-operation-1'", install)
                    if failure!='install':
                        activate = next(c for c in commands if 'activate-independent' in c)
                        self.assertIn('$(git rev-parse HEAD)', activate)
                        self.assertIn("'"+'a'*40+"'", activate)
                    self.assertFalse((run / 'independent-editor-key').exists())


if __name__ == '__main__':
    unittest.main()
