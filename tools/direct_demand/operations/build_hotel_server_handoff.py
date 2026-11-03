#!/usr/bin/env python3
"""Build a pinned HOTEL handoff offline. No network, PHP execution, Git or provider calls.

The reviewed control flow is immutable. Only the strict launch manifest, canonical
public payload, derived constants and workflow pins may change between launches.
"""
from pathlib import Path
import argparse
import hashlib
import json
import re
import sys

PHP = 'tools/direct_demand/operations/hotel-server-private-readiness.php'
WORKFLOW = '.github/workflows/hotel-server-private-readiness-20261102.yml'
BUILDER = 'tools/direct_demand/operations/build_hotel_server_handoff.py'
KIND = 'hotel-server-private-forward-handoff-v2'
PROFILE = '92ae1de467574b17a77d188b2f9766d139e5ada622126a20d2fe2052f5506323'
PHP_CONTROL_SHA = '3f3b04c89304a8131a71bbee0049ec337a916fad58a8a774bae68887ce60d6e9'
WORKFLOW_CONTROL_SHA = '6a2fd2a44519c58e24d9bcb9d35cf8e8730f4fe31af2d47a45e9878e5bd963f5'
COMPLETION = {'state': 'complete', 'stage_cursor': 4, 'new_accepted': 4,
              'historical_accepted': 19, 'reserved_calls': 8,
              'reserved_tokens': 8417600, 'reserved_micros': 88000000}
DYNAMIC = ('HOTEL_EXPECTED_INSTALLED_SHA', 'HOTEL_OPERATION', 'HOTEL_PREDECESSOR',
           'HOTEL_CAMPAIGN', 'HOTEL_OLD_BOOTSTRAP', 'HOTEL_PREDECESSOR_INPUTS_SHA',
           'HOTEL_OLD_RUNTIME', 'HOTEL_PAYLOAD_SHA', 'HOTEL_DELTA_SHA',
           'HOTEL_PLAN_SHA', 'HOTEL_POLICY_SHA', 'HOTEL_BUDGET_SHA')


def require(condition, reason):
    if not condition:
        raise ValueError(reason)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def blob(raw):
    return hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()


def canon(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':'),
                      allow_nan=False).replace('\u2028', '\\u2028').replace('\u2029', '\\u2029').encode()


def no_duplicates(pairs):
    out = {}
    for key, value in pairs:
        require(key not in out, 'duplicate_json_key')
        out[key] = value
    return out


def parse(raw):
    return json.loads(raw, object_pairs_hook=no_duplicates,
                      parse_constant=lambda value: (_ for _ in ()).throw(ValueError('nonfinite_json')))


def keys(value, expected):
    require(type(value) is dict and set(value) == set(expected), 'strict_object_shape')


def hash_value(value, length=64):
    require(type(value) is str and re.fullmatch('[a-f0-9]{' + str(length) + '}', value), 'hash_required')


def campaign(value):
    require(type(value) is str and re.fullmatch('[A-Za-z0-9][A-Za-z0-9_-]{0,79}', value)
            and value != 'HOTEL-CONTINUOUS-20261027', 'new_campaign_required')


def substitute_once(pattern, replacement, text):
    result, count = re.subn(pattern, replacement, text, flags=re.M)
    require(count == 1, 'unique_reviewed_substitution_required')
    return result


def constant(text, name, value):
    return substitute_once(r"(^const " + name + r"=')[^']*(';)$",
                           lambda match: match[1] + value + match[2], text)


def nowdoc(text, label, value):
    require('\n' + label not in value, 'nowdoc_delimiter_forbidden')
    return substitute_once(r"(return <<<'" + label + r"'\n)[\s\S]*?(\n" + label + r";)",
                           lambda match: match[1] + value + match[2], text)


def read_nowdoc(text, label):
    matches = re.findall(r"return <<<'" + label + r"'\n([\s\S]*?)\n" + label + ';', text)
    require(len(matches) == 1, 'unique_reviewed_nowdoc_required')
    return matches[0].encode()


def normalize_php(text):
    for name in DYNAMIC:
        text = constant(text, name, '<DERIVED>')
    text = substitute_once(r'^const HOTEL_LAUNCH_DEADLINE=[0-9]+;$',
                           'const HOTEL_LAUNCH_DEADLINE=<DERIVED>;', text)
    text = nowdoc(text, 'LAUNCH_MANIFEST_JSON', '<MANIFEST>')
    return nowdoc(text, 'PUBLIC_HANDOFF_JSON', '<PAYLOAD>').encode()


def workflow_pins(text, before, operator_blob, operator_sha, builder_blob, builder_sha, aad):
    replacements = [
        (r"(github.event.before == ')[^']+(')", before),
        (r"(operator_blob = ')[^']+(')", operator_blob),
        (r"(operator_sha = ')[^']+(')", operator_sha),
        (r"(builder_blob = ')[^']+(')", builder_blob),
        (r"(builder_sha = ')[^']+(')", builder_sha),
        (r"(assert d\['aad'\]==')[^']+(')", aad),
    ]
    for pattern, value in replacements:
        text = substitute_once(pattern, lambda match: match[1] + value + match[2], text)
    return text


def normalize_workflow(text):
    return workflow_pins(text, *(['<DERIVED>'] * 6)).encode()


def validate(manifest, raw):
    keys(manifest, ['schema', 'installed_sha', 'expected_ops_parent', 'predecessor',
                    'successor_id', 'public_payload_sha256', 'completion_policy', 'deadline'])
    require(manifest['schema'] == KIND and manifest['completion_policy'] == COMPLETION,
            'fixed_completion_policy_required')
    hash_value(manifest['installed_sha'], 40)
    hash_value(manifest['expected_ops_parent'], 40)
    predecessor = manifest['predecessor']
    keys(predecessor, ['campaign_id', 'bootstrap_sha256', 'inputs_sha256', 'runtime_sha256'])
    campaign(predecessor['campaign_id'])
    campaign(manifest['successor_id'])
    require(predecessor['campaign_id'] != manifest['successor_id'], 'new_campaign_required')
    for field in ('bootstrap_sha256', 'inputs_sha256', 'runtime_sha256'):
        hash_value(predecessor[field])
    hash_value(manifest['public_payload_sha256'])
    require(type(manifest['deadline']) is int and 0 < manifest['deadline'] < 2000000000,
            'bounded_deadline_required')
    require(0 < len(raw) <= 131072 and sha(raw) == manifest['public_payload_sha256'], 'payload_pin_required')
    public = parse(raw)
    require(canon(public) == raw, 'canonical_payload_required')
    keys(public, ['schema', 'predecessor_id', 'successor_id', 'delta', 'plan', 'policy', 'budget_attestation'])
    require(public['schema'] == 'hotel-server-public-handoff-v1'
            and public['predecessor_id'] == predecessor['campaign_id']
            and public['successor_id'] == manifest['successor_id'], 'payload_identity_required')
    delta = public['delta']
    keys(delta, ['corpus', 'criteria', 'map', 'archives', 'instructions', 'stage_contexts'])
    decoded = {}
    for name, value in delta.items():
        require(type(value) is str and 0 < len(value.encode()) <= 4194304, 'bounded_delta_required')
        decoded[name] = parse(value)
        require(canon(decoded[name]).decode() == value, 'canonical_delta_required')
    plan, policy, budget = (public[name] for name in ('plan', 'policy', 'budget_attestation'))
    keys(plan, ['limits', 'models', 'research_mode', 'stages'])
    limits = {'deadline': manifest['deadline'], 'max_calls': 8, 'max_micros': 88000000,
              'max_tokens': 8417600, 'micros_per_call': 11000000, 'tokens_per_call': 1052200}
    require(plan['limits'] == limits and all(type(value) is int for value in plan['limits'].values()),
            'fixed_budget_required')
    require(plan['models'] == {'producer': 'gpt-6.1-sol', 'reviewer': 'gpt-6.1-sol'}
            and plan['research_mode'] == 'assigned_retained_excerpts', 'fixed_profile_required')
    keys(policy, ['budget_attestation_sha256', 'input_token_ceiling', 'max_scopes',
                  'max_source_age_seconds', 'model', 'principals', 'profile_sha256',
                  'request_byte_ceiling', 'reserved_micros_minimum', 'source_mode', 'valid_until'])
    require(policy['model'] == 'gpt-6.1-sol' and policy['profile_sha256'] == PROFILE
            and policy['max_scopes'] == 4 and policy['request_byte_ceiling'] == 32768
            and policy['input_token_ceiling'] == 1000000 and policy['reserved_micros_minimum'] == 11000000
            and policy['max_source_age_seconds'] == 86400 and policy['source_mode'] == plan['research_mode']
            and policy['valid_until'] == manifest['deadline'], 'fixed_policy_required')
    require(policy['principals'] == {'producer': 'hotel-20261102-producer', 'reviewer': 'hotel-20261102-reviewer'},
            'fixed_principals_required')
    require(type(budget) is dict and budget.get('schema') == 'hotel-budget-attestation-v1'
            and budget.get('campaign_id') == manifest['successor_id']
            and budget.get('previous_campaign_budget_not_reused') is True
            and budget.get('maximum_calls') == 8 and budget.get('maximum_scopes_per_call') == 4
            and budget.get('planned_record_count') == 16 and budget.get('maximum_reserved_micros') == 88000000
            and budget.get('tokens_per_call_reservation') == 1052200 and budget.get('reserved_micros_per_call') == 11000000
            and budget.get('input_usage_guard') == 1000000 and budget.get('output_tokens_cap') == 2200
            and budget.get('valid_until') == manifest['deadline'] and budget.get('profile_sha256') == PROFILE
            and budget.get('accounting') == 'full_reserved_ceiling_not_provider_invoice'
            and type(budget.get('owner_decision')) is str and budget['owner_decision'].strip(), 'new_attestation_required')
    require(sha(canon(budget.get('profile'))) == PROFILE and sha(canon(budget)) == policy['budget_attestation_sha256'],
            'attestation_pin_required')
    contexts = decoded['stage_contexts']
    require(type(contexts) is list and len(contexts) == 16, 'sixteen_dependencies_required')
    dependencies = {}
    for context in contexts:
        keys(context, ['scope', 'dependencies'])
        deps = context['dependencies']
        keys(deps, ['corpus', 'criteria', 'map', 'archives', 'instructions'])
        require(all(type(value) is str and 0 < len(value.encode()) <= 8192 for value in deps.values()),
                'bounded_dependencies_required')
        key = sha(canon(context['scope']))
        require(key not in dependencies, 'duplicate_dependency_scope')
        dependencies[key] = deps
    require(type(plan['stages']) is list and len(plan['stages']) == 4, 'four_stages_required')
    seen = set()
    for country, stage in zip(('EG', 'TH', 'VN', 'TR'), plan['stages']):
        require(type(stage) is list and len(stage) == 4, 'four_scopes_per_stage_required')
        for scope in stage:
            keys(scope, ['country', 'hotel', 'question', 'source'])
            require(scope['country'] == country and all(type(value) is str and value for value in scope.values()),
                    'scope_identity_required')
            key = sha(canon(scope))
            require(key not in seen and key in dependencies, 'unique_prepared_scope_required')
            seen.add(key)
    require(seen == set(dependencies), 'exact_dependencies_required')
    return public


def derived(manifest, public):
    previous = manifest['predecessor']
    result = {'HOTEL_EXPECTED_INSTALLED_SHA': manifest['installed_sha'], 'HOTEL_OPERATION': sha(canon(manifest)),
              'HOTEL_PREDECESSOR': previous['campaign_id'], 'HOTEL_CAMPAIGN': manifest['successor_id'],
              'HOTEL_OLD_BOOTSTRAP': previous['bootstrap_sha256'], 'HOTEL_PREDECESSOR_INPUTS_SHA': previous['inputs_sha256'],
              'HOTEL_OLD_RUNTIME': previous['runtime_sha256'], 'HOTEL_PAYLOAD_SHA': manifest['public_payload_sha256']}
    for field, name in [('delta', 'HOTEL_DELTA_SHA'), ('plan', 'HOTEL_PLAN_SHA'),
                        ('policy', 'HOTEL_POLICY_SHA'), ('budget_attestation', 'HOTEL_BUDGET_SHA')]:
        result[name] = sha(canon(public[field]))
    return result


def reviewed_source(text):
    require(sha(normalize_php(text)) == PHP_CONTROL_SHA, 'unreviewed_operator_control_flow')


def check_source(text):
    reviewed_source(text)
    manifest_raw = read_nowdoc(text, 'LAUNCH_MANIFEST_JSON')
    require(0 < len(manifest_raw) <= 16384, 'bounded_manifest_required')
    manifest = parse(manifest_raw)
    require(canon(manifest) == manifest_raw, 'canonical_manifest_required')
    public = validate(manifest, read_nowdoc(text, 'PUBLIC_HANDOFF_JSON'))
    for name, value in derived(manifest, public).items():
        require(text.count("const " + name + "='" + value + "';") == 1, 'derived_constant_mismatch')
    require(text.count('const HOTEL_LAUNCH_DEADLINE=' + str(manifest['deadline']) + ';') == 1, 'deadline_mismatch')
    require(not re.search(r'__[A-Z0-9_]+__', text), 'unfilled_placeholder')
    return manifest


def boundary_fixture(text):
    reviewed_source(text)
    begin, end = '// BEGIN PURE TERMINAL BOUNDARY\n', '// END PURE TERMINAL BOUNDARY'
    require(text.count(begin) == 1 and text.count(end) == 1, 'pure_boundary_region_required')
    function = text.split(begin, 1)[1].split(end, 1)[0]
    return '''<?php
declare(strict_types=1);
function hotelFail(string $reason): never { throw new DomainException($reason); }
''' + function + r'''
$pins=['campaign_id'=>'BOUNDARY_PREDECESSOR','bootstrap_sha256'=>str_repeat('a',64),'inputs_sha256'=>str_repeat('b',64)];
$complete=$pins+['state'=>'complete','enabled'=>'1','stage_cursor'=>'4','new_accepted'=>'4','historical_accepted'=>'19',
    'baseline_sha256'=>str_repeat('c',64),'reserved_calls'=>'8','reserved_tokens'=>'8417600','reserved_micros'=>'88000000'];
$resolved=hotelCheckTerminalBoundary($complete,$pins,true,null);
if($resolved['baseline_sha256']!==str_repeat('c',64)||$resolved['enabled']!==1)throw new RuntimeException('complete_boundary_not_resolved');
hotelCheckTerminalBoundary($complete,$pins,true,$resolved['baseline_sha256']);
$disabled=$complete;$disabled['enabled']=0;hotelCheckTerminalBoundary($disabled,$pins,false,$resolved['baseline_sha256']);
$cases=[];
foreach(['ready','unknown','UNKNOWN','hold','disabled','reviewed'] as $state)$cases[]=['state'=>$state];
foreach(['campaign_id'=>'FOREIGN','bootstrap_sha256'=>str_repeat('d',64),'inputs_sha256'=>str_repeat('e',64),
    'baseline_sha256'=>'invalid','enabled'=>'0','stage_cursor'=>'3','new_accepted'=>'3','reserved_calls'=>'7',
    'reserved_tokens'=>'0','reserved_micros'=>'0'] as $field=>$value)$cases[]=[$field=>$value];
$rejected=0;
foreach($cases as $change){
    try{hotelCheckTerminalBoundary(array_replace($complete,$change),$pins,true,null);}
    catch(DomainException $error){if($error->getMessage()!=='predecessor_CAS')throw $error;++$rejected;continue;}
    throw new RuntimeException('unsafe_boundary_accepted');
}
try{hotelCheckTerminalBoundary(array_replace($complete,['baseline_sha256'=>str_repeat('f',64)]),$pins,true,$resolved['baseline_sha256']);}
catch(DomainException $error){if($error->getMessage()!=='predecessor_CAS')throw $error;++$rejected;}
if($rejected!==count($cases)+1)throw new RuntimeException('resolved_boundary_was_reresolved');
echo json_encode(['pure_boundary_gate'=>'PASS','rejections'=>$rejected,'provider_calls'=>0]),"\n";
'''


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument('--check-source', type=Path)
    modes.add_argument('--emit-boundary-fixture', type=Path)
    parser.add_argument('--manifest', type=Path)
    parser.add_argument('--payload', type=Path)
    parser.add_argument('--template-root', type=Path, default=Path(__file__).resolve().parents[3])
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    if args.check_source or args.emit_boundary_fixture:
        require(not any([args.manifest, args.payload, args.output]), 'exclusive_builder_mode_required')
        if args.check_source:
            manifest = check_source(args.check_source.read_text())
            print(json.dumps({'source_validation': 'PASS', 'operation_id': sha(canon(manifest)), 'execution_performed': False}))
        else:
            sys.stdout.write(boundary_fixture(args.emit_boundary_fixture.read_text()))
        return
    require(all([args.manifest, args.payload, args.output]), 'manifest_payload_output_required')
    require(not args.output.exists(), 'output_exists_no_overwrite')
    manifest = parse(args.manifest.read_bytes())
    require(len(canon(manifest)) <= 16384, 'bounded_manifest_required')
    payload = args.payload.read_bytes()
    public = validate(manifest, payload)
    source = (args.template_root / PHP).read_text()
    workflow = (args.template_root / WORKFLOW).read_text()
    reviewed_source(source)
    require(sha(normalize_workflow(workflow)) == WORKFLOW_CONTROL_SHA, 'unreviewed_workflow_control_flow')
    builder = Path(__file__).read_bytes()
    for name, value in derived(manifest, public).items():
        source = constant(source, name, value)
    source = substitute_once(r'^const HOTEL_LAUNCH_DEADLINE=[0-9]+;$',
                             'const HOTEL_LAUNCH_DEADLINE=' + str(manifest['deadline']) + ';', source)
    source = nowdoc(source, 'LAUNCH_MANIFEST_JSON', canon(manifest).decode())
    source = nowdoc(source, 'PUBLIC_HANDOFF_JSON', payload.decode())
    require(len(source.encode()) < 196608, 'operator_transport_bound')
    check_source(source)
    operation = sha(canon(manifest))
    aad = KIND + '|' + manifest['installed_sha'] + '|' + operation
    workflow = workflow_pins(workflow, manifest['expected_ops_parent'], blob(source.encode()), sha(source.encode()),
                             blob(builder), sha(builder), aad)
    require(sha(normalize_workflow(workflow)) == WORKFLOW_CONTROL_SHA, 'workflow_control_flow_changed')
    require(not re.search(r'__[A-Z0-9_]+__', workflow), 'unfilled_workflow_placeholder')
    files = {PHP: source.encode(), WORKFLOW: workflow.encode(), BUILDER: builder}
    pins = {'schema': 'hotel-server-handoff-build-v2', 'execution_performed': False,
            'installed_sha': manifest['installed_sha'], 'expected_before': manifest['expected_ops_parent'],
            'operation_id': operation, 'aad': aad, 'manifest': manifest,
            'operator_control_sha256': PHP_CONTROL_SHA, 'workflow_control_sha256': WORKFLOW_CONTROL_SHA, 'files': {}}
    args.output.mkdir(parents=True, mode=0o700)
    for relative, data in files.items():
        target = args.output / relative
        target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        with target.open('xb') as handle:
            handle.write(data)
        target.chmod(0o600)
        pins['files'][relative] = {'bytes': len(data), 'sha256': sha(data), 'git_blob': blob(data)}
    with (args.output / 'pins.json').open('xb') as handle:
        handle.write(canon(pins) + b'\n')
    print(json.dumps({'output': str(args.output), 'operation_id': operation, 'files': pins['files']}, indent=2))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, TypeError, OSError) as error:
        print('HOLD: ' + str(error), file=sys.stderr)
        sys.exit(1)
