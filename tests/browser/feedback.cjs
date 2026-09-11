// Run with Node 22+ and Chrome/Edge; optionally set PHOTOHUB_BROWSER to its executable.
// All network responses are local fixtures. No PhotoHub database or cloud is used.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const os = require('node:os');
const {spawn} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const counts={share:0,download:0,submit:0};
const html=`<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="test"><link rel="stylesheet" href="/feedback.css"></head><body>
<section data-online-sharing><form data-sharing-form method="POST" action="/previews"><button id="share">Share Previews Online</button></form><p data-sharing-status></p><a data-sharing-result hidden></a><input data-cloud-copy-fallback hidden><button id="copy" data-copy-cloud-link="https://example.test/client">Copy Link</button></section>
<form data-feedback-form data-loading="Submitting your selections..." data-error="We couldn't submit your selections. Please check your connection and try again." action="/submit" method="POST"><button id="submit">Submit</button></form>
<form data-download-form action="/zip" method="POST"><button id="zip">Download ZIP</button></form><a data-download-link href="/photo" id="photo">Download photo</a>
<script src="/feedback.js" defer></script><script src="/online-sharing.js" defer></script></body></html>`;
const server=http.createServer((req,res)=>{
 const send=(body,status=200,type='application/json')=>{res.writeHead(status,{'Content-Type':type});res.end(typeof body==='string'?body:JSON.stringify(body));};
 const p=new URL(req.url,'http://localhost').pathname;
 if(p==='/feedback.js'||p==='/online-sharing.js')return send(fs.readFileSync(path.join(root,'public/js',p.slice(1)),'utf8'),200,'application/javascript');
 if(p==='/feedback.css')return send(fs.readFileSync(path.join(root,'public/css/feedback.css'),'utf8'),200,'text/css');
 if(p==='/previews'){counts.share++;return setTimeout(()=>send({status:'queued',message:'Uploading...',uploaded:1,total:2,continue_url:'/continue'}),180);}
 if(p==='/continue')return setTimeout(()=>send({status:'failed',message:'Upload failed. Please retry.',uploaded:1,total:2}),400);
 if(p==='/submit'){counts.submit++;return setTimeout(()=>send({message:'Selection could not be submitted.'},422),150);}
 if(p==='/zip'||p==='/photo'){counts.download++;return setTimeout(()=>send({message:'Could not prepare download.'},403),180);}
 return send(html,200,'text/html');
});
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'photohub-feedback-'));
 const executable=process.env.PHOTOHUB_BROWSER || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
 const browser=spawn(executable,['--headless','--disable-gpu','--no-first-run','--remote-debugging-port=0',`--user-data-dir=${profile}`],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 let socket;
 try{
  const endpoint=await new Promise((resolve,reject)=>{
   let output='';const timeout=setTimeout(()=>reject(new Error('Browser debugging startup timed out')),15000);
   browser.once('error',error=>{clearTimeout(timeout);reject(error);});
   browser.stderr.on('data',chunk=>{output+=chunk;const match=output.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(match){clearTimeout(timeout);resolve(match[1]);}});
  });
  socket=new WebSocket(endpoint);await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
  let id=0;const pending=new Map();
  socket.onmessage=event=>{const data=JSON.parse(event.data);if(pending.has(data.id)){const {resolve,reject}=pending.get(data.id);pending.delete(data.id);data.error?reject(new Error(JSON.stringify(data.error))):resolve(data.result);}};
  const call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const n=++id;pending.set(n,{resolve,reject});socket.send(JSON.stringify({id:n,method,params,...(sessionId?{sessionId}:{})}));});
  const {targetId}=await call('Target.createTarget',{url:'about:blank'});
  const {sessionId}=await call('Target.attachToTarget',{targetId,flatten:true});
  const evaluate=async expression=>{const result=await call('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true},sessionId);if(result.exceptionDetails)throw new Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
  await call('Page.navigate',{url:`http://127.0.0.1:${server.address().port}/`},sessionId);
  for(let i=0;i<100;i++){if(await evaluate('!!window.PhotoHubFeedback && document.readyState === "complete"'))break;await new Promise(r=>setTimeout(r,50));}
  await evaluate(`(async()=>{
   const check=(condition,message)=>{if(!condition)throw new Error(message);};
   const wait=async fn=>{for(let i=0;i<150;i++){if(fn())return;await new Promise(r=>setTimeout(r,30));}throw new Error('Timed out waiting for UI');};
   const share=document.getElementById('share'),form=share.form;
   form.requestSubmit();form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
   check(share.disabled,'Sharing button must disable');check(share.textContent.includes('Uploading previews'),'Sharing label');
   await wait(()=>document.querySelector('[role=progressbar]')?.getAttribute('aria-valuenow')==='50');
   await wait(()=>document.querySelector('[data-sharing-status]').textContent.includes('Upload failed'));
   check(!share.disabled&&share.textContent==='Share Previews Online','Sharing button restores after error');
   check(document.querySelector('.pf-toast[data-type=error]'),'Error toast');
   Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async()=>{}}});
   const copy=document.getElementById('copy');copy.click();await wait(()=>copy.textContent.startsWith('Copied') && copy.textContent.includes(String.fromCharCode(0x2713)));check(copy.disabled,'Copy guard');
   await wait(()=>copy.textContent==='Copy Link');check(!copy.disabled,'Copy restores');
   const submit=document.getElementById('submit');submit.click();check(submit.disabled,'Submit disables');await wait(()=>!submit.disabled);
   const zip=document.getElementById('zip');zip.form.requestSubmit();document.getElementById('photo').click();check(zip.disabled,'Download disables');await wait(()=>!zip.disabled);check(zip.textContent==='Download ZIP','Download restores');
   return true;
  })()`);
  assert.deepEqual(counts,{share:1,download:1,submit:1});
  await call('Emulation.setDeviceMetricsOverride',{width:375,height:800,deviceScaleFactor:1,mobile:true},sessionId);
  assert.equal(await evaluate('(()=>{const r=document.querySelector(".pf-toasts").getBoundingClientRect();return r.left>=0&&r.right<=innerWidth;})()'),true);
  console.log('PASS: sharing progress, duplicate guards, failure restoration, copied state, selection errors, download guard, mobile toast bounds.');
 }finally{
  if(socket)socket.close();browser.kill();server.closeAllConnections();server.close();
  // Profile is unique test data under the OS temporary directory. Retain it if
  // Chromium has not yet released its handles; never remove another profile.
 }
})().catch(error=>{console.error(error);server.close();process.exitCode=1;});
