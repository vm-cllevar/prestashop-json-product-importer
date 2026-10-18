/* Full-file transport only; validation and mutation decisions remain server-side. */
(function () {
  'use strict';
  const root = document.getElementById('jpp-jobs');
  if (!root) return;
  const find = id => root.querySelector('[data-ui="' + id + '"]');
  const output = find('status'), fileInput = find('file'), selector = find('jobs');
  let selected = null, running = false, stop = false, page = 0;
  function message(text, error) { output.textContent = text; output.className = 'alert ' + (error ? 'alert-danger' : 'alert-info'); }
  async function request(action, values) {
    const body = new FormData();
    body.set('jpp_job_action', action); body.set('preview_token', root.dataset.token);
    Object.entries(values || {}).forEach(([key, value]) => body.set(key, String(value)));
    const response = await fetch(root.dataset.endpoint, {method:'POST',body,credentials:'same-origin'});
    let result;
    try { result = JSON.parse(await response.text()); } catch (_) { throw new Error('Server did not return a job response. Check your login, then reload and resume.'); }
    if (!response.ok || !result.ok) throw new Error(result.error || 'Request failed. Reload job status before resuming.');
    return result;
  }
  function controls() {
    find('upload').disabled = running;
    selector.disabled = running;
    find('load').disabled = running;
    find('preview').disabled = running || !selected || !['ready','previewing'].includes(selected.status);
    find('apply').disabled = running || !selected || !['previewed','applying'].includes(selected.status) || +selected.updates === 0;
    find('rollback').disabled = running || !selected || !['applying','applied','rolling_back'].includes(selected.status);
    find('stop').disabled = !running;
  }
  function show(job) {
    selected = job;
    find('summary').textContent = job.filename + ' · ' + job.status + ' · uploaded ' + job.received + '/' + job.total +
      ' · previewed ' + (job.previewed || 0) + '/' + Math.ceil(job.total/100) + ' batches · ' + job.updates + ' updates, ' +
      job.unchanged + ' unchanged, ' + job.skipped + ' skipped, ' + job.invalid + ' invalid · applied ' + job.applied_products +
      ' products (' + job.applied_batches + ' batches) · rolled back ' + job.rolled_back_batches + ' batches';
    controls();
  }
  async function list() {
    const result = await request('list');
    selector.replaceChildren();
    result.jobs.forEach(j => { const option = document.createElement('option'); option.value=j.id;option.textContent=j.filename+' · '+j.status+' · '+j.created_at+' UTC';selector.append(option); });
    if (selected) selector.value=selected.id;
  }
  async function renderPage() {
    if (!selected) return;
    const result=await request('page',{job:selected.id,seq:page});show(result.job);
    find('page-label').textContent='Batch '+(page+1)+' of '+Math.ceil(selected.total/100)+' (100 records per batch)';
    find('previous').disabled=page===0;find('next').disabled=(page+1)*100>=+selected.total;
    const tbody=find('rows');tbody.replaceChildren();
    if (!result.page) { const row=tbody.insertRow();const cell=row.insertCell();cell.colSpan=3;cell.textContent='This batch has not been previewed yet.';return; }
    result.page.rows.forEach(r=>{
      const tr=tbody.insertRow();tr.insertCell().textContent=r.row+' / '+r.reference;
      const status=tr.insertCell();
      if(r.url){const a=document.createElement('a');a.href=r.url;a.target='_blank';a.rel='noopener noreferrer';a.textContent=r.status+' — Edit product';status.append(a);}else status.textContent=r.status;
      const detail=tr.insertCell();
      if(r.after){Object.entries(r.after).forEach(([field,value])=>{const line=document.createElement('div');line.style.whiteSpace='pre-wrap';line.textContent=field+': '+r.before[field]+' → '+value;detail.append(line);});}
      else detail.textContent=r.detail;
      if(r.notes.length){const notes=document.createElement('small');notes.textContent=' '+r.notes.join(' ');detail.append(notes);}
    });
  }
  async function work(fn) {
    if(running)return;running=true;stop=false;controls();
    try { await fn(); if(stop)message('Paused after the last completed request. Select this job to resume.'); }
    catch(e){message(e.message,true); if(selected){try{show((await request('status',{job:selected.id})).job);}catch(_) {}}}
    finally {running=false;controls();try{await list();}catch(_) {}}
  }
  async function process(action) {
    const terminal={preview:'previewed',apply:'applied',rollback:'rolled_back'}[action];
    while(!stop && selected.status!==terminal){
      show((await request(action,{job:selected.id})).job);
      message((action==='preview'?'Previewing; no catalog changes.':action==='apply'?'Applying reviewed changes.':'Restoring applied batches in reverse order.')+' You can pause after this request.');
    }
    if(!stop){message(action==='preview'?'Full preview complete. Review the summary and batch pages before applying.':action==='apply'?'All eligible updates applied. Rollback is available for this job.':'Rollback complete. Original recorded fields restored.');}
    await renderPage();
  }
  find('upload').addEventListener('click',()=>work(async()=>{
    const file=fileInput.files[0];if(!file)throw new Error('Choose the full JSON file.');
    if(file.size>64*1024*1024)throw new Error('Maximum file size is 64 MiB.');
    message('Reading and fingerprinting the file locally…');
    const buffer=await file.arrayBuffer();
    const hash=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',buffer))).map(x=>x.toString(16).padStart(2,'0')).join('');
    const records=JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(buffer));
    if(!Array.isArray(records)||!records.length||records.length>100000)throw new Error('Expected a JSON array with 1–100,000 records.');
    if(selected && selected.status==='uploading'){
      show((await request('status',{job:selected.id})).job);
      if(selected.file_hash!==hash || +selected.total!==records.length)throw new Error('This is a different file. Load another job or choose New job first.');
    } else show((await request('create',{filename:file.name,hash,total:records.length})).job);
    while(!stop && +selected.received<records.length){
      const offset=+selected.received;let count=Math.min(1000,records.length-offset),json;
      while(true){json=JSON.stringify(records.slice(offset,offset+count));if(new TextEncoder().encode(json).length<=900000)break;if(count<=100)throw new Error('One 100-record chunk exceeds 900 KB. Use smaller input records.');count=Math.max(100,Math.floor((count-1)/100)*100);}
      show((await request('upload',{job:selected.id,offset,rows:json})).job);
      message('Uploading '+selected.received+' / '+selected.total+' records. No catalog changes.');
    }
    if(stop)return;
    show((await request('finish',{job:selected.id})).job);await process('preview');
  }));
  find('load').addEventListener('click',()=>work(async()=>{if(!selector.value)throw new Error('No job selected.');show((await request('status',{job:selector.value})).job);page=0;await renderPage();message('Job loaded. Choose Resume preview, Apply, or Roll back as appropriate. For incomplete uploads, reselect the same file.');}));
  find('new').addEventListener('click',()=>{if(running)return;selected=null;find('summary').textContent='Choose a file to start a new job.';find('rows').replaceChildren();controls();});
  find('preview').addEventListener('click',()=>work(()=>process('preview')));
  find('apply').addEventListener('click',()=>work(()=>process('apply')));
  find('rollback').addEventListener('click',()=>work(()=>process('rollback')));
  find('stop').addEventListener('click',()=>{stop=true;message('Pausing after the current request finishes…');});
  find('previous').addEventListener('click',()=>work(async()=>{page=Math.max(0,page-1);await renderPage();}));
  find('next').addEventListener('click',()=>work(async()=>{page=Math.min(Math.ceil(selected.total/100)-1,page+1);await renderPage();}));
  controls();list().catch(e=>message(e.message,true));
}());
