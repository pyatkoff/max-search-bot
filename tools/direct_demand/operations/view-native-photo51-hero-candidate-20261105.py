import base64,hashlib,json,os,pathlib
root=pathlib.Path(os.environ['RUNNER_TEMP'])
items=[{"hotel_id":78251,"role":"h78251_new51_1","dir":"cullinan","width":1920,"height":1152,"bytes":302335,"sha256":"81ed170f61a9d4bf569d2617ee26f7074319e00426ca202c8f110ccdb234df4e","format":"JPEG"}]
for i in items:
 folder=root/('photo51-'+i['dir'])
 files=list(folder.rglob('primary-image-inspection.json'));assert len(files)==1
 receipt=json.loads(files[0].read_text().splitlines()[-1])
 assert receipt['operation']=='commercial-batch51-bounded-primary-contact-inspection'
 rows=[r for r in receipt['images'] if r['role']==i['role'] and r['hotel_id']==i['hotel_id']];assert len(rows)==1
 row=rows[0]
 for key in ('bytes','sha256','width','height','format'):assert row[key]==i[key]
 photo=folder/row['retained_raw_file'];assert photo.is_file()
 raw=photo.read_bytes();assert len(raw)==i['bytes'] and hashlib.sha256(raw).hexdigest()==i['sha256']
 data=base64.b64encode(raw).decode();parts=[data[j:j+48000] for j in range(0,len(data),48000)]
 print(json.dumps(dict(i,kind='native_photo51',chunks=len(parts),source_gets=0,old_fixtures_rerun=0)),flush=True)
 for j,part in enumerate(parts):print(json.dumps({'kind':'native_photo51_chunk','role':i['role'],'index':j,'total':len(parts),'data':part}),flush=True)
