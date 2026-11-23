import hashlib
import io
import json
import copy
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

sys.path.insert(0,str(Path(__file__).resolve().parent))
import artifacts
import public_read

def json_bytes(value):
    return (json.dumps(value,ensure_ascii=False,separators=(',',':'))+'\n').encode()

def edit_fixture():
    read = {'read-result.json':json_bytes({'native':'original READ'}),
            'journal-metadata.json':json_bytes({'journal_pin':{'sha256':'d'*64,'bytes':1874681123,
                'lines':11806,'collector_run_id':'44891762210','cycle_state':'started'}})}
    rows=[]; posts=[]
    for platform,target in artifacts.EDIT_TARGETS.items():
        old='Одиннадцать ресторанов. Цена 1000 ₽.'
        new='Десять ресторанов, баров и лаунжей. Цена 1000 ₽.'
        row={**target,'hotel_id':66198,'platform':platform,'version_id':target['previous_version_id'],
             'old_text':old,'new_text':new,'old_text_sha256':artifacts.sha(old.encode()),
             'new_text_sha256':artifacts.sha(new.encode()),'attachments_sha256':'a'*64,
             'button_url_sha256':'b'*64,'monitor_price_record_id':'c'*64}
        rows.append(row)
        posts.append({**target,'platform':platform,'hotel_id':66198,'state':'sent',
            'same_caption_skipped':False,'caption_correction_operation':artifacts.EDIT_OPERATION,
            'previous_text_sha256':row['old_text_sha256'],'text_sha256':row['new_text_sha256'],
            'previous_attachments_sha256':row['attachments_sha256'],
            'attachments_sha256':('e'*64 if platform=='telegram' else row['attachments_sha256']),
            'button_url_sha256':row['button_url_sha256'],'button_text':'✈️ Узнать стоимость тура',
            'monitor_price_record_id':row['monitor_price_record_id'],'body_photo_button_verified':True,
            'formatting_verified':True,'existing_message_reused':True,'existing_token_resolves_to_current_version':True})
    manifest={'operation':artifacts.EDIT_OPERATION,'expected_installed_sha':artifacts.APP,'row_count':2,
              'snapshot':{'sha256':artifacts.sha(read['read-result.json'])},'rows':rows,'button_text':'✈️ Узнать стоимость тура'}
    mraw=json_bytes(manifest); read['edit-manifest-preflight.json']=mraw
    result={'ok':True,'operation':artifacts.EDIT_OPERATION,'mode':'approved_existing_caption_quality_revision',
        'installed_sha':artifacts.APP,'manifest_sha256':artifacts.sha(mraw),'posts':posts,
        **{k:2 for k in ['post_write_attempts','caption_updates','verified_posts','preflight_existing_messages']},
        **{k:0 for k in ['new_posts_created','configuration_write_attempts','button_write_attempts','tour_search_calls','price_queries']}}
    progress=[{'progress':'existing_caption_quality_revised','platform':p,
               'publication_id':t['publication_id'],'post_url':t['post_url']} for p,t in artifacts.EDIT_TARGETS.items()]
    compile_receipt={'state':'OFFLINE_COMPILED_NOT_EXECUTED','installed_sha':artifacts.APP,
        'source_lineage':'d44ff7ff0e1137801137467f5f03e3ca32af87f6','operation':artifacts.EDIT_OPERATION,
        'approval_comment_id':7072600922,'manifest_sha256':artifacts.sha(mraw),
        'snapshot_sha256':artifacts.sha(read['read-result.json']),
        'journal_pin':json.loads(read['journal-metadata.json'])['journal_pin'],'row_count':2,'expected_edits':2}
    files={'content66198-edit/edit-manifest.json':mraw,'content66198-edit/edit-result.json':json_bytes(result),
           'content66198-edit/edit-output.jsonl':b''.join(json_bytes(v) for v in progress+[result]),
           'content66198-edit/edit-output.jsonl.exit':b'0\n','content66198-edit/compile-receipt.json':json_bytes(compile_receipt),
           'content66198-edit/public-before-edit.json':json_bytes({'ok':True,'phase':'before'})}
    for checkpoint,observed in [('credentials','2026-11-23T01:45:55.205Z'),('before_apply','2026-11-23T01:46:13.227Z')]:
        files['content66198-edit-'+checkpoint+'-admission.json']=json_bytes(
            artifacts.ORIGINAL_ADMISSION | {'checkpoint':checkpoint,'observed_at_utc':observed})
    return files,read

def edit_jobs():
    return {'total_count':1,'jobs':[{'id':artifacts.EDIT_PIN['job_id'],'run_id':artifacts.EDIT_PIN['run_id'],
        'name':'native-edit','status':'completed','conclusion':'failure','run_attempt':1,
        'head_sha':artifacts.EDIT_PIN['head_sha'],'steps':[
            {'number':number,'name':name,'status':'completed','conclusion':conclusion}
            for number,(name,conclusion) in artifacts.EDIT_STEPS.items()]}]}

def original_run(binding, conclusion):
    return {'id':binding['run_id'],'head_sha':binding['head_sha'],'run_attempt':1,
        'status':'completed','conclusion':conclusion,'event':'push','head_branch':artifacts.BRANCH,
        'path':artifacts.WORKFLOW,'actor':{'id':artifacts.OWNER},'triggering_actor':{'id':artifacts.OWNER}}

class WorkflowHelpers(unittest.TestCase):
    def test_public_telegram_exact_caption_and_button(self):
        body = '<div data-post="any_toour/677"><div class="tgme_widget_message_text">Wrong</div></div>'
        body += '<div data-post="any_toour/678"><a class="tgme_widget_message_photo_wrap"></a><div class="tgme_widget_message_text">✈️ Waldorf<br><b>Десять ресторанов, баров и лаунжей</b> &amp; море</div><a class="tgme_widget_message_inline_button" href="https://t.me/anyru_bot?start=h_66198">✈️ Узнать стоимость тура</a></div>'
        caption,buttons = public_read.telegram_caption(body)
        self.assertEqual(caption,'✈️ Waldorf\nДесять ресторанов, баров и лаунжей & море')
        self.assertEqual(buttons,[{'text':'✈️ Узнать стоимость тура','url':'https://t.me/anyru_bot?start=h_66198'}])
        for mutated in [body.replace('any_toour/678','any_toour/679'),body+body,
                        body.replace('tgme_widget_message_photo_wrap','other')]:
            with self.assertRaises(ValueError):
                public_read.telegram_caption(mutated)

    def test_max_public_decoder_and_ambiguous_payload(self):
        text = '✈️ Waldorf\nДесять ресторанов, баров и лаунжей & море'
        body = 'message:{text:'+json.dumps(text.replace('&','&amp;'),ensure_ascii=False)+',other:1}'
        self.assertEqual(public_read.max_caption(body),text)
        for value in ['',body+body,'message:{text:null}']:
            with self.assertRaises(ValueError):
                public_read.max_caption(value)

    def test_original_artifact_members_and_hashes(self):
        members = {name+'.json':json.dumps({'member':name}).encode() for name in ['read-result','read-scope','journal-metadata','capture-proof']}
        def archive(files):
            output = io.BytesIO()
            with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as z:
                for name,value in files.items():
                    z.writestr(name,value)
            return output.getvalue()
        raw = archive(members)
        binding = {'sha256':hashlib.sha256(raw).hexdigest(),'members':{n:hashlib.sha256(v).hexdigest() for n,v in members.items()}}
        self.assertEqual(artifacts.validate_archive(raw,binding),members)
        with self.assertRaises(RuntimeError):
            artifacts.validate_archive(raw+b'x',binding)
        for changed in [members | {'unapproved.json':b'{}'},members | {'read-result.json':b'{"tampered":true}'}]:
            candidate = archive(changed)
            with self.assertRaises(RuntimeError):
                artifacts.validate_archive(candidate,binding | {'sha256':hashlib.sha256(candidate).hexdigest()})

    def test_confirmed_edit_binding_is_one_original_operation(self):
        files,_=edit_fixture()
        binding=artifacts.EDIT_PIN | {'members':{n:artifacts.sha(v) for n,v in files.items()},
                                    'read_binding':artifacts.READ_PIN | {'members':dict(artifacts.READ_MEMBERS)}}
        artifacts.validate_edit_binding(binding)
        mutations=[('kind','CONTENT66198_ORIGINAL_READ'),('application_sha','0'*40),('run_id',45096362079),
                   ('run_attempt',2),('head_sha','0'*40),('job_id',137436923924),('sha256','0'*64)]
        for key,value in mutations:
            with self.subTest(key=key),self.assertRaises(RuntimeError):
                artifacts.validate_edit_binding(binding | {key:value})
        bad=copy.deepcopy(binding);bad['read_binding']['members']['read-result.json']='0'*64
        with self.assertRaises(RuntimeError): artifacts.validate_edit_binding(bad)
        bad=copy.deepcopy(binding);bad['members']['feed-result.json']='0'*64
        with self.assertRaises(RuntimeError): artifacts.validate_edit_binding(bad)

    def test_original_edit_requires_exact_failed_run_and_native_phase_boundary(self):
        binding=artifacts.EDIT_PIN; run=original_run(binding,'failure'); jobs=edit_jobs()
        artifacts.validate_run(run,binding,'failure');artifacts.validate_edit_jobs(jobs,binding)
        for key,value in [('conclusion','success'),('event','workflow_dispatch'),('run_attempt',2),
                          ('head_sha','0'*40),('head_branch','main'),('actor',{'id':1})]:
            with self.subTest(key=key),self.assertRaises(RuntimeError):
                artifacts.validate_run(run | {key:value},binding,'failure')
        for step,conclusion in [(7,'failure'),(7,'cancelled'),(8,'success'),(9,'success'),(12,'success'),(16,'failure')]:
            changed=copy.deepcopy(jobs)
            next(s for s in changed['jobs'][0]['steps'] if s['number']==step)['conclusion']=conclusion
            with self.subTest(step=step,conclusion=conclusion),self.assertRaises(RuntimeError):
                artifacts.validate_edit_jobs(changed,binding)
        changed=copy.deepcopy(jobs);changed['jobs'][0]['steps'].append(
            {'number':18,'name':'Unexpected write','status':'completed','conclusion':'success'})
        with self.assertRaises(RuntimeError):artifacts.validate_edit_jobs(changed,binding)
        changed=copy.deepcopy(jobs);changed['jobs'][0]['id']+=1
        with self.assertRaises(RuntimeError):artifacts.validate_edit_jobs(changed,binding)

    def test_original_edit_artifact_identity_cannot_cross_runs(self):
        b=artifacts.EDIT_PIN
        meta={'id':b['artifact_id'],'name':b['name'],'expired':False,'digest':'sha256:'+b['sha256'],
              'workflow_run':{'id':b['run_id'],'head_sha':b['head_sha'],'head_branch':artifacts.BRANCH}}
        artifacts.validate_artifact_meta(meta,b)
        for key,value in [('expired',True),('digest','sha256:'+'0'*64),('id',14783441649)]:
            with self.subTest(key=key),self.assertRaises(RuntimeError):artifacts.validate_artifact_meta(meta | {key:value},b)
        bad=copy.deepcopy(meta);bad['workflow_run']['id']=artifacts.READ_PIN['run_id']
        with self.assertRaises(RuntimeError):artifacts.validate_artifact_meta(bad,b)

    def test_original_edit_archive_has_only_eight_exact_paths(self):
        files,_=edit_fixture()
        def archive(values):
            output=io.BytesIO()
            with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as z:
                for name,data in values.items():z.writestr(name,data)
            return output.getvalue()
        raw=archive(files);binding={'sha256':artifacts.sha(raw),'members':{n:artifacts.sha(v) for n,v in files.items()}}
        self.assertEqual(artifacts.validate_edit_archive(raw,binding),files)
        with self.assertRaises(RuntimeError):artifacts.validate_edit_archive(raw+b'changed',binding)
        changed=dict(files);changed['content66198-edit/edit-output.jsonl.exit']=b'1\n'
        raw2=archive(changed)
        with self.assertRaises(RuntimeError):artifacts.validate_edit_archive(raw2,binding | {'sha256':artifacts.sha(raw2)})
        for path in ['content66198-feed/feed-result.json','../edit.php']:
            changed=files | {path:b'{}'};raw2=archive(changed)
            with self.subTest(path=path),self.assertRaises(RuntimeError):
                artifacts.validate_edit_archive(raw2,binding | {'sha256':artifacts.sha(raw2)})

    def test_original_edit_exit_jsonl_and_read_manifest_are_independent_witnesses(self):
        files,read=edit_fixture()
        result=artifacts.validate_edit_evidence(files,read)
        self.assertEqual([p['version_id'] for p in result['posts']],[14280,14281])
        for changed in [files | {'content66198-edit/edit-output.jsonl.exit':b'1\n'},
                        files | {'content66198-edit/edit-output.jsonl.exit':b''},
                        files | {'content66198-edit/edit-output.jsonl':files['content66198-edit/edit-output.jsonl']+b'{}\n'},
                        files | {'content66198-edit/edit-result.json':json_bytes(result | {'verified_posts':1})}]:
            with self.assertRaises(RuntimeError):artifacts.validate_edit_evidence(changed,read)
        with self.assertRaises(RuntimeError):
            artifacts.validate_edit_evidence(files,read | {'edit-manifest-preflight.json':read['edit-manifest-preflight.json']+b' '})

    def test_original_edit_receipts_reject_unknown_or_changed_preservation(self):
        files,read=edit_fixture()
        def changed_result(update):
            values=dict(files);result=json.loads(files['content66198-edit/edit-result.json']);update(result)
            values['content66198-edit/edit-result.json']=json_bytes(result)
            lines=files['content66198-edit/edit-output.jsonl'].splitlines()
            values['content66198-edit/edit-output.jsonl']=b'\n'.join(lines[:2])+b'\n'+json_bytes(result)
            return values
        for mutate in [lambda r:r.update(ok=False),lambda r:r.update(post_write_attempts=True),
                       lambda r:r.update(new_posts_created=1),
                       lambda r:r['posts'][0].update(state='unknown'),
                       lambda r:r['posts'][0].update(version_id=14279),
                       lambda r:r['posts'][0].update(body_photo_button_verified=False),
                       lambda r:r['posts'][0].update(existing_token_resolves_to_current_version=False),
                       lambda r:r['posts'][0].update(button_url_sha256='f'*64),
                       lambda r:r['posts'][1].update(attachments_sha256='f'*64)]:
            with self.assertRaises(RuntimeError):artifacts.validate_edit_evidence(changed_result(mutate),read)
        bad=dict(files);name='content66198-edit-before_apply-admission.json'
        bad[name]=json_bytes(json.loads(bad[name]) | {'record_digest':'0'*64})
        with self.assertRaises(RuntimeError):artifacts.validate_edit_evidence(bad,read)

    def test_existing_read_consumer_still_rejects_failed_run(self):
        binding=artifacts.READ_PIN | {'members':dict(artifacts.READ_MEMBERS)}
        with tempfile.TemporaryDirectory() as directory,patch.object(artifacts,'api',return_value=original_run(binding,'failure')):
            out=Path(directory)/'read'
            with self.assertRaises(RuntimeError):artifacts.consume(binding,out)
            self.assertFalse(out.exists())

if __name__ == '__main__':
    unittest.main()
