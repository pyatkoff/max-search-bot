#!/usr/bin/env python3
"""Upgrade only the exact public MAX lookup bundle after a checked preimage."""
import argparse
import base64
import hashlib
import json
from pathlib import Path
import stat
import subprocess

PIN = 'SHA256:WuqyxyQM6A4Sd0ZLddDBF1g/90VD2NFMdOmTjpSX2XQ'
PUBLIC_KEY_DER_SHA256 = 'b5a8227d84674149ae1384772bc3ce2e153ed67de1b9e19d6721965dc8203702'
FILES = (
    'lookup.php',
    'services/MaxTrackerLookupProtocol.php',
    'services/MaxTrackerLookupRepository.php',
    'services/MaxSubscriptionAttributionPolicy.php',
)
# Accepted PR1267 public code at a8b4c2d1126b0e38d831e0ace9d75554d6e4415b.
TARGET_MANIFEST = {'version': 1, 'files': {
    'lookup.php': '0ba2187b77adffdcfb2d00a9a3024d9d0899b93005768ff86aa565b958553e8e',
    'services/MaxTrackerLookupProtocol.php': 'c8a21dc7c29fb5903ea6104db6b5b57f33f118cc3a1735039bc783272b5b3a1b',
    'services/MaxTrackerLookupRepository.php': '07bcf389b92472bb60216d498d11856e8ea8b87ff5807a8517c71d50f4aafa49',
    'services/MaxSubscriptionAttributionPolicy.php': '2e5b35659b1aa7159b4b2b772ba0fafd1440c98bc63786ba48e3b4f9b34ef53b',
}}
TARGET_RELEASE_SHA256 = '714bb8e2a129b24b520c0abe23d646670a4a20afa34937b17eae8e046926953a'
# Canonical historical SOURCE candidate, not an assertion of installed state.
# The caller must supply this candidate explicitly; the remote guard proves it.
CHECKED_PREIMAGE = {'version': 1, 'files': {
    'lookup.php': '4d4bc139fa0252d1a95c87145c1b1583a55a7f9f8a9ca4f203d1f9e7b34eab53',
    'services/MaxTrackerLookupProtocol.php': '92c6d703395a9dd30fc3467b6ffc8ed31cf0392f6ee3671f7397ada829c213c0',
    'services/MaxTrackerLookupRepository.php': '73ec719fc4b48893b7a5b4a3c9c33a994f38e4897e75a4fece854eb9e35df310',
    'services/MaxSubscriptionAttributionPolicy.php': '2e5b35659b1aa7159b4b2b772ba0fafd1440c98bc63786ba48e3b4f9b34ef53b',
}, 'release_json_sha256': '419733c8c023feb11a977016bd7b32e682c3d4c9a089d3706744ebfb88b7b374'}


def _json(raw):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError('duplicate JSON key')
            result[key] = value
        return result
    return json.loads(raw, object_pairs_hook=unique)


def _regular_bytes(path, limit):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > limit:
        raise ValueError('regular single-link public file required')
    with path.open('rb') as handle:
        opened = handle.fileno()
        import os
        current = os.fstat(opened)
        if (info.st_dev, info.st_ino) != (current.st_dev, current.st_ino):
            raise ValueError('public file changed')
        raw = handle.read(limit + 1)
    if len(raw) != info.st_size:
        raise ValueError('public file size changed')
    return raw


def _read_preimage(path):
    preimage = _json(_regular_bytes(Path(path), 4096))
    if (not isinstance(preimage, dict) or type(preimage.get('version')) is not int
            or preimage != CHECKED_PREIMAGE):
        raise ValueError('unchecked predecessor candidate')
    return preimage


def _read_bundle(path):
    source = Path(path)
    if not stat.S_ISDIR(source.lstat().st_mode):
        raise ValueError('bundle directory required')
    bundle = source.resolve(strict=True)
    members = list(bundle.rglob('*'))
    actual = set()
    for item in members:
        info = item.lstat()
        name = str(item.relative_to(bundle))
        if stat.S_ISDIR(info.st_mode) and name == 'services':
            continue
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise ValueError('unexpected release member')
        actual.add(name)
    if actual != set(FILES) | {'release.json'}:
        raise ValueError('unexpected release file')
    raw_manifest = _regular_bytes(bundle / 'release.json', 4096)
    if (_json(raw_manifest) != TARGET_MANIFEST
            or hashlib.sha256(raw_manifest).hexdigest() != TARGET_RELEASE_SHA256):
        raise ValueError('unaccepted release manifest')
    payload = {}
    for name in FILES:
        raw = _regular_bytes(bundle / name, 262144)
        if hashlib.sha256(raw).hexdigest() != TARGET_MANIFEST['files'][name]:
            raise ValueError('release digest mismatch')
        payload[name] = base64.b64encode(raw).decode('ascii')
    payload['release.json'] = base64.b64encode(raw_manifest).decode('ascii')
    return payload


def _remote_script(payload, preimage):
    # No remote path or command argument. Only this fixed public-code upgrade.
    script = r'''<?php
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);
function refuse($reason){throw new RuntimeException($reason);}
function directory_guard($path){
    clearstatcache(true,$path);$s=lstat($path);
    if(!$s||($s['mode']&0170000)!==0040000||is_link($path)||realpath($path)!==$path)refuse('subtree_mismatch');
}
function public_bytes($path,$limit=262144){
    clearstatcache(true,$path);$s=lstat($path);
    if(!$s||($s['mode']&0170000)!==0100000||$s['nlink']!==1
        ||is_link($path)||realpath($path)!==$path||$s['size']>$limit)refuse('public_file_type');
    $h=fopen($path,'rb');if($h===false)refuse('public_file_read');
    try{
        $o=fstat($h);
        if(!$o||$o['dev']!==$s['dev']||$o['ino']!==$s['ino']
            ||($o['mode']&0170000)!==0100000||$o['nlink']!==1)refuse('public_file_changed');
        $raw=stream_get_contents($h,$limit+1);
        if(!is_string($raw)||strlen($raw)!==$s['size'])refuse('public_file_read');
        return $raw;
    }finally{fclose($h);}
}
function snapshot($site,$names){
    $parent=$site.'/new';$dest=$parent.'/max-attribution';
    foreach([$site,$parent,$dest,$dest.'/services'] as $dir)directory_guard($dir);
    $top=array_values(array_diff(scandir($dest),['.','..']));sort($top);
    if($top!==['lookup.php','release.json','services','verification.pem'])refuse('subtree_members');
    $serviceNames=array_values(array_diff(scandir($dest.'/services'),['.','..']));sort($serviceNames);
    if($serviceNames!==['MaxSubscriptionAttributionPolicy.php','MaxTrackerLookupProtocol.php','MaxTrackerLookupRepository.php'])refuse('subtree_members');
    $result=[];foreach($names as $name)$result[$name]=public_bytes($dest.'/'.$name,$name==='verification.pem'?16384:262144);
    return $result;
}
function key_guard($raw,$pin){
    if(strpos($raw,'-----BEGIN PUBLIC KEY-----')!==0||strpos($raw,'PRIVATE')!==false)refuse('public_key_type');
    $key=openssl_pkey_get_public($raw);$details=$key?openssl_pkey_get_details($key):false;
    if(!$details||$details['type']!==OPENSSL_KEYTYPE_RSA||$details['bits']<2048)refuse('public_key_type');
    $der=base64_decode(preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s/','',$details['key']),true);
    if(!is_string($der)||!hash_equals($pin,hash('sha256',$der)))refuse('public_key_pin');
}
function preimage_guard($snapshot,$expected){
    foreach($expected['files'] as $name=>$hash)
        if(!hash_equals($hash,hash('sha256',$snapshot[$name])))refuse('preimage_mismatch');
    if(!hash_equals($expected['release_json_sha256'],hash('sha256',$snapshot['release.json'])))refuse('preimage_mismatch');
}
$site='/var/www/www-root/data/www/tour-max.ru';$dest=$site.'/new/max-attribution';
$keyPin='__KEY_PIN__';$expected=json_decode('__PREIMAGE__',true,16,JSON_THROW_ON_ERROR);
$encoded=json_decode('__PAYLOAD__',true,16,JSON_THROW_ON_ERROR);
$target=json_decode('__TARGET__',true,16,JSON_THROW_ON_ERROR);
$targetRelease='__TARGET_RELEASE__';$names=array_merge(array_keys($target['files']),['release.json','verification.pem']);
$lock=null;$staged=[];$exitCode=0;
try{
    // Directory, exact member, file type, hardlink, key and every preimage hash
    // are checked before tempnam/chmod/write/rename or any other mutation.
    $before=snapshot($site,$names);key_guard($before['verification.pem'],$keyPin);
    $lock=fopen($dest.'/release.json','rb');if($lock===false||!flock($lock,LOCK_EX))refuse('install_lock');
    $before=snapshot($site,$names);key_guard($before['verification.pem'],$keyPin);preimage_guard($before,$expected);
    $lockInfo=fstat($lock);$pathInfo=lstat($dest.'/release.json');
    if(!$lockInfo||!$pathInfo||$lockInfo['dev']!==$pathInfo['dev']||$lockInfo['ino']!==$pathInfo['ino'])refuse('install_lock');
    $bytes=[];
    foreach($encoded as $name=>$value){$raw=base64_decode($value,true);if(!is_string($raw))refuse('release_payload');$bytes[$name]=$raw;}
    $hashes=$target['files'];$hashes['release.json']=$targetRelease;
    if(array_keys($bytes)!==array_keys($hashes))refuse('release_payload');
    foreach($hashes as $name=>$hash)if(!hash_equals($hash,hash('sha256',$bytes[$name])))refuse('release_payload');
    // Only authenticated public code and its public manifest enter temp files.
    foreach($bytes as $name=>$raw){
        $temp=tempnam(dirname($dest.'/'.$name),'.lookup-');if($temp===false)refuse('stage_failed');
        $staged[$name]=$temp;
        if(dirname($temp)!==dirname($dest.'/'.$name)||file_put_contents($temp,$raw)!==strlen($raw)||!chmod($temp,0644)
            ||!hash_equals($hashes[$name],hash_file('sha256',$temp)))refuse('stage_failed');
    }
    // The original release file lock serializes guarded callers. Recheck the
    // live preimage immediately before replacing files; preserve key bytes.
    foreach([$site,$site.'/new',$dest,$dest.'/services'] as $dir)directory_guard($dir);
    foreach($names as $name)
        if(public_bytes($dest.'/'.$name,$name==='verification.pem'?16384:262144)!==$before[$name])refuse('preimage_changed');
    $order=array_values(array_diff(array_keys($bytes),['lookup.php']));$order[]='lookup.php';
    foreach($order as $name){if(!rename($staged[$name],$dest.'/'.$name))refuse('install_failed');unset($staged[$name]);}
    $after=snapshot($site,$names);
    foreach($hashes as $name=>$hash)if(!hash_equals($hash,hash('sha256',$after[$name])))refuse('readback_mismatch');
    if($after['verification.pem']!==$before['verification.pem'])refuse('public_key_changed');
    key_guard($after['verification.pem'],$keyPin);
    echo json_encode(['ok'=>true,'public_files'=>5,'preserved_public_keys'=>1,'preimage_verified'=>true,'conversion_requests'=>0,'tracker_business_writes'=>0])."\n";
}catch(Throwable $error){
    echo json_encode(['ok'=>false,'error'=>$error instanceof RuntimeException?$error->getMessage():'install_failed','conversion_requests'=>0,'tracker_business_writes'=>0])."\n";
    $exitCode=2;
}finally{
    foreach($staged as $temp)if(is_file($temp))unlink($temp);
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
}
exit($exitCode);
'''
    return (script.replace('__KEY_PIN__', PUBLIC_KEY_DER_SHA256)
            .replace('__PREIMAGE__', json.dumps(preimage, separators=(',', ':')))
            .replace('__PAYLOAD__', json.dumps(payload, separators=(',', ':')))
            .replace('__TARGET__', json.dumps(TARGET_MANIFEST, separators=(',', ':')))
            .replace('__TARGET_RELEASE__', TARGET_RELEASE_SHA256))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('bundle', 'expected-preimage', 'ssh-key', 'known-hosts'):
        parser.add_argument('--' + name, required=True)
    args = parser.parse_args()
    preimage = _read_preimage(args.expected_preimage)
    payload = _read_bundle(args.bundle)
    fingerprints = subprocess.run(
        ['ssh-keygen', '-lf', args.known_hosts, '-E', 'sha256'],
        check=True, capture_output=True, text=True).stdout.splitlines()
    if not fingerprints or any(len(line.split()) < 2 or line.split()[1] != PIN for line in fingerprints):
        raise ValueError('tracker host fingerprint mismatch')
    # SSH alone consumes the caller's existing transport identity. It is never
    # read, copied, printed or transmitted by this installer as payload.
    subprocess.run([
        'ssh', '-i', args.ssh_key, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
        '-o', 'ConnectTimeout=10', '-o', 'StrictHostKeyChecking=yes',
        '-o', 'UserKnownHostsFile=' + str(Path(args.known_hosts).resolve()),
        'github-audit@158.160.185.1', 'php -d display_errors=0 -d log_errors=0'],
        input=_remote_script(payload, preimage), text=True, check=True, timeout=60)


if __name__ == '__main__':
    main()
