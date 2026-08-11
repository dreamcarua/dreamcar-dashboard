/* ============================================================
   Фінанси → Імпорт виписок (для Давида, без доступу в Касу)
   Запит Вадима, 11.08.2026.
   Формати: Excel/CSV (SheetJS) + PDF (pdf.js). Автодетект ОТП/ПУМБ
   за заголовками; для інших банків — ручний маппер колонок.
   Заливка через рольову RPC kasa_import_statement (ceo/coo/cfo),
   завжди excl_pnl=true (не впливає на P&L). Дедуп по № документа.
   ============================================================ */
(function () {
  if (window.__finImport) return; window.__finImport = true;

  var S = { accounts: [], account: null, rows: [], bank: null, stmtBalance: null, grid: null, headerRow: null };

  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
  function uah(n){ return Number(n||0).toLocaleString('uk-UA',{maximumFractionDigits:2})+' ₴'; }
  async function client(){ if (typeof getSb==='function') return await getSb(); return window.supabase; }

  // ---------- РЕНДЕР ----------
  async function renderImport(){
    var host=document.getElementById('pane-import-body') || document.getElementById('pane-import'); if(!host) return;
    host.innerHTML =
      '<div class="tbl-card" style="padding:14px;margin-bottom:14px">'+
        '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">'+
          '<div class="field" style="margin:0;min-width:220px"><label>Рахунок (куди залити)</label><select id="impAcc"><option value="">— завантаження…—</option></select></div>'+
          '<div class="field" style="margin:0;flex:1;min-width:240px"><label>Файл виписки (.xls / .xlsx / .csv / .pdf)</label><input id="impFileF" type="file" accept=".xls,.xlsx,.csv,.pdf"></div>'+
        '</div>'+
        '<div id="impMsg" style="margin-top:8px;font-size:12.5px;color:var(--muted,#9aa)"></div>'+
        '<div class="note" style="margin-top:8px;font-size:12px">Банківські виписки не впливають на P&amp;L (це позиція грошей). Дублі відсіюються по номеру документа — можна вантажити з перекриттям періодів.</div>'+
      '</div>'+
      '<div id="impMapWrap"></div>'+
      '<div id="impSummary"></div>'+
      '<div class="tbl-card" style="padding:12px"><b style="font-family:Oswald">Баланси ФОП-рахунків</b><div id="impBalances" style="margin-top:10px">…</div></div>';
    document.getElementById('impFileF').addEventListener('change', onFile);
    await loadBalances();
  }

  async function loadBalances(){
    try{
      var sb=await client();
      var r=await sb.rpc('kasa_fop_balances');
      if(r.error) throw r.error;
      var arr=r.data||[];
      S.accounts=arr;
      var sel=document.getElementById('impAcc');
      if(sel) sel.innerHTML='<option value="">— оберіть рахунок —</option>'+arr.map(function(a){return '<option value="'+a.id+'">'+esc(a.name)+' ('+esc(a.bank||'')+')</option>';}).join('');
      var bh=document.getElementById('impBalances');
      if(bh) bh.innerHTML = arr.length ? '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px">'+
        arr.map(function(a){return '<div class="kpi"><div class="label">'+esc(a.name)+'</div><div class="value" style="font-size:18px">'+uah(a.balance)+'</div><div class="sub">'+(a.n||0)+' оп · '+(a.last_op||'—')+'</div></div>';}).join('')+'</div>'
        : '<span class="muted">Немає банківських рахунків.</span>';
    }catch(e){ var m=document.getElementById('impMsg'); if(m) m.textContent='Не вдалось завантажити рахунки: '+(e.message||e)+' (потрібна роль ceo/coo/cfo).'; }
  }

  function msg(t,warn){ var m=document.getElementById('impMsg'); if(m){ m.textContent=t; m.style.color=warn?'#e0a458':'var(--muted,#9aa)'; } }

  // ---------- ФАЙЛ ----------
  function onFile(e){
    var f=e.target.files[0]; if(!f) return;
    document.getElementById('impMapWrap').innerHTML=''; document.getElementById('impSummary').innerHTML='';
    S.rows=[]; S.bank=null; S.stmtBalance=null;
    var ext=(f.name.split('.').pop()||'').toLowerCase();
    msg('Читаю файл…');
    var reader=new FileReader();
    reader.onload=function(){
      try{
        if(ext==='pdf') parsePDF(new Uint8Array(reader.result));
        else parseSheet(reader.result, ext);
      }catch(err){ msg('Помилка читання: '+(err.message||err), true); console.error(err); }
    };
    if(ext==='pdf') reader.readAsArrayBuffer(f); else reader.readAsArrayBuffer(f);
  }

  // ---------- EXCEL / CSV ----------
  function parseSheet(ab, ext){
    if(typeof XLSX==='undefined'){ msg('SheetJS не завантажився — онови сторінку', true); return; }
    var wb=XLSX.read(new Uint8Array(ab), {type:'array'});
    var sh=wb.Sheets[wb.SheetNames[0]];
    var grid=XLSX.utils.sheet_to_json(sh,{header:1,raw:false,defval:''});
    S.grid=grid;
    // шукаємо рядок заголовків
    var hi=grid.findIndex(function(r){ return r.some(function(c){return /Операці|Призначення|Дебет|Кредит|Сума/i.test(String(c));}); });
    if(hi<0) hi=0;
    S.headerRow=hi;
    var head=grid[hi].map(function(c){return String(c).trim();});
    // Автодетект ОТП (корпоративна виписка)
    if(head.some(function(h){return /Операці/i.test(h);}) && head.some(function(h){return /Призначення/i.test(h);})){
      parseOTP(grid, head, hi); return;
    }
    // Інакше — універсальний маппер
    genericMapper(grid, head, hi);
  }

  function col(head, re){ for(var i=0;i<head.length;i++) if(re.test(head[i])) return i; return -1; }

  function parseOTP(grid, head, hi){
    S.bank='ОТП';
    var cOp=col(head,/Операці/i), cSum=col(head,/^Сума$/i)||col(head,/Сума/i), cDate=col(head,/Дата проведення/i),
        cTime=col(head,/Час/i), cDesc=col(head,/Призначення/i), cCp=col(head,/контрагент/i), cDoc=col(head,/Номер документа/i);
    var rows=[];
    for(var r=hi+1;r<grid.length;r++){
      var row=grid[r]; var op=String(row[cOp]||'').trim(); var ds=String(row[cDate]||'').trim();
      if(!/Кредит|Дебет/.test(op) || !ds) continue;
      var amt=parseFloat(String(row[cSum]).replace(/\s/g,'').replace(',','.')); if(!(amt>0)) continue;
      var d=toISO(ds); var tm=String(row[cTime]||'').trim()||'00:00';
      rows.push({dir: op==='Кредит'?'in':'out', amt:amt, dt:d, ts:d+'T'+tm+':00+03:00',
                 de:String(row[cDesc]||'').slice(0,400), cp:String(row[cCp]||'').slice(0,200), ex:String(row[cDoc]||'').slice(0,120)});
    }
    finish(rows);
  }

  function toISO(s){ s=String(s).trim(); var m=s.match(/(\d{2})[.\/](\d{2})[.\/](\d{4})/); if(m) return m[3]+'-'+m[2]+'-'+m[1];
    var m2=s.match(/(\d{4})-(\d{2})-(\d{2})/); if(m2) return m2[0]; return s; }

  // ---------- УНІВЕРСАЛЬНИЙ МАППЕР ----------
  function genericMapper(grid, head, hi){
    S.bank='інший'; S.headerRow=hi;
    var opts=head.map(function(h,i){return '<option value="'+i+'">'+esc(h||('колонка '+i))+'</option>';}).join('');
    document.getElementById('impMapWrap').innerHTML=
      '<div class="tbl-card" style="padding:12px;margin-bottom:14px"><b>Зістав колонки</b>'+
      '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin-top:10px">'+
        fld('mDate','Дата',opts)+fld('mAmt','Сума',opts)+fld('mDir','Знак/напрямок (опц.)','<option value="">— за знаком суми —</option>'+opts)+
        fld('mDesc','Опис (опц.)','<option value="">—</option>'+opts)+fld('mCp','Контрагент (опц.)','<option value="">—</option>'+opts)+fld('mDoc','№ док (опц.)','<option value="">—</option>'+opts)+
      '</div><button class="btn" style="margin-top:10px" onclick="finImportApplyMap()">Розпарсити</button></div>';
  }
  function fld(id,label,opts){ return '<div class="field" style="margin:0"><label>'+label+'</label><select id="'+id+'">'+opts+'</select></div>'; }
  window.finImportApplyMap=function(){
    var grid=S.grid, hi=S.headerRow;
    var cD=+val('mDate'), cA=+val('mAmt'), cDir=val('mDir'), cDe=val('mDesc'), cCp=val('mCp'), cDoc=val('mDoc');
    var rows=[];
    for(var r=hi+1;r<grid.length;r++){
      var row=grid[r]; var ds=String(row[cD]||'').trim(); if(!ds) continue;
      var raw=String(row[cA]||'').replace(/\s/g,'').replace(',','.'); var amt=parseFloat(raw); if(isNaN(amt)||amt===0) continue;
      var dir = cDir!=='' ? (parseFloat(String(row[+cDir]).replace(',','.'))<0||/деб|out|-/i.test(String(row[+cDir]))?'out':'in') : (amt<0?'out':'in');
      var d=toISO(ds);
      rows.push({dir:dir, amt:Math.abs(amt), dt:d, ts:d+'T12:00:00+03:00',
        de: cDe!==''?String(row[+cDe]||'').slice(0,400):'', cp: cCp!==''?String(row[+cCp]||'').slice(0,200):'',
        ex: cDoc!==''?String(row[+cDoc]||'').slice(0,120):(d+'-'+Math.abs(amt)+'-'+r)});
    }
    finish(rows);
  };
  function val(id){ var e=document.getElementById(id); return e?e.value:''; }

  // ---------- PDF (pdf.js) ----------
  async function parsePDF(bytes){
    if(typeof pdfjsLib==='undefined'){ msg('pdf.js не завантажився — онови сторінку', true); return; }
    if(pdfjsLib.GlobalWorkerOptions && !pdfjsLib.GlobalWorkerOptions.workerSrc)
      pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
    msg('Читаю PDF…');
    var pdf=await pdfjsLib.getDocument({data:bytes}).promise;
    var allItems=[]; var fulltext='';
    for(var p=1;p<=pdf.numPages;p++){
      var page=await pdf.getPage(p); var tc=await page.getTextContent();
      var items=tc.items.map(function(it){ return {s:it.str, x:it.transform[4], y:it.transform[5], w:it.width}; });
      allItems.push(items); fulltext+=items.map(function(i){return i.s;}).join(' ')+'\n';
    }
    // Вихідне сальдо для звірки (беремо ОСТАННЮ суму у рядку — це кредит-баланс)
    var mb=fulltext.match(/Вих[іiі]дне сальдо[^\n\r]*/);
    if(mb){ var nums=mb[0].match(/[\d ]+[.,]\d{2}/g); if(nums&&nums.length) S.stmtBalance=parseFloat(nums[nums.length-1].replace(/\s/g,'').replace(',','.')); }
    if(/ПЕРШИЙ УКРАЇНСЬКИЙ|ПУМБ/i.test(fulltext)) parsePUMB(allItems);
    else { msg('PDF: автоформат не розпізнано. Поки підтримується ПУМБ. Для інших банків — вивантаж Excel/CSV.', true); }
  }

  // ПУМБ PDF: pdf.js округлює y інакше, ніж pdfplumber → НЕ групуємо по y-бакетах.
  // Прив'язуємось до токена дати й збираємо сусідні токени з допуском ±4 по y.
  // Сума = єдиний токен вигляду \d…[.,]dd (коди/IBAN не мають 2 десяткових). Напрямок: x1<300 = дебет.
  function parsePUMB(pages){
    S.bank='ПУМБ';
    var AMT=/^\d[\d ]*[.,]\d{2}$/, DATE=/^\d{2}\.\d{2}\.\d{4}$/;
    var out=[], seen={};
    for(var pi=0; pi<pages.length; pi++){
      var items=pages[pi].map(function(i){return {s:String(i.s).trim(), x:i.x, x1:i.x+i.w, y:i.y};}).filter(function(i){return i.s!=='';});
      var dates=items.filter(function(i){return i.x<95 && DATE.test(i.s);}).sort(function(a,b){return b.y-a.y;}); // зверху вниз
      for(var di=0; di<dates.length; di++){
        var d=dates[di], yNext=(di+1<dates.length)?dates[di+1].y:-1e9;
        var row=items.filter(function(i){return Math.abs(i.y-d.y)<4 && i.x>d.x;});
        var am=row.find(function(w){return AMT.test(w.s);});
        if(!am) continue;
        var amt=parseFloat(am.s.replace(/\s/g,'').replace(',','.')); if(!(amt>0)) continue;
        var dir=am.x1<300?'out':'in';
        var doc=row.filter(function(w){return w.x>120 && w.x<220;}).sort(function(a,b){return a.x-b.x;}).map(function(w){return w.s;}).join('');
        var block=items.filter(function(i){return i.y < d.y-4 && i.y > yNext+3;}); // рядки-продовження між датами
        var cpTok=row.concat(block).filter(function(w){return w.x>655 && w.x<775 && !/^\d+$/.test(w.s);}).sort(function(a,b){return (b.y-a.y)||(a.x-b.x);});
        var cp=cpTok.map(function(w){return w.s;}).join(' ');
        var desc=block.filter(function(w){return w.x<640 && !DATE.test(w.s) && !AMT.test(w.s);}).sort(function(a,b){return (b.y-a.y)||(a.x-b.x);}).map(function(w){return w.s;}).join(' ');
        var dt=toISO(d.s); var ex=doc||('pumb-'+dt+'-'+pi+'-'+di); if(seen[ex]) ex=ex+'-'+di; seen[ex]=1;
        out.push({dir:dir, amt:amt, dt:dt, ts:dt+'T12:00:00+03:00', de:desc.slice(0,400), cp:cp.trim().slice(0,200), ex:ex.slice(0,120)});
      }
    }
    finish(out);
  }

  // ---------- ЗВЕДЕННЯ + ІМПОРТ ----------
  function finish(rows){
    S.rows=rows;
    if(!rows.length){ msg('Не знайшов жодної операції у файлі.', true); return; }
    var si=0,so=0,dates=[];
    rows.forEach(function(r){ if(r.dir==='in')si+=r.amt; else so+=r.amt; if(r.dt)dates.push(r.dt); });
    dates.sort(); var bal=Math.round((si-so)*100)/100;
    var mism = (S.stmtBalance!=null) && Math.abs(bal - S.stmtBalance) > 0.01;
    msg('Розпізнано: '+S.bank+' · '+rows.length+' операцій.');
    document.getElementById('impSummary').innerHTML=
      '<div class="tbl-card" style="padding:14px;margin-bottom:14px">'+
        '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">'+
          '<div class="kpi"><div class="label">Операцій</div><div class="value" style="font-size:20px">'+rows.length+'</div></div>'+
          '<div class="kpi pos"><div class="label">Надходження</div><div class="value" style="font-size:18px">'+uah(si)+'</div></div>'+
          '<div class="kpi"><div class="label">Витрати</div><div class="value" style="font-size:18px">'+uah(so)+'</div></div>'+
          '<div class="kpi"><div class="label">Баланс (in−out)</div><div class="value" style="font-size:18px">'+uah(bal)+'</div></div>'+
        '</div>'+
        '<div style="margin-top:8px;font-size:12.5px">Період: '+(dates[0]||'?')+' → '+(dates[dates.length-1]||'?')+
          (S.stmtBalance!=null ? ' · Вихідне сальдо у файлі: <b>'+uah(S.stmtBalance)+'</b> '+(mism?'<span style="color:#e05555">⚠ РОЗБІЖНІСТЬ — перевір формат</span>':'<span style="color:#3fb950">✓ збіг</span>') : '')+'</div>'+
        '<div style="margin-top:10px;overflow:auto"><table style="width:100%;font-size:12px"><thead><tr><th style="text-align:left">Дата</th><th style="text-align:left">Опис</th><th style="text-align:left">Контрагент</th><th style="text-align:right">Сума</th></tr></thead><tbody>'+
          rows.slice(0,6).map(function(r){return '<tr><td>'+esc(r.dt)+'</td><td style="text-align:left">'+esc((r.de||'').slice(0,50))+'</td><td>'+esc((r.cp||'').slice(0,26))+'</td><td style="text-align:right;color:'+(r.dir==='in'?'#3fb950':'#e0a458')+'">'+(r.dir==='in'?'+':'−')+uah(r.amt)+'</td></tr>';}).join('')+
          '</tbody></table><div class="muted" style="font-size:11px;margin-top:4px">…показано 6 з '+rows.length+'</div></div>'+
        '<button class="btn" style="margin-top:12px" onclick="finImportDo()">Залити '+rows.length+' операцій у Касу</button>'+
        (mism?' <span style="color:#e05555;font-size:12px">рекомендую спершу розібратись з розбіжністю</span>':'')+
      '</div>';
  }

  window.finImportDo=async function(){
    var acc=document.getElementById('impAcc').value;
    if(!acc){ msg('Спершу обери рахунок', true); return; }
    if(!S.rows.length){ msg('Немає що заливати', true); return; }
    if(!confirm('Залити '+S.rows.length+' операцій у обраний рахунок? (не впливає на P&L, дублі відсіються)')) return;
    try{
      msg('Заливаю…');
      var sb=await client();
      var src = S.bank==='ОТП'?'otp_import':(S.bank==='ПУМБ'?'pumb_import':'import');
      var r=await sb.rpc('kasa_import_statement',{p_account:acc, p_rows:S.rows, p_source:src, p_excl:true});
      if(r.error) throw r.error;
      var res=r.data||{};
      msg('Готово: залито '+(res.inserted||0)+', пропущено дублів '+(res.skipped||0)+'.');
      document.getElementById('impSummary').innerHTML='<div class="tbl-card" style="padding:14px"><b style="color:#3fb950">✓ Залито '+(res.inserted||0)+' операцій</b>'+((res.skipped||0)?' · пропущено '+res.skipped+' дублів':'')+'</div>';
      document.getElementById('impFileF').value=''; S.rows=[];
      await loadBalances();
    }catch(e){ msg('Не залилось: '+(e.message||e), true); console.error(e); }
  };

  window.renderImport=renderImport;
})();
