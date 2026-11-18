import gzip,json,hashlib,pathlib
PROOF_SHA='ff4aaa11ce77ce015e82b40acabc19db3dd15a676b5826aefade62091bf8cb31'
def accepted877(packed,root):
 raw=(pathlib.Path(root)/'monitor-price-expansion1000-proof-20261118.json').read_bytes()
 assert hashlib.sha256(raw).hexdigest()==PROOF_SHA,'expansion1000_proof_changed'
 p=json.loads(raw);assert hashlib.sha256(packed).hexdigest()==p['roster_gzip_sha256'],'expansion1000_packed_changed'
 full=gzip.decompress(packed);rows=json.loads(full)['rows'];assert len(rows)==1000
 assert [r['tourvisor_hotel_id'] for r in rows[877:]]==[r['tourvisor_hotel_id'] for r in p['append123']]
 prefix=full[:p['baseline_roster_raw_bytes']-7]+b'\n  ]\n}\n'
 assert hashlib.sha256(prefix).hexdigest()==p['baseline_roster_raw_sha256'],'baseline877_changed'
 packed877=gzip.compress(prefix,mtime=0)
 assert hashlib.sha256(packed877).hexdigest()==p['baseline_roster_gzip_sha256'],'baseline877_packed_changed'
 return packed877
