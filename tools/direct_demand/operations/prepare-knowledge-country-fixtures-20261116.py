"""Correct only offline fixtures after the original failed no-production preparation."""
import hashlib,importlib.util,pathlib
p=pathlib.Path(__file__).with_name('prepare-knowledge-country-scope-20261116.py')
b=p.read_bytes()
assert hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()=='b2054cac5c31be7cb29dc3fd22e2d1daa75dc8eb'
spec=importlib.util.spec_from_file_location('bounded_country_preparation',p)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
for variable in ['reference','retainedRef']:
    for field,label in [('facts','fact'),('sources','sourceRecord')]:
        old=f"${variable}['{field}']===[${label}]"
        new=f"$canonical(${variable}['{field}'])===$canonical([${label}])"
        assert m.TESTS.count(old)==1,old
        m.TESTS=m.TESTS.replace(old,new)
old="'selected_room'=>null,'provenance'"
assert m.SNAPSHOT.count(old)==1
m.SNAPSHOT=m.SNAPSHOT.replace(old,"'selected_room'=>'Synthetic operator category','provenance'")
assert m.SNAPSHOT.count('echo json_encode(')==1
m.SNAPSHOT=m.SNAPSHOT.replace('echo json_encode(',"if($available<1)throw new RuntimeException('NO_AVAILABLE_BASELINE_REFERENCES');echo json_encode(")
# No runtime gate, real fact, admission, namespace or approval is changed here.
m.main()
