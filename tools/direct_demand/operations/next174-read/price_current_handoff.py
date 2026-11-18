"""Scoped current PRICE config; preserve original historical PRICE1000 key."""
import copy,hashlib,importlib.util
VALIDATOR_SHA='f22aa7a8708f46452020cfab5cf5fa035a1c289ca1dec53701f73f70f696b460'
def validate(config,root,api):
 path=root/'current-price4294/price_terminal_4294.py'
 if hashlib.sha256(path.read_bytes()).hexdigest()!=VALIDATOR_SHA or config['source_sha256'].get('current-price4294/price_terminal_4294.py')!=VALIDATOR_SHA:raise ValueError('current_price_validator_changed')
 current=config.get('current_price_terminal_handoff')
 if type(current) is not dict:raise ValueError('current_price_handoff_missing')
 if current.get('descriptor_path')!='current-price4294/handoff-descriptor.json':raise ValueError('current_price_descriptor_scope')
 scoped=copy.deepcopy(config);scoped['price_terminal_handoff']=copy.deepcopy(current)
 spec=importlib.util.spec_from_file_location('exact_current_price4294',path);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
 result=module.validate_handoff(scoped,root,api)
 if result.get('native_execution_allowed') is not False or result.get('fresh_native_admission_required') is not True:raise ValueError('current_price_not_readonly_boundary')
 return result
