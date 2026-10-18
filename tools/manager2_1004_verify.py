#!/usr/bin/env python3
import base64, hashlib, json, os, re, subprocess, sys, tempfile, time, zipfile
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urljoin, urlsplit, unquote
from urllib.request import Request, urlopen

REPO="pyatkoff/max-search-bot"
HEAD="940a502ddcb049cc5ef92a3278cab8941d8bbe0d"
TESTED="fbfc418164715c66e511f65026bf4bd60372f1df"
TREE="dfb71a8b9b9e0a84d0eb15206b13ef726c6f9eac"
RUN=39118506695
assert os.environ["GITHUB_REPOSITORY"]==REPO
AUX=Path.cwd()
PROOF=Path(os.environ["RUNNER_TEMP"])/"m2-1004-proof"
PROOF.mkdir(exist_ok=True)
def get(url, auth=False, maximum=2*1024*1024):
    req=Request(url,headers={"User-Agent":"AnyTour-Manager2-release-verification","Accept-Encoding":"identity"})
    if auth: req.add_unredirected_header("Authorization","Bearer "+os.environ["GH_TOKEN"])
    with urlopen(req,timeout=45) as response:
        assert response.status==200 and urlsplit(response.url).scheme=="https"
        body=response.read(maximum+1)
        assert len(body)<=maximum
        return body
def api(path):
    return json.loads(get("https://api.github.com/repos/"+REPO+"/"+path,True,8*1024*1024))
def run(words,cwd=None,env=None):
    return subprocess.check_output(words,cwd=cwd or AUX,env=env)
def clean_source(sha, ref=None):
    run(["git","fetch","origin",ref or sha])
    if ref: assert run(["git","rev-parse","FETCH_HEAD"]).decode().strip()==sha
    source=PROOF/"product-source"
    run(["git","worktree","add","--detach",str(source),sha])
    assert run(["git","rev-parse","HEAD^{tree}"],source).decode().strip()==TREE
    assert run(["git","status","--porcelain"],source)==b""
    return source
def without_credentials():
    env=os.environ.copy()
    for key in ["GH_TOKEN","GITHUB_TOKEN","NODE_OPTIONS","NODE_PATH","M2_HISTORY_BASELINE","M2_HISTORY_BASELINE_ROOT","M2_SOURCE_ROOT","M2_QA_SOURCE_ROOT","M2_LOCAL_TEST_ROOT"]:
        env.pop(key,None)
    return env

def canonical():
    for attempt in range(144):
        workflow=api("actions/runs/"+str(RUN))
        assert workflow["id"]==RUN and workflow["head_sha"]==HEAD and workflow["run_attempt"]==1
        if workflow["status"]=="completed":
            assert workflow["conclusion"]=="success",workflow["conclusion"]
            break
        if attempt%12==0:print("M2_WAIT_SOURCE "+workflow["status"],flush=True)
        time.sleep(5)
    else:raise RuntimeError("Bounded wait for exact-source evidence expired")
    matches=[a for a in api("actions/runs/"+str(RUN)+"/artifacts?per_page=100")["artifacts"]
        if a["name"]=="workspace-v2-visual-"+TESTED]
    assert len(matches)==1
    a=api("actions/artifacts/"+str(matches[0]["id"]))
    ARTIFACT=a["id"];SIZE=a["size_in_bytes"];DIGEST=a["digest"]
    assert a["name"]=="workspace-v2-visual-"+TESTED and not a["expired"]
    assert 0<SIZE<=160*1024*1024 and re.fullmatch(r"sha256:[0-9a-f]{64}",DIGEST)
    assert a["workflow_run"]["id"]==RUN and a["workflow_run"]["head_sha"]==HEAD
    assert a["workflow_run"]["repository_id"]==1342493152 and a["workflow_run"]["head_repository_id"]==1342493152
    source=clean_source(TESTED,"refs/pull/1004/merge")
    archive=PROOF/"canonical.zip"
    body=get("https://api.github.com/repos/"+REPO+"/actions/artifacts/"+str(ARTIFACT)+"/zip",True,160*1024*1024)
    assert len(body)==SIZE and "sha256:"+hashlib.sha256(body).hexdigest()==DIGEST
    archive.write_bytes(body)
    dest=PROOF/"canonical"
    subprocess.run(["python3",str(source/"tools/manager2_visual_artifact.py"),str(archive),str(dest),DIGEST],check=True)
    env=without_credentials();env.update({"GITHUB_REPOSITORY":REPO,"QA_PR_NUMBER":"1004"})
    subprocess.run(["node",str(source/"tools/verify_manager2_visual_evidence.js"),str(dest),TESTED,HEAD,
        "success","success","success",str(RUN),"1"],cwd=source,env=env,check=True)
    evidence=json.loads((dest/"visual-evidence.json").read_text())
    assert evidence["testedSha"]==TESTED and evidence["headSha"]==HEAD and str(evidence["runId"])==str(RUN)
    counts={r["browser"]:len(r["cases"]) for r in evidence["receipts"]}
    assert counts=={"chromium":645,"webkit":624},counts
    frames=[]
    for browser,width,part in [
        ("webkit",390,"notification-reauth-pending-submit-420"),("webkit",430,"notification-reauth-cold-target"),
        ("webkit",768,"notification-reauth-pending-submit-844"),("webkit",1440,"notification-reauth-retained-reply"),
        ("webkit",390,"notification-reauth-new-account"),("chromium",390,"notification-reauth-cold-target")]:
        name="history-after-"+browser+"-"+str(width)+"-"+part+".png"
        matches=list((dest/browser).rglob(name));assert len(matches)==1,name
        raw=matches[0].read_bytes();assert raw.startswith(b"\x89PNG\r\n\x1a\n") and len(raw)<512*1024
        frames.append({"name":name,"bytes":len(raw),"sha256":hashlib.sha256(raw).hexdigest()})
        encoded=base64.b64encode(raw).decode()
        parts=[encoded[i:i+12000] for i in range(0,len(encoded),12000)]
        for i,value in enumerate(parts):print("M2_FRAME "+name+" "+str(i+1)+"/"+str(len(parts))+" "+value,flush=True)
    summary={"ok":True,"aux_sha":os.environ["GITHUB_SHA"],"source_head":HEAD,"tested_sha":TESTED,
        "source_tree":TREE,"artifact_id":ARTIFACT,"artifact_size":SIZE,"artifact_digest":DIGEST,"cases":counts,
        "zip_entries":len(zipfile.ZipFile(archive).infolist()),"pngs":len(list(dest.rglob("*.png"))),"frames":frames}
    (PROOF/"canonical-summary.json").write_text(json.dumps(summary,indent=2)+"\n")
    print("M2_CANONICAL_SUMMARY "+json.dumps(summary),flush=True)

def wait_for_production():
    names={"Deploy production","Publish production diagnostics","Publish live session diagnostics",
        "Verify production MAX TLS","Inspect production MAX webhook","Inspect production Telegram webhook","Retired domain guard"}
    last=""
    for attempt in range(60):
        main=api("commits/main")
        if main["commit"]["tree"]["sha"]==TREE:
            sha=main["sha"]
            runs=api("actions/runs?head_sha="+sha+"&per_page=50")["workflow_runs"]
            selected={}
            for item in runs:
                if item["name"] in names and item["name"] not in selected:selected[item["name"]]=item
            for item in selected.values():
                if item["status"]=="completed" and item["conclusion"]!="success":raise RuntimeError("Production gate failed: "+item["name"])
            diag=api("git/ref/heads/diagnostics")["object"]["sha"]
            raw="https://raw.githubusercontent.com/"+REPO+"/"+diag+"/"
            snapshot=json.loads(get(raw+"autopilot_snapshot.json"))
            deploy=json.loads(get(raw+"deploy_status.json"));ops=json.loads(get(raw+"ops_status.json"))
            ready=set(selected)==names and all(r["conclusion"]=="success" for r in selected.values())
            ready=ready and snapshot["production"]["sha"]==sha and deploy["sha"]==sha and ops["sha"]==sha
            if ready:
                assert snapshot["migrations"]["pending"]==0 and snapshot["migrations"]["checksum_failures"]==0
                assert all(snapshot["health"][k] for k in ["manager_visibility_ok","manager_lead_detail_ok","handoff_integrity_ok","admin_project_access_ok","website_attribution_ok"])
                assert all(deploy[k]=="success" for k in ["provenance","sync","migration","smoke","diagnostics"])
                return sha,{"diagnostics_ref":diag,"snapshot":snapshot,"deploy":deploy,"ops":ops,
                    "runs":{k:{"id":v["id"],"conclusion":v["conclusion"]} for k,v in selected.items()}}
        current=main["sha"]+":"+str(main["commit"]["tree"]["sha"]==TREE)
        if current!=last:print("M2_WAIT_PRODUCTION "+current,flush=True);last=current
        time.sleep(10)
    raise RuntimeError("Bounded wait for the authorized publication expired")

def installed():
    sha,production=wait_for_production()
    source=clean_source(sha)
    capture=PROOF/"installed";capture.mkdir()
    html=get("https://app.anytoour.ru/manager2/")
    (capture/"shell.html").write_bytes(html)
    class Assets(HTMLParser):
        def __init__(self):super().__init__();self.base="https://app.anytoour.ru/manager2/";self.urls=[]
        def handle_starttag(self,tag,attrs):
            a=dict(attrs)
            if tag=="base" and a.get("href"):self.base=urljoin(self.base,a["href"])
            if tag=="script" and a.get("src"):self.urls.append(urljoin(self.base,a["src"]))
            if tag=="link" and a.get("rel")=="stylesheet" and a.get("href"):self.urls.append(urljoin(self.base,a["href"]))
    parser=Assets();parser.feed(html.decode());urls=sorted(set(parser.urls));assert 40<=len(urls)<=100
    assets={}
    def download(url):
        u=urlsplit(url);p=unquote(u.path).lstrip("/")
        assert u.scheme=="https" and u.netloc=="app.anytoour.ru" and ".." not in Path(p).parts
        assert p.startswith(("manager/assets/","manager2/assets/")) and p.endswith((".js",".css"))
        expected=run(["git","show",sha+":"+p],source);actual=get(url);assert actual==expected,p
        return p,actual,{"path":p,"bytes":len(actual),"sha256":hashlib.sha256(actual).hexdigest()}
    with ThreadPoolExecutor(max_workers=6) as pool:
        for p,body,record in pool.map(download,urls):
            assert p not in assets
            target=capture/p;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(body);assets[p]=record
    worker_path="manager/sw.js"
    worker_body=get("https://app.anytoour.ru/"+worker_path)
    assert worker_body==run(["git","show",sha+":"+worker_path],source),"installed worker bytes"
    (capture/worker_path).write_bytes(worker_body)
    worker_record={"path":worker_path,"bytes":len(worker_body),"sha256":hashlib.sha256(worker_body).hexdigest()}
    maintained={}
    for p in ["tests/visual/manager-transcript-history.spec.js","tests/visual/fixtures/personal-workspace-default.js"]:
        body=run(["git","show",sha+":"+p],source);target=capture/p
        target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(body);maintained[p]=hashlib.sha256(body).hexdigest()
    (capture/"bin").mkdir();(capture/"reads").mkdir()
    shim=capture/"bin/php"
    shim.write_text('#!/bin/sh\nset -eu\n[ "$#" = 1 ] && [ "$1" = manager2/index.php ]\nexec /usr/bin/cat "$M2_INSTALLED_HTML"\n')
    shim.chmod(0o700)
    observer=PROOF/"observe.cjs"
    observer.write_text("""const fs=require('fs'),path=require('path'),crypto=require('crypto');
const original=fs.readFileSync,root=path.resolve(process.env.M2_INSTALLED_ROOT),reads=[];
fs.readFileSync=function(file,...args){const value=original.call(this,file,...args);
if(typeof file==='string'){const relative=path.relative(root,path.resolve(file));
if(/^(?:(manager|manager2)\\/assets\\/.+\\.(js|css)|manager\\/sw\\.js)$/.test(relative)){const bytes=Buffer.isBuffer(value)?value:Buffer.from(value);
reads.push({path:relative,bytes:bytes.length,sha256:crypto.createHash('sha256').update(bytes).digest('hex')});}}return value;};
process.on('exit',()=>{if(reads.length)fs.writeFileSync(path.join(process.env.M2_INSTALLED_READS,process.pid+'.json'),JSON.stringify({reads}),{flag:'wx'});});
""")
    config=PROOF/"installed.config.cjs"
    package=str(AUX/"node_modules/@playwright/test")
    config.write_text("const {defineConfig}=require("+json.dumps(package)+");const path=require('path');module.exports=defineConfig({testDir:path.join(process.env.M2_INSTALLED_ROOT,'tests/visual'),timeout:45000,workers:2,retries:0,use:{headless:true,trace:'retain-on-failure'},projects:[{name:'chromium',use:{browserName:'chromium'}},{name:'webkit',use:{browserName:'webkit'}}]});\n")
    env=without_credentials();env.update({"M2_INSTALLED_ROOT":str(capture),"M2_INSTALLED_HTML":str(capture/"shell.html"),
        "M2_INSTALLED_READS":str(capture/"reads"),"NODE_PATH":str(AUX/"node_modules"),
        "NODE_OPTIONS":"--require "+str(observer),"PATH":str(capture/"bin")+":"+env["PATH"]})
    assert run([str(shim),"manager2/index.php"],capture,env)==html
    grep="notification reauth|notification navigation|retained (history|card)|mobile history (superseded|obsolete) Back|older transport current(401|403|404)|an old account response cannot reintroduce"
    command=["node",str(AUX/"node_modules/@playwright/test/cli.js"),"test","manager-transcript-history.spec.js",
        "--config="+str(config),"--grep",grep,"--output="+str(capture/"browser-results")]
    def execute(extra,name):
        child=env|{"PLAYWRIGHT_JSON_OUTPUT_FILE":str(capture/name)}
        result=subprocess.run(command+extra,cwd=capture,env=child,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
        (capture/(name+".log")).write_bytes(result.stdout)
        if result.returncode:print(result.stdout.decode(errors="replace")[-16000:],flush=True)
        assert result.returncode==0,str(capture/(name+".log"))
        return json.loads((capture/name).read_text())
    def cases(report,executed=False):
        found={}
        def visit(suite):
            for spec in suite.get("specs",[]):
                for test in spec.get("tests",[]):
                    key=(spec["id"],test["projectName"]);assert key not in found
                    if executed:assert spec["ok"] and test["status"]=="expected" and len(test["results"])==1 and test["results"][0]["status"]=="passed"
                    found[key]=spec["title"]
            for child in suite.get("suites",[]):visit(child)
        for suite in report["suites"]:visit(suite)
        assert not report.get("errors")
        return found
    print("M2_INSTALLED_STATIC "+json.dumps({"sha":sha,"assets":len(assets),"worker":worker_record,"public_gets":2+len(urls)}),flush=True)
    planned=cases(execute(["--list","--reporter=json"],"planned.json"));assert len(planned)==136,len(planned)
    actual=execute(["--reporter=line,json"],"report.json");executed=cases(actual,True);assert executed==planned
    stats=actual["stats"];assert stats["expected"]==136 and not any(stats[k] for k in ["skipped","flaky","unexpected"])
    reads=[]
    for p in (capture/"reads").glob("*.json"):reads.extend(json.loads(p.read_text())["reads"])
    html_reads=[r for r in reads if r["path"] in assets]
    worker_reads=[r for r in reads if r["path"]==worker_path]
    expected=assets|{worker_path:worker_record}
    assert 136*len(assets)<=len(html_reads) and len(reads)<=20000 and worker_reads
    assert {r["path"] for r in reads}==set(expected)
    for r in reads:assert r["sha256"]==expected[r["path"]]["sha256"] and r["bytes"]==expected[r["path"]]["bytes"]
    summary={"ok":True,"aux_sha":os.environ["GITHUB_SHA"],"production_sha":sha,"tree":TREE,
        "production_evidence":production,"shell_sha256":hashlib.sha256(html).hexdigest(),"public_static_gets":2+len(urls),
        "source_equal_assets":len(assets),"source_equal_worker":True,"source_equal_static_files":len(expected),"worker":worker_record,"maintained_files":maintained,"planned_cases":136,"executed_cases":136,
        "stats":stats,"actual_asset_read_returns":len(html_reads),"actual_worker_read_returns":len(worker_reads),"unique_read_assets":len(assets),
        "production_api_or_business_qa_writes":0,"real_customer_provider_os_sends":0,
        "completed":datetime.now(timezone.utc).isoformat(),"assets":list(assets.values())}
    receipt=capture/"receipt.json";receipt.write_text(json.dumps(summary,indent=2)+"\n")
    brief={k:summary[k] for k in ["ok","production_sha","tree","public_static_gets","source_equal_assets","source_equal_worker","source_equal_static_files","actual_worker_read_returns","planned_cases","executed_cases","stats","actual_asset_read_returns","completed"]}
    brief["receipt_sha256"]=hashlib.sha256(receipt.read_bytes()).hexdigest()
    print("M2_INSTALLED_SUMMARY "+json.dumps(brief),flush=True)

if __name__=="__main__":
    {"canonical":canonical,"installed":installed}[sys.argv[1]]()
