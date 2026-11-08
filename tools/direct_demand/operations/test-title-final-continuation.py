#!/usr/bin/env python3
"""Offline continuation regression against authenticated original receipt fixtures."""
import argparse, copy, json, os
from pathlib import Path
import runpy


def run(private, ops, config):
    helper = runpy.run_path(str(ops/'prepare-title-continuation.py'))
    builder, bulk = helper['modules'](ops)
    inputs = {key:bulk['archive_members']((private/(key+'.zip')).read_bytes()) for key in
        ('prepared','canary','prior','fresh','continuation_prepared','continuation_result','final_fresh')}
    packet, proof = helper['derive_final'](inputs, config, builder, bulk)
    assert proof['completed_before_run'] == 882 and proof['bulk_posts'] == 34 and proof['write_batch_indices'] == [45,46]
    prior = inputs['continuation_prepared']
    index = json.loads(prior['manifests/plan-index.json'])
    for number, entry in enumerate(index['manifests']):
        name = 'manifests/'+entry['file']
        if number < 45:
            assert packet[name] == prior[name]
        else:
            assert json.loads(packet[name])['rows'] == json.loads(prior[name])['rows']
    pending = json.loads(prior['manifests/'+index['manifests'][45]['file']])['rows'][0]['publication_id']
    completed = json.loads(inputs['continuation_result']['batch044.result.safe.json'])['posts'][0]['publication_id']

    def reject(name, mutate):
        data, cfg = copy.deepcopy(inputs), copy.deepcopy(config)
        mutate(data, cfg)
        try:
            helper['derive_final'](data, cfg, builder, bulk)
        except ValueError:
            return
        raise AssertionError('unsafe_continuation_accepted:'+name)

    def change_result(data, key, value):
        name = 'batch044.result.safe.json'; row = json.loads(data['continuation_result'][name])
        row[key] = value; data['continuation_result'][name] = helper['encoded'](row)

    def change_fresh(data, cfg, mutate):
        row = json.loads(data['final_fresh']['progress-readback.json']); mutate(row)
        raw = helper['encoded'](row); data['final_fresh']['progress-readback.json'] = raw
        cfg['final_fresh']['json_sha256'] = helper['sha'](raw)

    def change_post(data, cfg, pid, key, value):
        def mutate(snapshot):
            post = next(p for p in snapshot['posts'] if p['publication_id'] == pid)
            post[key] = value
        change_fresh(data, cfg, mutate)

    reject('already_dispatched_45', lambda d,c: d['continuation_result'].update({'batch045.started.safe.json':b'{}'}))
    reject('unknown_previous_outcome', lambda d,c: change_result(d,'validated',False))
    reject('hidden_provider_write', lambda d,c: change_result(d,'reported_new_posts_created',1))
    reject('missing_unused_operation', lambda d,c: change_fresh(d,c,lambda s:s.update(unstarted_operation_directories_absent=[45])))
    reject('changed_pending_photo', lambda d,c: change_post(d,c,pending,'photo_signature','max:changed'))
    reject('changed_completed_version', lambda d,c: change_post(d,c,completed,'version_id',999999))
    reject('changed_pending_version', lambda d,c: change_post(d,c,pending,'version_id',999999))
    reject('changed_current_source', lambda d,c: c.update(current_source='a'*40))
    reject('rewritten_prior_manifest', lambda d,c: d['continuation_prepared'].update({'manifests/'+index['manifests'][44]['file']:b'{}'}))
    print(json.dumps({'ok':True,'positive_fixture':1,'unsafe_rejections':9,'historical_manifest_bytes_preserved':45,
        'original_untouched_write_batches':[45,46],'post_write_attempts':0,'provider_calls':0}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--private-dir',type=Path,required=True)
    parser.add_argument('--config',type=Path)
    args = parser.parse_args(); ops = Path(__file__).parent
    helper = runpy.run_path(str(ops/'prepare-title-continuation.py'))
    config = json.loads(args.config.read_text()) if args.config else helper['configuration']()
    run(args.private_dir,ops,config)
