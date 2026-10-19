'use strict';

// Exact PHP OAuth endpoint/service with synthetic SQLite, a real secure browser
// session and loopback TLS. No production bootstrap, credentials or network I/O.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const https = require('node:https');
const readline = require('node:readline');
const {spawn, execFileSync} = require('node:child_process');

async function startOAuthFixture(sourceRoot) {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'oauth-consent-browser-'));
  let child, proxy, lines;
  const close = async () => {
    if (proxy) await new Promise(resolve => proxy.close(resolve));
    if (child && child.exitCode === null) {
      const exited = new Promise(resolve => child.once('close', resolve));
      child.stdin.end('\n');
      const timer = setTimeout(() => child.kill(), 3000);
      await exited;
      clearTimeout(timer);
    }
    if (lines) lines.close();
    fs.rmSync(temp, {recursive:true, force:true});
  };
  try {
    // A disposable test certificate only. No system trust/configuration changes.
    execFileSync('openssl', ['req','-x509','-newkey','rsa:2048','-nodes','-days','1',
      '-subj','/CN=127.0.0.1','-keyout',path.join(temp,'key.pem'),'-out',path.join(temp,'cert.pem')], {stdio:'ignore'});
    child = spawn('php', ['tests/run_manager_preparation_oauth_regression.php','--browser-fixture'],
      {cwd:sourceRoot, stdio:['pipe','pipe','pipe']});
    lines = readline.createInterface({input:child.stdout});
    const metadata = await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('OAuth fixture startup timed out')), 12000);
      child.once('error', error => {clearTimeout(timer); reject(error);});
      child.once('exit', () => {clearTimeout(timer); reject(new Error('OAuth fixture stopped before startup'));});
      lines.on('line', line => {
        if (!line.startsWith('OAUTH_BROWSER_FIXTURE ')) return;
        clearTimeout(timer);
        try {resolve(JSON.parse(line.slice('OAUTH_BROWSER_FIXTURE '.length)));} catch (error) {reject(error);}
      });
    });
    if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(metadata.origin)) throw new Error('Non-loopback OAuth fixture');
    proxy = https.createServer({key:fs.readFileSync(path.join(temp,'key.pem')),cert:fs.readFileSync(path.join(temp,'cert.pem'))}, (request, response) => {
      const target = new URL(request.url, metadata.origin);
      if (target.origin !== metadata.origin) {response.writeHead(400).end(); return;}
      const upstream = http.request(target, {method:request.method,headers:request.headers}, incoming => {
        response.writeHead(incoming.statusCode, incoming.headers);
        incoming.pipe(response);
      });
      upstream.on('error', () => response.writeHead(502).end());
      request.pipe(upstream);
    });
    await new Promise((resolve, reject) => {proxy.once('error',reject);proxy.listen(0,'127.0.0.1',resolve);});
    return {...metadata, origin:'https://127.0.0.1:'+proxy.address().port, close};
  } catch (error) {
    await close();
    throw error;
  }
}

module.exports = {startOAuthFixture};
