#!/usr/bin/env python3
"""Install only a validated public lookup bundle into the permitted tracker subtree."""
import argparse
import base64
import hashlib
import json
from pathlib import Path
import subprocess

PIN = 'SHA256:WuqyxyQM6A4Sd0ZLddDBF1g/90VD2NFMdOmTjpSX2XQ'
FILES = {'lookup.php', 'services/MaxTrackerLookupProtocol.php', 'services/MaxTrackerLookupRepository.php', 'services/MaxSubscriptionAttributionPolicy.php'}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('bundle', 'public-key', 'ssh-key', 'known-hosts'):
        parser.add_argument('--' + name, required=True)
    args = parser.parse_args()
    bundle = Path(args.bundle).resolve()
    manifest = json.loads((bundle / 'release.json').read_text())
    if manifest.get('version') != 1 or set(manifest.get('files', {})) != FILES:
        raise ValueError('invalid release manifest')
    actual = {str(p.relative_to(bundle)) for p in bundle.rglob('*') if p.is_file()}
    if actual != FILES | {'release.json'} or any(p.is_symlink() for p in bundle.rglob('*')):
        raise ValueError('unexpected release file')
    payload = {}
    for name, digest in manifest['files'].items():
        content = (bundle / name).read_bytes()
        if hashlib.sha256(content).hexdigest() != digest:
            raise ValueError('release digest mismatch')
        payload[name] = base64.b64encode(content).decode()
    payload['release.json'] = base64.b64encode((bundle / 'release.json').read_bytes()).decode()
    public = Path(args.public_key).read_bytes()
    if len(public) > 16384 or b'PRIVATE' in public or not public.startswith(b'-----BEGIN PUBLIC KEY-----'):
        raise ValueError('public verification key required')
    subprocess.run(['openssl', 'pkey', '-pubin', '-in', args.public_key, '-noout'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    payload['verification.pem'] = base64.b64encode(public).decode()
    fingerprints = subprocess.run(['ssh-keygen', '-lf', args.known_hosts, '-E', 'sha256'], check=True, capture_output=True, text=True).stdout.splitlines()
    if not fingerprints or any(line.split()[1] != PIN for line in fingerprints):
        raise ValueError('tracker host fingerprint mismatch')
    # All transmitted material is public code or the public verification key.
    script = '''<?php
    ini_set('display_errors','0');ini_set('log_errors','0');
    $site='/var/www/www-root/data/www/tour-max.ru';$parent=$site.'/new';$dest=$parent.'/max-attribution';
    if(realpath($parent)!==$parent||is_link($dest)||is_link($dest.'/services'))exit(2);
    if(!is_dir($dest)&&!mkdir($dest,0755))exit(2);
    if(!is_dir($dest.'/services')&&!mkdir($dest.'/services',0755))exit(2);
    $files=json_decode(PAYLOAD,true,16,JSON_THROW_ON_ERROR);
    // Install authenticated endpoint last. Intermediate failures are fail-closed.
    $endpoint=$files['lookup.php'];unset($files['lookup.php']);$files['lookup.php']=$endpoint;
    foreach($files as $name=>$encoded){
      $path=$dest.'/'.$name;if(is_link($path))exit(2);
      $data=base64_decode($encoded,true);if(!is_string($data))exit(2);
      $temp=tempnam(dirname($path),'.lookup-');if($temp===false)exit(2);
      if(file_put_contents($temp,$data)!==strlen($data)){unlink($temp);exit(2);}
      chmod($temp,0644);if(!rename($temp,$path)){unlink($temp);exit(2);}
    }
    foreach($files as $name=>$encoded)if(hash_file('sha256',$dest.'/'.$name)!==hash('sha256',base64_decode($encoded,true)))exit(3);
    echo json_encode(['ok'=>true,'public_files'=>count($files),'conversion_requests'=>0,'tracker_writes'=>0])."\\n";
    '''.replace('PAYLOAD', "'" + json.dumps(payload, separators=(',', ':')) + "'")
    subprocess.run(['ssh', '-i', args.ssh_key, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'ConnectTimeout=10', '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + str(Path(args.known_hosts).resolve()), 'github-audit@158.160.185.1', 'php -d display_errors=0 -d log_errors=0'], input=script, text=True, check=True, timeout=60)

if __name__ == '__main__':
    main()
