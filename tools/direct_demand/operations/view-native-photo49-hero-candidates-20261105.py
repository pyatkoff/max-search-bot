import base64,hashlib,json,os,pathlib
root=pathlib.Path(os.environ['RUNNER_TEMP'])
items=[{"hotel_id":1421,"role":"h1421_new49_2","dir":"belvil","width":1536,"height":1023,"bytes":476305,"sha256":"07bcfbb32a8a2194e3508089316cc6b51566e014ff2cc29b1d0e227035c5d613","format":"JPEG"},{"hotel_id":1422,"role":"h1422_new49_2","dir":"zeugma","width":1536,"height":1536,"bytes":370956,"sha256":"16ce60533316c5e59be3f2e3525d0fd0f4838e075a2eaa8a6980a6d6a3e6abd1","format":"WEBP"}]
for i in items:
 folder=root/('photo49-'+i['dir'])
 files=list(folder.rglob('primary-image-inspection.json'));assert len(files)==1
 receipt=json.loads(files[0].read_text().splitlines()[-1])
 assert receipt['operation']=='commercial-batch49-bounded-primary-contact-inspection'
 rows=[r for r in receipt['images'] if r['role']==i['role'] and r['hotel_id']==i['hotel_id']];assert len(rows)==1
 row=rows[0]
 for key in ('bytes','sha256','width','height','format'):assert row[key]==i[key]
 photo=folder/row['retained_raw_file'];assert photo.is_file()
 raw=photo.read_bytes();assert len(raw)==i['bytes'] and hashlib.sha256(raw).hexdigest()==i['sha256']
 data=base64.b64encode(raw).decode();parts=[data[j:j+48000] for j in range(0,len(data),48000)]
 print(json.dumps(dict(i,kind='native_photo49',chunks=len(parts),source_gets=0,old_fixtures_rerun=0)),flush=True)
 for j,part in enumerate(parts):print(json.dumps({'kind':'native_photo49_chunk','role':i['role'],'index':j,'total':len(parts),'data':part}),flush=True)
