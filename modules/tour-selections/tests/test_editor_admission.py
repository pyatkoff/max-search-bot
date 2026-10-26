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
        self.base = Path(self.tmp.name)
        self.backend = self.base / 'backend'
        self.backend.mkdir()
        *_, tracked = pack.inputs(ROOT)
        for name in [*tracked, 'modules/tour-selections/admit-editor.py']:
            p = self.backend / name
            p.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / name, p)
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
