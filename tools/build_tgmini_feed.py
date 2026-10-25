#!/usr/bin/env python3
"""Build the two-file Mini App feed with public keys only; no server access."""
import argparse, hashlib, json, re
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def build(output,request_public,response_public):
    for value in [request_public,response_public]:
        if not re.fullmatch(r'[a-f0-9]{64}',value):raise ValueError('public_key_required')
    sources=['services/AdvertisingVisitContract.php','services/AdvertisingVisitJournalReader.php','services/AdvertisingVisitFeedProtocol.php']
    chunks=[]
    for name in sources:
        text=(ROOT/name).read_text()
        assert text.startswith('<?php\n')
        text=text.removeprefix('<?php\n')
        text=re.sub(r'^declare\(strict_types=1\);\n','',text)
        text=re.sub(r'^require_once [^\n]+\n','',text,flags=re.M)
        chunks.append(text)
    header="""<?php
declare(strict_types=1);
namespace AnyTourTgminiFeedV1;
use \\InvalidArgumentException;
use \\JsonException;
use \\RuntimeException;
// Private namespace compatibility for the existing PHP7.4+ Mini App.
function array_is_list(array $a): bool { return $a===[] || array_keys($a)===range(0,count($a)-1); }
function str_contains(string $s,string $n): bool { return $n==='' || strpos($s,$n)!==false; }
function str_ends_with(string $s,string $n): bool { return $n==='' || substr($s,-strlen($n))===$n; }
"""
    handler=(ROOT/'modules/tgmini-identity/feed.php.in').read_text().replace('__REQUEST_PUBLIC_KEY__',request_public).replace('__RESPONSE_PUBLIC_KEY__',response_public)
    output=Path(output);output.mkdir(parents=True,exist_ok=True)
    files={'tgmini_identity_feed.php':header+'\n'.join(chunks)+'\n'+handler,
        'tgmini_identity_capture.php':(ROOT/'modules/tgmini-identity/tgmini_identity_capture.php').read_text()}
    for name,text in files.items():(output/name).write_text(text)
    manifest={'schema_version':1,'build':'anytour-tgmini-feed-20261026-1',
        'request_public_key':request_public,'response_public_key':response_public,
        'source_sha256':{name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in sources},
        'files':{name:hashlib.sha256((output/name).read_bytes()).hexdigest() for name in files}}
    (output/'PUBLIC_BUILD.json').write_text(json.dumps(manifest,sort_keys=True,indent=2)+'\n')
    return manifest

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--request-public-key',required=True);p.add_argument('--response-public-key',required=True);p.add_argument('--output',required=True);a=p.parse_args()
    build(a.output,a.request_public_key,a.response_public_key)
    print('Two-file public-key feed package built')
