'use strict';

// Exact PHP OAuth endpoint/service with synthetic SQLite, a real secure browser
// session and loopback TLS. No production bootstrap, credentials or network I/O.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const https = require('node:https');
const net = require('node:net');
const readline = require('node:readline');
const {spawn, execFileSync} = require('node:child_process');

async function startOAuthFixture(sourceRoot, {legacyFormPolicy=false} = {}) {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'oauth-consent-browser-'));
  let child, proxy, callbackServer, networkProxy, lines;
  const callbacks=[], unexpected=[], sockets=new Set();
  const track=server=>server.on('connection',socket=>{sockets.add(socket);socket.on('close',()=>sockets.delete(socket));});
  const listen=server=>new Promise((resolve,reject)=>{server.once('error',reject);server.listen(0,'127.0.0.1',resolve);});
  const close = async () => {
    for (const socket of sockets) socket.destroy();
    for (const server of [networkProxy,callbackServer,proxy]) if (server) await new Promise(resolve => server.close(resolve));
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
    const tls={key:fs.readFileSync(path.join(temp,'key.pem')),cert:fs.readFileSync(path.join(temp,'cert.pem'))};
    proxy = track(https.createServer(tls, (request, response) => {
      const target = new URL(request.url, metadata.origin);
      if (target.origin !== metadata.origin) {response.writeHead(400).end(); return;}
      const upstream = http.request(target, {method:request.method,headers:request.headers}, incoming => {
        const headers={...incoming.headers};
        if(legacyFormPolicy&&headers['content-security-policy']){
          const canonical="form-action 'self' "+metadata.redirect_uri+';';
          if(!headers['content-security-policy'].includes(canonical)){
            unexpected.push('noncanonical CSP control input');incoming.resume();response.writeHead(502).end();return;
          }
          // Explicit negative control only: reproduce the former one-directive
          // policy while executing the identical endpoint and browser flow.
          headers['content-security-policy']=headers['content-security-policy'].replace(canonical,"form-action 'self';");
        }
        response.writeHead(incoming.statusCode, headers);
        incoming.pipe(response);
      });
      upstream.on('error', () => response.writeHead(502).end());
      request.pipe(upstream);
    }));
    await listen(proxy);
    const origin='https://127.0.0.1:'+proxy.address().port;
    callbackServer=track(https.createServer(tls,(request,response)=>{
      const url=new URL(request.url,metadata.redirect_uri);
      if(request.method!=='GET'||url.origin!=='https://chatgpt.com'||url.pathname!=='/connector_platform_oauth_redirect'){
        unexpected.push('unexpected callback request');response.writeHead(400).end();return;
      }
      callbacks.push(url);
      response.writeHead(200,{'Content-Type':'text/html','Cache-Control':'no-store'});
      response.end('<!doctype html><title>Synthetic callback</title><p>Callback intercepted locally</p>');
    }));
    await listen(callbackServer);
    // Route handlers need not see a redirected request. A deny-by-default
    // CONNECT proxy keeps even that browser path in loopback; no Internet
    // passthrough exists, and the real callback URL/CSP checks are preserved.
    networkProxy=track(http.createServer((request,response)=>{
      unexpected.push('unexpected HTTP proxy request');response.writeHead(502).end();
    }));
    networkProxy.on('connect',(request,client,head)=>{
      const port=request.url==='chatgpt.com:443'?callbackServer.address().port
        :(request.url===new URL(origin).host?proxy.address().port:null);
      if(!port){unexpected.push('blocked non-fixture CONNECT');client.destroy();return;}
      const upstream=net.connect(port,'127.0.0.1',()=>{
        client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
        if(head.length)upstream.write(head);
        client.pipe(upstream);upstream.pipe(client);
      });
      sockets.add(upstream);upstream.on('close',()=>sockets.delete(upstream));
      upstream.on('error',()=>client.destroy());client.on('error',()=>upstream.destroy());
      client.on('close',()=>upstream.destroy());
    });
    await listen(networkProxy);
    return {...metadata,origin,proxy:{server:'http://127.0.0.1:'+networkProxy.address().port,bypass:'127.0.0.1,localhost'},callbacks,unexpected,close};
  } catch (error) {
    await close();
    throw error;
  }
}

module.exports = {startOAuthFixture};
