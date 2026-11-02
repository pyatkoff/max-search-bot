"""Controlled checks for the new bounded batch preparation; never runs a provider."""
import copy,hashlib,importlib.util,json,os,tempfile,unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('batch_preparer','tools/direct_demand/operations/prepare-commercial-batch.py')
p=importlib.util.module_from_spec(spec);spec.loader.exec_module(p)
TEMPLATE=Path(p.TEMPLATE_PATH).read_text()
OUT=Path(os.environ['RUNNER_TEMP'])/'commercial-batch-check';OUT.mkdir(exist_ok=True)

def fixture(n):
 rows=[]
 for i in range(1,n+1):
  text='Тестовый отель '+str(i)+'. Контролируемые данные, не для публикации.'
  rows.append({'hotel_id':i,'logical_id':'fixture-hotel-'+str(i)+'-v1','text':text,'text_sha256':hashlib.sha256(text.encode()).hexdigest(),'photo_sha256':hashlib.sha256(('fixture-photo-'+str(i)).encode()).hexdigest(),'photo_url':'https://raw.githubusercontent.com/pyatkoff/max-search-bot/'+'0'*40+'/.github/traffic-publish/fixture/hotel-'+str(i)+'.png','media_state':p.READY,'aliases':['Fixture hotel '+str(i)],'sources':['https://example.org/hotel/'+str(i)]})
 return {'operation':'commercial-hotel-batch-20261102-9-v1','state':'PREPARED_NOT_SENT','button_text':'✈️ Узнать стоимость тура','rows':rows}

def approved(m):
 raw=(json.dumps(m,ensure_ascii=False)+'\n').encode()
 return raw,{'schema_version':1,'operation':m['operation'],'expected_installed_sha':'9c5be6ba829e5083fd27f377887121d58492bf54','hotel_ids':[r['hotel_id'] for r in m['rows']],'manifest_sha256':hashlib.sha256(raw).hexdigest()}

class Checks(unittest.TestCase):
 def accepted(self,n):
  m=fixture(n);raw,spec=approved(m);script,report=p.prepare(TEMPLATE,raw,spec)
  self.assertEqual(report['expected_confirmed_posts'],2*n);self.assertEqual(report['provider_calls'],0)
  self.assertIn("count($payload['rows']??[])==="+str(n),script)
  # Apart from finite operation/count/ID/mode and the existing injected header,
  # every runtime guard, durable state transition and provider call stays identical.
  body=script.split("\n",4)[4]
  restored=p.PREFIX+body
  restored=restored.replace(m['operation'],p.TEMPLATE_OPERATION).replace("count($payload['rows']??[])==="+str(n),p.COUNT_ANCHOR).replace("in_array((int)$row['hotel_id'],"+json.dumps(spec['hotel_ids'],separators=(',',':'))+",true)",p.SCOPE_ANCHOR).replace("'mode'=>'publish_approved_commercial_batch'",p.MODE_ANCHOR)
  self.assertEqual(restored,TEMPLATE)
  (OUT/('fixture-'+str(n)+'.php')).write_text(script)
 def test_one(self):self.accepted(1)
 def test_three(self):self.accepted(3)
 def test_ten(self):self.accepted(10)
 def rejected(self,m,reason):
  raw,spec=approved(m)
  with self.assertRaisesRegex(ValueError,reason):p.prepare(TEMPLATE,raw,spec)
 def test_empty(self):self.rejected(fixture(0),'hotel_scope_invalid')
 def test_over_cap(self):self.rejected(fixture(11),'hotel_scope_invalid')
 def test_duplicate_hotel(self):
  m=fixture(2);m['rows'][1]['hotel_id']=1;self.rejected(m,'hotel_scope_invalid')
 def test_duplicate_logical(self):
  m=fixture(2);m['rows'][1]['logical_id']=m['rows'][0]['logical_id'];self.rejected(m,'logical_id_invalid_or_duplicate')
 def test_mismatched_text(self):
  m=fixture(2);m['rows'][1]['text']+=' Изменено';self.rejected(m,'text_hash_invalid_or_duplicate')
 def test_unverified_photo(self):
  m=fixture(1);m['rows'][0]['media_state']='DRAFT';self.rejected(m,'photo_not_visually_accepted')
 def test_wrong_cover_host(self):
  m=fixture(1);m['rows'][0]['photo_url']='https://example.org/photo.png';self.rejected(m,'photo_url_not_immutable_public_cover')
 def test_closed_manifest(self):
  m=fixture(1);m['state']='PUBLISHED_IN_BOTH_CHANNELS_VERIFIED';self.rejected(m,'manifest_not_approved_for_send')
 def test_changed_approval(self):
  raw,spec=approved(fixture(1));spec['manifest_sha256']='0'*64
  with self.assertRaisesRegex(ValueError,'approved_manifest_changed'):p.prepare(TEMPLATE,raw,spec)
 def test_changed_template(self):
  raw,spec=approved(fixture(1))
  with self.assertRaisesRegex(ValueError,'publisher_template_changed'):p.prepare(TEMPLATE+'\n',raw,spec)
 def test_canonical_button(self):
  m=fixture(1);m['button_text']='Другой текст';self.rejected(m,'canonical_button_changed')

if __name__=='__main__':
 suite=unittest.defaultTestLoader.loadTestsFromTestCase(Checks)
 result=unittest.TextTestRunner(verbosity=2).run(suite)
 report={'mode':'offline_batch_preparation_validation','checks':result.testsRun,'ok':result.wasSuccessful(),'max_hotels_per_send':p.MAX_BATCH,'max_channel_posts_per_send':2*p.MAX_BATCH,'provider_calls':0,'db_writes':0,'message_sends':0}
 Path('commercial-batch-preparation-validation.json').write_text(json.dumps(report,sort_keys=True)+'\n')
 print(json.dumps(report,sort_keys=True))
 raise SystemExit(0 if result.wasSuccessful() else 1)
