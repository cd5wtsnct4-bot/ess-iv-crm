/* ESS IV CRM — front-end application. Talks to api.php; all records live in MySQL. */
'use strict';
/* ============ store: PHP + MySQL API ============ */
const S={bookings:new Map(),rooms:new Map(),tickets:new Map(),schedules:new Map()};
const COLLS=Object.keys(S);
const APP=window.APP||{};
const IS_ADMIN=APP.user?.role==='admin';
const PERMS=APP.user?.perms||{};
/** Access per area: 'none' | 'view' | 'edit'. The server enforces the same rules. */
const CAN=(area,level='view')=>level==='edit'?PERMS[area]==='edit':!!PERMS[area]&&PERMS[area]!=='none';
const TAB_AREA={cal:'reservations',res:'reservations',desk:'reservations',rooms:'rooms',maint:'maintenance'};
let store=null, loaded=false, lastSig='';

async function api(r,body){
  let res;
  try{
    res=await fetch('api.php?r='+encodeURIComponent(r),{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',
      headers:body?{'Content-Type':'application/json','X-CSRF-Token':APP.csrf||''}:{},body:body?JSON.stringify(body):undefined});
  }catch(e){throw new Error('Cannot reach the server. Check your internet connection and try again.')}
  if(res.status===401){location.href='login.php';throw new Error('Your session has ended.')}
  let j=null;try{j=await res.json()}catch(e){}
  if(!res.ok||!j)throw new Error(j?.error||'The server returned an error ('+res.status+').');
  return j;
}
async function refresh(){
  const j=await api('state');
  const sig=JSON.stringify(j);
  if(loaded&&sig===lastSig)return;
  lastSig=sig;
  for(const c of COLLS)S[c]=new Map(Object.entries(j[c]||{}));
  loaded=true;scheduleRender();
}
function apiStore(){
  const write=async(c,id,data,mode)=>{
    try{
      const j=await api('save',{coll:c,id,data,mode});S[c].set(id,j.record);
      for(const[ac,recs]of Object.entries(j.also||{}))for(const[aid,ar]of Object.entries(recs))S[ac]?.set(aid,ar); // rooms the server changed as a knock-on
      lastSig='';scheduleRender();return j.record}
    catch(e){toast(e.message);throw e}
  };
  return{
    mode:'api',
    set:(c,id,d)=>write(c,id,d,'set'),
    update:(c,id,d)=>write(c,id,d,'update'),
    async del(c,id){
      try{await api('delete',{coll:c,id});if(c==='rooms')S.rooms.set(id,{service:'in',hk:'Clean'});else S[c].delete(id);lastSig='';scheduleRender()}
      catch(e){toast(e.message);throw e}
    },
  };
}

/* ============ derived state ============ */
function bookingsArr(){return [...S.bookings.entries()].map(([id,b])=>({id,...b}))}
function roomState(r,date=TODAY){
  const o=S.rooms.get(r.id)||{};
  const offline=(o.service==='oos'||o.service==='maint')&&(!o.since||o.since<=date);
  if(offline)return{key:o.service,label:o.service==='oos'?'Out of service':'Under maintenance',cls:o.service==='oos'?'s-oos':'s-mnt',o};
  let inHouse=null,arriving=null;
  for(const b of IDX.byRoom.get(r.id)||[]){
    if(b.status==='Checked-in')inHouse=b;
    else if((b.status==='Confirmed'||b.status==='Provisional')&&b.checkIn===date)arriving=b;
  }
  if(inHouse){const due=inHouse.checkOut<=date;return{key:'occ',label:inHouse.checkOut<date?'Occupied · overdue out':due?'Occupied · due out':'Occupied',cls:'s-occ',b:inHouse,o}}
  if(arriving)return{key:'arr',label:'Arriving today',cls:'s-arr',b:arriving,o};
  if(o.hk==='Dirty')return{key:'dirty',label:'Vacant · dirty',cls:'s-dirty',o};
  return{key:'vac',label:'Vacant · clean',cls:'s-vac',o};
}
let IDX={byRoom:new Map()};
function reindex(){
  const byRoom=new Map();
  for(const b of bookingsArr()){if(!byRoom.has(b.roomId))byRoom.set(b.roomId,[]);byRoom.get(b.roomId).push(b)}
  IDX={byRoom};
}
function isOffline(roomId,start,end){
  const o=S.rooms.get(roomId);if(!o||(o.service!=='oos'&&o.service!=='maint'))return false;
  const ret=o.expectedReturn||'9999-12-31';const since=o.since||'0000-01-01';
  return since<end&&ret>start;
}
function conflicts(roomId,ci,co,exceptId){
  return (IDX.byRoom.get(roomId)||[]).filter(b=>b.id!==exceptId&&ACTIVE.has(b.status)&&b.checkIn<co&&b.checkOut>ci);
}
function availableRooms(ci,co,type,exceptId){
  return ROOMS.filter(r=>(!type||r.type===type)&&!isOffline(r.id,ci,co)&&conflicts(r.id,ci,co,exceptId).length===0);
}
function occupancyOn(date){
  let occ=0,off=0;
  for(const r of ROOMS){if(isOffline(r.id,date,addDays(date,1)))off++}
  for(const b of S.bookings.values()){if(ACTIVE.has(b.status)&&b.checkIn<=date&&b.checkOut>date)occ++}
  const sellable=ROOMS.length-off;return{occ,off,sellable,pct:sellable?occ/sellable*100:0};
}
function ticketDue(t){return t.dueDate||addDays(t.reportedAt||TODAY,PRIO_DAYS[t.priority]||7)}
function ticketOpen(t){return t.status!=='Completed'&&t.status!=='Verified'}
function computeAlerts(){
  const A={overdueOut:[],dueOut:[],arrToday:[],missed:[],upArr:[],upDep:[],maintOverdue:[],backToday:[],pmDue:[]};
  for(const b of bookingsArr()){
    if(b.status==='Checked-in'){
      if(b.checkOut<TODAY)A.overdueOut.push(b);else if(b.checkOut===TODAY)A.dueOut.push(b);
      else if(diff(TODAY,b.checkOut)<=7)A.upDep.push(b);
    }else if(b.status==='Confirmed'||b.status==='Provisional'){
      if(b.checkIn<TODAY)A.missed.push(b);else if(b.checkIn===TODAY)A.arrToday.push(b);
      else if(diff(TODAY,b.checkIn)<=7)A.upArr.push(b);
    }
  }
  for(const[id,t]of S.tickets)if(ticketOpen(t)&&ticketDue(t)<TODAY)A.maintOverdue.push({id,...t});
  for(const[id,o]of S.rooms)if((o.service==='oos'||o.service==='maint')&&o.expectedReturn&&o.expectedReturn<=TODAY)A.backToday.push({id,...o});
  for(const[id,s]of S.schedules){const nd=addDays(s.lastDone||TODAY,+s.frequency||30);if(diff(TODAY,nd)<=7)A.pmDue.push({id,...s,nextDue:nd})}
  const by=k=>(a,b)=>a[k]<b[k]?-1:a[k]>b[k]?1:0;
  A.upArr.sort(by('checkIn'));A.upDep.sort(by('checkOut'));A.overdueOut.sort(by('checkOut'));A.missed.sort(by('checkIn'));
  return A;
}

/* ============ UI helpers ============ */
const $=s=>document.querySelector(s);
const view=$('#view'),dlg=$('#dlg');
let tab='dash';
const ui={calStart:addDays(TODAY,-2),calDays:21,calBlock:'A',calFloor:'all',calType:'',resQ:'',resStatus:'active',resRange:'all',roomBlock:'all',roomStatus:'',roomType:'',mStatus:'open',mPrio:''};
try{const t=localStorage.getItem('ess4-tab');if(t)tab=t}catch(e){}
function toast(msg){const t=$('#toast');t.textContent=msg;t.hidden=false;clearTimeout(toast.h);toast.h=setTimeout(()=>t.hidden=true,2600)}
function statusPill(s){const m={'Provisional':'s-muted','Confirmed':'s-arr','Checked-in':'s-occ','Checked-out':'s-vac','Cancelled':'s-oos','No-show':'s-oos'};return `<span class="pill ${m[s]||''}">${esc(s)}</span>`}
function tStatusPill(s){const m={'Open':'s-oos','Assigned':'s-arr','In progress':'s-occ','Awaiting parts':'s-dirty','Completed':'s-vac','Verified':'s-vac'};return `<span class="pill ${m[s]||''}">${esc(s)}</span>`}
const opts=(arr,sel,blank)=>(blank!=null?`<option value="">${blank}</option>`:'')+arr.map(v=>{const[val,lab]=Array.isArray(v)?v:[v,v];return `<option value="${esc(val)}"${String(val)===String(sel??'')?' selected':''}>${esc(lab)}</option>`}).join('');

document.getElementById('tabs').addEventListener('click',e=>{const b=e.target.closest('button[data-tab]');if(!b)return;tab=b.dataset.tab;try{localStorage.setItem('ess4-tab',tab)}catch(e){}render()});
$('#newBookingBtn').onclick=()=>openBooking();
$('#todayLbl').textContent=fmt(TODAY)+' '+parse(TODAY).getFullYear();

/* ============ render ============ */
let rq=0;function scheduleRender(){if(rq)return;rq=requestAnimationFrame(()=>{rq=0;render()})}
function render(){
  if(!store)return;
  reindex();
  if(TAB_AREA[tab]&&!CAN(TAB_AREA[tab]))tab='dash';
  for(const b of document.querySelectorAll('#tabs button')){b.hidden=!!TAB_AREA[b.dataset.tab]&&!CAN(TAB_AREA[b.dataset.tab]);b.setAttribute('aria-selected',b.dataset.tab===tab)}
  $('#newBookingBtn').hidden=!CAN('reservations','edit');
  const A=computeAlerts();
  const urgent=A.overdueOut.length+A.dueOut.length+A.arrToday.length+A.missed.length+A.maintOverdue.length;
  const c=$('#alertCount');c.hidden=!urgent;c.textContent=urgent;
  const scrollX=document.querySelector('.tape')?.scrollLeft, scrollY=document.querySelector('.tape')?.scrollTop;
  view.innerHTML=({dash:renderDash,cal:renderCal,res:renderRes,desk:renderDesk,rooms:renderRooms,maint:renderMaint})[tab](A);
  const tp=document.querySelector('.tape');if(tp&&scrollX!=null){tp.scrollLeft=scrollX;tp.scrollTop=scrollY}
  bindView();
}
function emptyBanner(){
  if(!loaded||S.bookings.size||S.tickets.size||S.schedules.size)return '';
  return `<div class="banner"><p><b>No records yet.</b> Add your first reservation with <b>New reservation</b>.${IS_ADMIN?' To try the system first, load a set of example bookings, room statuses and maintenance tickets. Example records are tagged and can be cleared in one click.':''}</p>${IS_ADMIN?'<button class="btn primary" data-act="loadDemo">Load example data</button>':''}</div>`;
}
function exampleBanner(){
  const has=[...S.bookings.values()].some(b=>b.example);
  if(!has)return '';
  return `<div class="banner"><p>The system contains example records. ${IS_ADMIN?'Clear them before going live.':'An administrator can clear them.'}</p>${IS_ADMIN?'<button class="btn" data-act="clearDemo">Clear example data</button>':''}</div>`;
}

/* ---- Dashboard ---- */
function alertRow(color,title,detail,actions=''){return `<div class="alert"><span class="bar" style="background:${color}"></span><div style="min-width:0"><div class="t">${title}</div><div class="d">${detail}</div></div><div style="display:flex;gap:6px;padding-right:2px">${actions}</div></div>`}
function bookingAlert(b,color,dateLbl,act){
  return alertRow(color,`${esc(b.guestName)} <span class="mono">· ${esc(b.roomId)}</span>`,`${dateLbl} · ${esc(b.company||b.guestType||'')} · ${esc(b.ref||'')}`,
    (act?`<button class="btn sm primary" data-act="${act}" data-id="${b.id}">${act==='checkin'?'Check in':'Check out'}</button>`:'')+`<button class="btn sm" data-act="openBooking" data-id="${b.id}">Open</button>`);
}
function renderAlerts(A,compact){
  const g=[];
  const grp=(title,items,fn,max=6)=>{if(!items.length)return;g.push(`<div class="alert-group"><h3>${title} · ${items.length}</h3>${items.slice(0,compact?max:999).map(fn).join('')}${compact&&items.length>max?`<div class="d" style="color:var(--muted);font-size:12px">+${items.length-max} more in Check-in / Check-out</div>`:''}</div>`)};
  grp('Overdue check-outs',A.overdueOut,b=>bookingAlert(b,'var(--oos)','Was due out '+fmt(b.checkOut)+' ('+rel(b.checkOut)+')','checkout'));
  grp('Missed arrivals (possible no-show)',A.missed,b=>bookingAlert(b,'var(--oos)','Was due in '+fmt(b.checkIn)+' ('+rel(b.checkIn)+')','checkin'));
  grp('Check-outs due today',A.dueOut,b=>bookingAlert(b,'var(--dirty)','Departure '+fmt(b.checkOut)+' by '+(b.departureTime||'10:00'),'checkout'));
  grp('Check-ins due today',A.arrToday,b=>bookingAlert(b,'var(--arr)','Arrival '+fmt(b.checkIn)+' ~'+(b.arrivalTime||'14:00'),'checkin'));
  grp('Upcoming check-ins (next 7 days)',A.upArr,b=>bookingAlert(b,'var(--arr)','Arrives '+fmt(b.checkIn)+' ('+rel(b.checkIn)+') · '+diff(b.checkIn,b.checkOut)+' nights'),4);
  grp('Upcoming check-outs (next 7 days)',A.upDep,b=>bookingAlert(b,'var(--occ)','Departs '+fmt(b.checkOut)+' ('+rel(b.checkOut)+')'),4);
  grp('Overdue maintenance',A.maintOverdue,t=>alertRow(PRIOS[t.priority],`${esc(t.title)} <span class="mono">· ${esc(t.roomId)}</span>`,`${esc(t.priority)} · due ${fmt(ticketDue(t))} (${rel(ticketDue(t))}) · ${esc(t.assignedTo||'Unassigned')}`,`<button class="btn sm" data-act="openTicket" data-id="${t.id}">Open</button>`));
  grp('Rooms due back in service',A.backToday,o=>alertRow('var(--mnt)',`Room <span class="mono">${esc(o.id)}</span> · ${esc(o.reason||'')}`,`Expected back ${fmt(o.expectedReturn)} (${rel(o.expectedReturn)})`,`<button class="btn sm" data-act="openRoom" data-id="${o.id}">Review</button>`));
  grp('Preventive maintenance due',A.pmDue,s=>alertRow('var(--dirty)',esc(s.task),`${esc(s.scope)} · due ${fmt(s.nextDue)} (${rel(s.nextDue)})`,`<button class="btn sm" data-act="pmDone" data-id="${s.id}">Mark done</button>`));
  return g.length?g.join(''):`<div class="empty">No alerts. Arrivals, departures and maintenance deadlines will appear here.</div>`;
}
function renderDash(A){
  const counts={occ:0,vac:0,dirty:0,arr:0,oos:0,maint:0};
  const byBlock={};for(const b of BLOCKS)byBlock[b]={occ:0,vac:0,dirty:0,arr:0,oos:0,maint:0};
  for(const r of ROOMS){const s=roomState(r);counts[s.key]++;byBlock[r.block][s.key]++}
  const occ=occupancyOn(TODAY);
  const days=[];for(let i=0;i<14;i++){const d=addDays(TODAY,i);days.push({d,...occupancyOn(d)})}
  const openT=[...S.tickets.values()].filter(ticketOpen).length;
  const kp=(v,l,c)=>`<div class="kpi" style="--k:${c}"><div class="v">${v}</div><div class="l">${l}</div></div>`;
  const segs=[['occ','var(--occ)','Occupied'],['arr','var(--arr)','Arriving'],['vac','var(--vac)','Vacant clean'],['dirty','var(--dirty)','Vacant dirty'],['maint','var(--mnt)','Maintenance'],['oos','var(--oos)','Out of service']];
  return `${emptyBanner()}${exampleBanner()}
  <div class="kpis">
    ${kp(occ.pct.toFixed(1)+'%','Occupancy tonight ('+occ.occ+' / '+occ.sellable+' sellable)','var(--accent)')}
    ${kp(counts.occ,'Occupied rooms','var(--occ)')}
    ${kp(counts.vac+counts.dirty,'Vacant ('+counts.vac+' ready, '+counts.dirty+' dirty)','var(--vac)')}
    ${kp(A.arrToday.length,'Check-ins due today','var(--arr)')}
    ${kp(A.dueOut.length+A.overdueOut.length,'Check-outs due ('+A.overdueOut.length+' overdue)','var(--dirty)')}
    ${kp(counts.maint,'Under maintenance','var(--mnt)')}
    ${kp(counts.oos,'Out of service','var(--oos)')}
    ${kp(openT,'Open maintenance tickets','var(--mnt)')}
  </div>
  <div class="two">
    <section class="panel"><div class="panel-head"><h2 class="grow">Alerts</h2><button class="btn sm" data-goto="desk">Open front desk</button></div><div class="alerts">${renderAlerts(A,true)}</div></section>
    <div class="grid">
      <section class="panel"><div class="panel-head"><h2 class="grow">Occupancy forecast</h2><span class="chip">next 14 nights</span></div>
        <div class="bars">${[25,50,75].map(p=>`<div class="gridline" style="bottom:${p}%"><span style="position:absolute;right:0;top:-12px">${p}%</span></div>`).join('')}
        ${days.map((x,i)=>`<div class="b${i===0?' today':''}" style="height:${Math.max(1,x.pct)}%" title="${fmt(x.d)}: ${x.occ} rooms, ${x.pct.toFixed(1)}%"><span>${Math.round(x.pct)}</span></div>`).join('')}</div>
        <div class="barlabels">${days.map(x=>`<span>${parse(x.d).getDate()}</span>`).join('')}</div>
      </section>
      <section class="panel"><div class="panel-head"><h2 class="grow">Room status by block</h2></div>
        <div class="blockbars">${BLOCKS.map(b=>{const t=byBlock[b];return `<div class="blockrow"><span class="mono">Block ${b}</span><div class="stack">${segs.map(([k,c])=>`<i style="width:${t[k]}%;background:${c}" title="${k}: ${t[k]}"></i>`).join('')}</div><span class="mono" style="text-align:right">${t.occ}/100</span></div>`}).join('')}</div>
        <div class="legend" style="margin-top:12px">${segs.map(([k,c,l])=>`<span><b style="background:${c}"></b>${l} ${counts[k]}</span>`).join('')}</div>
      </section>
    </div>
  </div>`;
}

/* ---- Calendar (tape chart) ---- */
const W=40;
function renderCal(){
  const start=ui.calStart,N=ui.calDays,end=addDays(start,N);
  const rooms=ROOMS.filter(r=>(ui.calBlock==='all'||r.block===ui.calBlock)&&(ui.calFloor==='all'||r.floor===+ui.calFloor)&&(!ui.calType||r.type===ui.calType));
  const head=[];for(let i=0;i<N;i++){const d=addDays(start,i),dt=parse(d),wk=dt.getDay()===0||dt.getDay()===6;head.push(`<div class="dayhead${wk?' wk':''}${d===TODAY?' td':''}" style="left:${i*W}px;width:${W}px"><span>${DOW[dt.getDay()]}</span><b>${dt.getDate()}</b></div>`)}
  const ti=diff(start,TODAY);
  const rows=rooms.map(r=>{
    const bars=[];
    for(const b of IDX.byRoom.get(r.id)||[]){
      if(b.status==='Cancelled'||b.status==='No-show')continue;
      if(!(b.checkIn<end&&b.checkOut>start))continue;
      const l=Math.max(-0.5,diff(start,b.checkIn))*W+W/2, rgt=Math.min(N+0.5,diff(start,b.checkOut))*W+W/2;
      const cls={'Provisional':'s-muted','Confirmed':'s-arr','Checked-in':'s-occ','Checked-out':'s-vac'}[b.status];
      bars.push(`<div class="bk ${cls}" data-bk="${b.id}" style="left:${Math.max(0,l)}px;width:${Math.max(14,Math.min(N*W,rgt)-Math.max(0,l)-2)}px" title="${esc(b.guestName)} · ${fmt(b.checkIn)} → ${fmt(b.checkOut)} · ${b.status}">${esc(b.guestName)}</div>`);
    }
    const o=S.rooms.get(r.id);
    if(o&&(o.service==='oos'||o.service==='maint')){
      const s=o.since||start,e=o.expectedReturn||end;
      if(s<end&&e>start){const l=Math.max(0,diff(start,s))*W,rr=Math.min(N,diff(start,e))*W;bars.push(`<div class="bk ${o.service==='oos'?'oosbar':'mntbar'}" style="left:${l}px;width:${Math.max(14,rr-l-2)}px" title="${esc(o.reason||'')}">${o.service==='oos'?'OOS':'Maint'}: ${esc(o.reason||'')}</div>`)}
    }
    return `<div class="trow"><div class="rlabel">${r.id} <small>${r.type}</small></div><div class="track" data-room="${r.id}" style="width:${N*W}px;background:repeating-linear-gradient(90deg,transparent 0 ${W-1}px,var(--line) ${W-1}px ${W}px)">${ti>=0&&ti<N?`<div class="todayline" style="left:${ti*W+W/2}px"></div>`:''}${bars.join('')}</div></div>`;
  }).join('');
  const occN=rooms.filter(r=>(IDX.byRoom.get(r.id)||[]).some(b=>b.status==='Checked-in')).length;
  return `<section class="panel"><div class="panel-head"><h2 class="grow">Booking calendar</h2>
    <div class="filters">
      <button class="btn sm" data-cal="-7">◀ Week</button><button class="btn sm" data-cal="today">Today</button><button class="btn sm" data-cal="7">Week ▶</button>
      <input type="date" id="calStart" value="${start}" aria-label="Start date">
      <select id="calDays" aria-label="Days shown">${opts([[14,'14 days'],[21,'21 days'],[31,'31 days'],[62,'62 days']],N)}</select>
      <select id="calBlock" aria-label="Block">${opts([['all','All blocks'],...BLOCKS.map(b=>[b,'Block '+b])],ui.calBlock)}</select>
      <select id="calFloor" aria-label="Floor">${opts([['all','All floors'],...Array.from({length:FLOORS},(_,i)=>[i+1,'Floor '+(i+1)])],ui.calFloor)}</select>
      <select id="calType" aria-label="Room type">${opts(Object.keys(TYPES),ui.calType,'All types')}</select>
    </div></div>
    <div class="summary" style="margin-bottom:10px"><span>Showing <b>${rooms.length}</b> rooms</span><span>In-house now <b>${occN}</b></span><span>Click an empty day to book that room · click a bar to open the reservation</span></div>
    <div class="legend" style="margin-bottom:10px"><span><b style="background:var(--muted)"></b>Provisional</span><span><b style="background:var(--arr)"></b>Confirmed</span><span><b style="background:var(--occ)"></b>Checked-in</span><span><b style="background:var(--vac)"></b>Checked-out</span><span><b style="background:var(--mnt)"></b>Maintenance</span><span><b style="background:var(--oos)"></b>Out of service</span></div>
    <div class="tape"><div class="tape-inner"><div class="trow head"><div class="rlabel" style="font-family:var(--font-body);font-weight:600">Room</div><div class="track" style="width:${N*W}px;cursor:default">${head.join('')}</div></div>${rows||'<div class="empty">No rooms match these filters.</div>'}</div></div>
  </section>`;
}

/* ---- Reservations ---- */
function renderRes(){
  const q=ui.resQ.toLowerCase();
  let list=bookingsArr().filter(b=>{
    if(ui.resStatus==='active'&&!ACTIVE.has(b.status))return false;
    if(ui.resStatus!=='active'&&ui.resStatus!=='all'&&b.status!==ui.resStatus)return false;
    if(ui.resRange==='today'&&!(b.checkIn<=TODAY&&b.checkOut>=TODAY))return false;
    if(ui.resRange==='7'&&!(b.checkIn<=addDays(TODAY,7)&&b.checkOut>=TODAY))return false;
    if(q&&!(`${b.guestName} ${b.ref} ${b.roomId} ${b.company} ${b.idNumber} ${b.phone} ${b.email}`.toLowerCase().includes(q)))return false;
    return true;
  }).sort((a,b)=>a.checkIn<b.checkIn?-1:1);
  const total=list.length;list=list.slice(0,300);
  return `<section class="panel"><div class="panel-head"><h2 class="grow">Reservations</h2>
    <div class="filters"><input type="search" id="resQ" placeholder="Search guest, ref, room, company, ID…" value="${esc(ui.resQ)}" style="min-width:220px">
    <select id="resStatus">${opts([['active','Active (provisional, confirmed, in-house)'],['all','All statuses'],...BOOKING_STATUSES],ui.resStatus)}</select>
    <select id="resRange">${opts([['all','Any dates'],['today','In-house / touching today'],['7','Next 7 days']],ui.resRange)}</select>
    <button class="btn primary" data-act="newBooking">New reservation</button></div></div>
    <div class="summary" style="margin-bottom:8px"><span><b>${total}</b> reservations${total>300?' (showing first 300)':''}</span></div>
    <div class="tablewrap"><table><thead><tr><th>Ref</th><th>Guest</th><th>Company / type</th><th>Room</th><th>Check-in</th><th>Check-out</th><th>Nights</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody>
    ${list.map(b=>`<tr class="clickable" data-open="${b.id}"><td class="mono">${esc(b.ref)}</td><td><b>${esc(b.guestName)}</b><div style="color:var(--muted);font-size:12px">${esc(b.phone||'')}</div></td><td>${esc(b.company||'')}<div style="color:var(--muted);font-size:12px">${esc(b.guestType||'')}</div></td><td class="mono">${esc(b.roomId)}<div style="color:var(--muted);font-size:11px">${esc(ROOM_BY_ID[b.roomId]?.type||'')}</div></td><td class="mono">${fmt(b.checkIn)}</td><td class="mono">${fmt(b.checkOut)}</td><td class="num">${diff(b.checkIn,b.checkOut)}</td><td class="num">${money(b.total)}</td><td>${statusPill(b.status)}</td>
    <td>${b.status==='Confirmed'||b.status==='Provisional'?`<button class="btn sm" data-act="checkin" data-id="${b.id}">Check in</button>`:b.status==='Checked-in'?`<button class="btn sm" data-act="checkout" data-id="${b.id}">Check out</button>`:''}</td></tr>`).join('')}
    </tbody></table>${list.length?'':'<div class="empty">No reservations match. Try "All statuses" or clear the search.</div>'}</div></section>`;
}

/* ---- Front desk ---- */
function renderDesk(A){
  const tbl=(title,items,dateKey,act,empty,color)=>`<section class="panel"><div class="panel-head"><h2 class="grow">${title}</h2><span class="pill" style="background:var(--surface-2);color:${color}">${items.length}</span></div>
    ${items.length?`<div class="tablewrap"><table><thead><tr><th>Guest</th><th>Room</th><th>${dateKey==='checkIn'?'Arrival':'Departure'}</th><th>When</th><th>Nights</th><th>Balance</th><th></th></tr></thead><tbody>
    ${items.map(b=>`<tr class="clickable" data-open="${b.id}"><td><b>${esc(b.guestName)}</b><div style="color:var(--muted);font-size:12px">${esc(b.company||'')} · ${esc(b.ref)}</div></td><td class="mono">${esc(b.roomId)}</td><td class="mono">${fmt(b[dateKey])} ${dateKey==='checkIn'?esc(b.arrivalTime||''):esc(b.departureTime||'')}</td><td>${rel(b[dateKey])}</td><td class="num">${diff(b.checkIn,b.checkOut)}</td><td class="num">${money((+b.total||0)-(+b.deposit||0))}</td><td>${act?`<button class="btn sm primary" data-act="${act}" data-id="${b.id}">${act==='checkin'?'Check in':'Check out'}</button>`:''}</td></tr>`).join('')}</tbody></table></div>`:`<div class="empty">${empty}</div>`}</section>`;
  return `<div class="two">
    <div class="grid">${tbl('Overdue check-outs',A.overdueOut,'checkOut','checkout','No overdue departures.','var(--oos)')}${tbl('Check-outs due today',A.dueOut,'checkOut','checkout','No departures due today.','var(--dirty)')}${tbl('Upcoming check-outs · 7 days',A.upDep,'checkOut','checkout','None in the next 7 days.','var(--occ)')}</div>
    <div class="grid">${tbl('Missed arrivals',A.missed,'checkIn','checkin','No missed arrivals.','var(--oos)')}${tbl('Check-ins due today',A.arrToday,'checkIn','checkin','No arrivals due today.','var(--arr)')}${tbl('Upcoming check-ins · 7 days',A.upArr,'checkIn','checkin','None in the next 7 days.','var(--arr)')}</div>
  </div>`;
}

/* ---- Rooms ---- */
function renderRooms(){
  const states=ROOMS.map(r=>({r,s:roomState(r)}));
  const counts={};for(const{s}of states)counts[s.key]=(counts[s.key]||0)+1;
  const blocks=ui.roomBlock==='all'?BLOCKS:[ui.roomBlock];
  const chips=[['','All',ROOMS.length],['occ','Occupied',counts.occ||0],['arr','Arriving',counts.arr||0],['vac','Vacant clean',counts.vac||0],['dirty','Vacant dirty',counts.dirty||0],['maint','Maintenance',counts.maint||0],['oos','Out of service',counts.oos||0]];
  const offline=states.filter(x=>x.s.key==='oos'||x.s.key==='maint');
  return `<section class="panel"><div class="panel-head"><h2 class="grow">Room status</h2><div class="filters">
    <select id="roomBlock">${opts([['all','All blocks'],...BLOCKS.map(b=>[b,'Block '+b])],ui.roomBlock)}</select>
    <select id="roomType">${opts(Object.keys(TYPES),ui.roomType,'All types')}</select>
    <button class="btn" data-act="findRoom">Check availability</button></div></div>
    <div class="filters" style="margin-bottom:12px">${chips.map(([k,l,n])=>`<button class="btn sm${ui.roomStatus===k?' primary':''}" data-rs="${k}">${l} · ${n}</button>`).join('')}</div>
    ${blocks.map(b=>`<h3 style="margin:14px 0 6px">Block ${b}</h3><div class="floors">${Array.from({length:FLOORS},(_,i)=>FLOORS-i).map(f=>`<div class="floor"><span class="fl">F${f}</span>${states.filter(x=>x.r.block===b&&x.r.floor===f).map(({r,s})=>{const dim=(ui.roomStatus&&s.key!==ui.roomStatus)||(ui.roomType&&r.type!==ui.roomType);return `<button class="tile ${s.cls}${dim?' dim':''}" data-room="${r.id}" title="${r.id} · ${r.type} · ${s.label}${s.o?.reason?' · '+esc(s.o.reason):''}${s.b?' · '+esc(s.b.guestName):''}">${r.id}<small>${s.b?esc(s.b.guestName.split(' ')[0]):s.key==='oos'||s.key==='maint'?esc(s.o.reason||s.label):r.type}</small></button>`}).join('')}</div>`).join('')}</div>`).join('')}
  </section>
  <section class="panel"><div class="panel-head"><h2 class="grow">Rooms out of service or under maintenance</h2><span class="chip">${offline.length} rooms</span></div>
  ${offline.length?`<div class="tablewrap"><table><thead><tr><th>Room</th><th>Status</th><th>Reason</th><th>Details</th><th>Since</th><th>Expected back</th><th>Days offline</th></tr></thead><tbody>
  ${offline.map(({r,s})=>`<tr class="clickable" data-room="${r.id}"><td class="mono">${r.id}</td><td><span class="pill ${s.cls}">${s.label}</span></td><td>${esc(s.o.reason||'—')}</td><td>${esc(s.o.reasonDetail||'')}</td><td class="mono">${fmt(s.o.since)}</td><td class="mono">${fmt(s.o.expectedReturn)}${s.o.expectedReturn&&s.o.expectedReturn<=TODAY?' <span class="pill s-oos">due</span>':''}</td><td class="num">${s.o.since?diff(s.o.since,TODAY):'—'}</td></tr>`).join('')}</tbody></table></div>`:'<div class="empty">All 600 rooms are in service.</div>'}</section>`;
}

/* ---- Maintenance ---- */
function renderMaint(){
  const all=[...S.tickets.entries()].map(([id,t])=>({id,...t}));
  const open=all.filter(ticketOpen);
  const done=all.filter(t=>!ticketOpen(t)&&t.completedAt&&t.reportedAt);
  const avg=done.length?(done.reduce((s,t)=>s+Math.max(0,diff(t.reportedAt,t.completedAt)),0)/done.length).toFixed(1):'—';
  const cost=all.reduce((s,t)=>s+(+t.actualCost||0),0);
  let list=all.filter(t=>(ui.mStatus==='open'?ticketOpen(t):ui.mStatus==='all'?true:t.status===ui.mStatus)&&(!ui.mPrio||t.priority===ui.mPrio));
  const po={Critical:0,High:1,Medium:2,Low:3};list.sort((a,b)=>(po[a.priority]-po[b.priority])||(ticketDue(a)<ticketDue(b)?-1:1));
  const kp=(v,l,c)=>`<div class="kpi" style="--k:${c}"><div class="v">${v}</div><div class="l">${l}</div></div>`;
  const byCat={};for(const t of open)byCat[t.category]=(byCat[t.category]||0)+1;
  const sch=[...S.schedules.entries()].map(([id,s])=>({id,...s,nextDue:addDays(s.lastDone||TODAY,+s.frequency||30)})).sort((a,b)=>a.nextDue<b.nextDue?-1:1);
  return `<div class="kpis">
    ${kp(open.length,'Open tickets','var(--mnt)')}
    ${kp(open.filter(t=>t.priority==='Critical').length,'Critical open','var(--oos)')}
    ${kp(open.filter(t=>ticketDue(t)<TODAY).length,'Past due date','var(--oos)')}
    ${kp(open.filter(t=>t.takesOffline).length,'Keeping rooms offline','var(--dirty)')}
    ${kp(avg,'Avg days to resolve','var(--accent)')}
    ${kp(money(cost),'Actual cost to date','var(--accent)')}
  </div>
  <section class="panel"><div class="panel-head"><h2 class="grow">Maintenance tickets</h2><div class="filters">
    <select id="mStatus">${opts([['open','Open (not completed)'],['all','All'],...M_STATUS],ui.mStatus)}</select>
    <select id="mPrio">${opts(Object.keys(PRIOS),ui.mPrio,'All priorities')}</select>
    <button class="btn primary" data-act="newTicket">Log maintenance issue</button></div></div>
    <div class="summary" style="margin-bottom:8px">${Object.entries(byCat).map(([c,n])=>`<span>${esc(c)} <b>${n}</b></span>`).join('')||'<span>No open tickets</span>'}</div>
    <div class="tablewrap"><table><thead><tr><th>Ticket</th><th>Room</th><th>Issue</th><th>Category</th><th>Assigned to</th><th>Reported</th><th>Due</th><th>Room offline</th><th>Status</th></tr></thead><tbody>
    ${list.map(t=>{const due=ticketDue(t),late=ticketOpen(t)&&due<TODAY;return `<tr class="clickable" data-ticket="${t.id}"><td class="mono"><span class="prio" style="background:${PRIOS[t.priority]}" title="${t.priority}"></span>${esc(t.no||'')}</td><td class="mono">${esc(t.roomId)}</td><td><b>${esc(t.title)}</b><div style="color:var(--muted);font-size:12px">${esc(t.priority)} priority</div></td><td>${esc(t.category)}</td><td>${esc(t.assignedTo||'Unassigned')}</td><td class="mono">${fmt(t.reportedAt)}</td><td class="mono" style="${late?'color:var(--oos);font-weight:600':''}">${fmt(due)}${late?' · late':''}</td><td>${t.takesOffline?'<span class="pill s-mnt">Yes</span>':'No'}</td><td>${tStatusPill(t.status)}</td></tr>`}).join('')}
    </tbody></table>${list.length?'':'<div class="empty">No tickets match. Log an issue from here or from any room.</div>'}</div></section>
  <section class="panel"><div class="panel-head"><h2 class="grow">Preventive maintenance schedule</h2><button class="btn" data-act="newSchedule">Add scheduled task</button></div>
    ${sch.length?`<div class="tablewrap"><table><thead><tr><th>Task</th><th>Scope</th><th>Every</th><th>Last done</th><th>Next due</th><th>Owner</th><th></th></tr></thead><tbody>
    ${sch.map(s=>{const d=diff(TODAY,s.nextDue);return `<tr><td><b>${esc(s.task)}</b></td><td>${esc(s.scope)}</td><td class="num">${s.frequency} days</td><td class="mono">${fmt(s.lastDone)}</td><td class="mono">${fmt(s.nextDue)} <span class="pill ${d<0?'s-oos':d<=7?'s-dirty':'s-vac'}">${d<0?'overdue':d<=7?'due soon':'scheduled'}</span></td><td>${esc(s.owner||'')}</td><td style="white-space:nowrap"><button class="btn sm" data-act="pmDone" data-id="${s.id}">Mark done today</button> <button class="btn sm" data-act="editSchedule" data-id="${s.id}">Edit</button></td></tr>`}).join('')}</tbody></table></div>`:'<div class="empty">No preventive tasks yet. Add recurring jobs such as filter cleaning or fire equipment checks.</div>'}
  </section>`;
}

/* ============ view event binding ============ */
function bindView(){
  view.querySelectorAll('[data-goto]').forEach(b=>b.onclick=()=>{tab=b.dataset.goto;render()});
  view.querySelectorAll('[data-open]').forEach(tr=>tr.onclick=e=>{if(e.target.closest('button'))return;openBooking(tr.dataset.open)});
  view.querySelectorAll('[data-ticket]').forEach(tr=>tr.onclick=()=>openTicket(tr.dataset.ticket));
  view.querySelectorAll('[data-room]:not(.track)').forEach(el=>el.onclick=()=>openRoom(el.dataset.room));
  view.querySelectorAll('[data-rs]').forEach(b=>b.onclick=()=>{ui.roomStatus=b.dataset.rs;render()});
  view.querySelectorAll('[data-cal]').forEach(b=>b.onclick=()=>{const v=b.dataset.cal;ui.calStart=v==='today'?addDays(TODAY,-2):addDays(ui.calStart,+v);render()});
  const bindSel=(id,key,num)=>{const el=document.getElementById(id);if(el)el.onchange=()=>{ui[key]=num?+el.value:el.value;render()}};
  bindSel('calDays','calDays',true);bindSel('calBlock','calBlock');bindSel('calFloor','calFloor');bindSel('calType','calType');
  bindSel('resStatus','resStatus');bindSel('resRange','resRange');bindSel('roomBlock','roomBlock');bindSel('roomType','roomType');bindSel('mStatus','mStatus');bindSel('mPrio','mPrio');
  const cs=document.getElementById('calStart');if(cs)cs.onchange=()=>{if(cs.value){ui.calStart=cs.value;render()}};
  const rq=document.getElementById('resQ');if(rq)rq.oninput=()=>{ui.resQ=rq.value;clearTimeout(rq.t);rq.t=setTimeout(()=>{render();const n=document.getElementById('resQ');n.focus();n.setSelectionRange(n.value.length,n.value.length)},250)};
  view.querySelectorAll('.track[data-room]').forEach(tr=>tr.onclick=e=>{
    const bk=e.target.closest('[data-bk]');if(bk){openBooking(bk.dataset.bk);return}
    if(e.target.closest('.bk')||!CAN('reservations','edit'))return;
    const x=e.clientX-tr.getBoundingClientRect().left;const d=addDays(ui.calStart,Math.floor(x/W));
    openBooking(null,{roomId:tr.dataset.room,checkIn:d,checkOut:addDays(d,1)});
  });
  const hideIf=(sel,cond)=>{if(cond)view.querySelectorAll(sel).forEach(el=>el.remove())};
  hideIf('[data-act=checkin],[data-act=checkout],[data-act=newBooking]',!CAN('reservations','edit'));
  hideIf('[data-act=newTicket],[data-act=newSchedule],[data-act=pmDone]',!CAN('maintenance','edit'));
  hideIf('[data-act=openTicket]',!CAN('maintenance'));
  hideIf('[data-act=openRoom],[data-act=findRoom]',!CAN('rooms'));
  hideIf('[data-goto=desk]',!CAN('reservations'));
  view.querySelectorAll('[data-act]').forEach(b=>b.onclick=e=>{e.stopPropagation();Promise.resolve(ACTS[b.dataset.act]?.(b.dataset.id)).catch(()=>{})});
}
const ACTS={
  newBooking:()=>openBooking(),openBooking:id=>openBooking(id),openTicket:id=>openTicket(id),openRoom:id=>openRoom(id),
  checkin:id=>doCheckIn(id),checkout:id=>doCheckOut(id),newTicket:()=>openTicket(),findRoom:()=>openFinder(),
  newSchedule:()=>openSchedule(),editSchedule:id=>openSchedule(id),
  pmDone:async id=>{await store.update('schedules',id,{lastDone:TODAY});toast('Marked done. Next due '+fmt(addDays(TODAY,+S.schedules.get(id).frequency||30)))},
  loadDemo:async()=>{toast('Loading example data…');try{const j=await api('demo_load',{data:demoData()});lastSig='';await refresh();toast(j.count+' example records loaded')}catch(e){toast(e.message)}},
  clearDemo:()=>confirmBox('Clear example data?','This removes every record tagged as an example. Records you entered yourself are kept.','Clear examples',async()=>{try{const j=await api('demo_clear',{});lastSig='';await refresh();toast(j.count+' example records cleared')}catch(e){toast(e.message)}}),
};

/* ============ dialogs ============ */
let DLG_LOCK=null; // set to an area just before showDlg(); the dialog becomes read-only without edit access
function lockDialog(){
  dlg.querySelectorAll('.dlg-body input,.dlg-body select,.dlg-body textarea').forEach(el=>el.disabled=true);
  dlg.querySelectorAll('[type=submit],[data-edit]').forEach(el=>el.remove());
  const h=dlg.querySelector('.dlg-head h2');if(h)h.insertAdjacentHTML('afterend','<span class="pill s-muted">View only</span>');
}
function showDlg(html,onBind){const lock=DLG_LOCK&&!CAN(DLG_LOCK,'edit');DLG_LOCK=null;dlg.innerHTML=html;onBind?.();if(lock)lockDialog();dlg.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>dlg.close());if(!dlg.open)dlg.showModal()}
function confirmBox(title,msg,okLbl,fn){
  showDlg(`<div class="dlg-head"><h2>${esc(title)}</h2></div><div class="dlg-body"><p style="margin:0">${esc(msg)}</p></div><div class="dlg-foot"><button class="btn" data-close>Keep</button><button class="btn danger" id="cOk">${esc(okLbl)}</button></div>`,()=>{$('#cOk').onclick=async()=>{dlg.close();await fn()}});
}
const val=id=>dlg.querySelector('#'+id)?.value??'';

function openBooking(id,preset={}){
  DLG_LOCK='reservations';
  const ex=id?{...S.bookings.get(id),id}:null;
  const b=ex||{status:'Confirmed',checkIn:TODAY,checkOut:addDays(TODAY,1),arrivalTime:'14:00',departureTime:'10:00',adults:1,children:0,source:'Phone',payment:'Company account',guestType:'Contractor',nationality:'South Africa',...preset};
  if(!b.roomType&&b.roomId)b.roomType=ROOM_BY_ID[b.roomId]?.type;
  const f=(id,label,inner,cls='')=>`<div class="field ${cls}"><label for="${id}">${label}</label>${inner}</div>`;
  const inp=(id,label,type='text',cls='',extra='')=>f(id,label,`<input id="${id}" type="${type}" value="${esc(b[id]??'')}" ${extra}>`,cls);
  showDlg(`<form id="bkForm" novalidate><div class="dlg-head"><h2>${ex?'Reservation '+esc(ex.ref):'New reservation'}</h2>${ex?statusPill(ex.status):''}<button type="button" class="btn sm" data-close>Close</button></div>
  <div class="dlg-body">
    <fieldset><legend>Guest</legend>
      ${inp('guestName','Full name *','text','span2','required autocomplete="off"')}
      ${inp('idNumber','ID / passport number')}
      ${inp('nationality','Nationality')}
      ${inp('phone','Mobile','tel')}
      ${inp('email','Email','email')}
      ${inp('company','Company / organisation')}
      ${f('guestType','Guest type',`<select id="guestType">${opts(GUEST_TYPES,b.guestType)}</select>`)}
      ${inp('vehicleReg','Vehicle registration')}
      ${inp('emergencyName','Emergency contact')}
      ${inp('emergencyPhone','Emergency contact number','tel')}
    </fieldset>
    <fieldset><legend>Stay</legend>
      ${inp('checkIn','Check-in date *','date')}
      ${inp('checkOut','Check-out date *','date')}
      ${inp('arrivalTime','Expected arrival','time')}
      ${inp('departureTime','Expected departure','time')}
      ${f('roomType','Room type',`<select id="roomType">${opts(Object.keys(TYPES),b.roomType,'Any type')}</select>`)}
      ${f('roomId','Room *',`<select id="roomId"></select>`)}
      ${inp('adults','Adults','number','','min="1" max="4"')}
      ${inp('children','Children','number','','min="0" max="4"')}
      <div class="spanall summary" id="stayInfo"></div>
    </fieldset>
    <fieldset><legend>Billing</legend>
      ${inp('rate','Rate per night (R)','number','','min="0" step="10"')}
      ${f('totalOut','Total',`<input id="totalOut" readonly tabindex="-1">`)}
      ${inp('deposit','Deposit paid (R)','number','','min="0" step="10"')}
      ${inp('cancellationFee','Cancellation / no-show fee retained (R)','number','','min="0" step="10" title="Filled in with the deposit automatically when the booking is cancelled"')}
      ${f('payment','Payment method',`<select id="payment">${opts(PAY,b.payment)}</select>`)}
      ${inp('billingRef','PO / billing reference')}
      ${f('source','Booking source',`<select id="source">${opts(SOURCES,b.source)}</select>`)}
    </fieldset>
    <fieldset><legend>Status & notes</legend>
      ${f('status','Booking status',`<select id="status">${opts(BOOKING_STATUSES,b.status)}</select>`)}
      ${inp('keyCard','Key card number')}
      ${f('specialRequests','Special requests',`<textarea id="specialRequests">${esc(b.specialRequests||'')}</textarea>`,'span2')}
      ${f('notes','Internal notes',`<textarea id="notes">${esc(b.notes||'')}</textarea>`,'spanall')}
      ${ex?`<div class="spanall summary"><span>Created <b>${fmt(ex.createdAt)}</b></span><span>Checked in <b>${fmt(ex.checkedInAt)}</b></span><span>Checked out <b>${fmt(ex.checkedOutAt)}</b></span></div>`:''}
    </fieldset>
    <div class="err" id="bkErr" role="alert"></div>
  </div>
  <div class="dlg-foot"><div class="left">${ex&&(ex.status==='Confirmed'||ex.status==='Provisional')?`<button type="button" class="btn" id="bkIn" data-edit>Check in now</button><button type="button" class="btn danger" id="bkCancel" data-edit>Cancel reservation</button>`:''}${ex&&ex.status==='Checked-in'?`<button type="button" class="btn" id="bkOut" data-edit>Check out now</button>`:''}</div>
  <button type="button" class="btn" data-close>Discard</button><button type="submit" class="btn primary">${ex?'Save changes':'Create reservation'}</button></div></form>`,()=>{
    const refreshRooms=()=>{
      const ci=val('checkIn'),co=val('checkOut'),type=val('roomType');
      const sel=dlg.querySelector('#roomId');const cur=sel.value||b.roomId;
      let list=ci&&co&&co>ci?availableRooms(ci,co,type,ex?.id):[];
      const curRoom=ROOM_BY_ID[cur];
      const options=list.map(r=>[r.id,`${r.id} · ${r.type} · Block ${r.block} F${r.floor}`]);
      if(curRoom&&!list.find(r=>r.id===cur))options.unshift([cur,`${cur} · ${curRoom.type} · NOT AVAILABLE for these dates`]);
      sel.innerHTML=opts(options,cur,options.length?'Select room':'No rooms available');
      const nights=ci&&co?diff(ci,co):0;
      if(!b.rate||!ex){const r=ROOM_BY_ID[sel.value];if(r&&!dlg.querySelector('#rate').dataset.touched)dlg.querySelector('#rate').value=r.rate}
      const rate=+val('rate')||0;dlg.querySelector('#totalOut').value=money(rate*Math.max(0,nights));
      dlg.querySelector('#stayInfo').innerHTML=`<span>Nights <b>${Math.max(0,nights)}</b></span><span>Available rooms for these dates <b>${list.length}</b>${type?' ('+type+')':''}</span>`;
    };
    dlg.querySelector('#rate').oninput=e=>{e.target.dataset.touched=1;refreshRooms()};
    ['checkIn','checkOut','roomType'].forEach(i=>dlg.querySelector('#'+i).onchange=refreshRooms);
    dlg.querySelector('#roomId').onchange=refreshRooms;
    if(ex)dlg.querySelector('#rate').dataset.touched=1;
    refreshRooms();
    if(ex){
      dlg.querySelector('#bkIn')?.addEventListener('click',()=>{dlg.close();doCheckIn(ex.id)});
      dlg.querySelector('#bkOut')?.addEventListener('click',()=>{dlg.close();doCheckOut(ex.id)});
      dlg.querySelector('#bkCancel')?.addEventListener('click',()=>confirmBox('Cancel reservation '+ex.ref+'?','The room is released for these dates. The reservation stays on record as Cancelled.','Cancel reservation',async()=>{try{await store.update('bookings',ex.id,{status:'Cancelled'});toast('Reservation cancelled')}catch(e){}}));
    }
    dlg.querySelector('#bkForm').onsubmit=async e=>{
      e.preventDefault();
      const err=dlg.querySelector('#bkErr');
      const d={};['guestName','idNumber','nationality','phone','email','company','guestType','vehicleReg','emergencyName','emergencyPhone','checkIn','checkOut','arrivalTime','departureTime','roomId','payment','billingRef','source','status','keyCard','specialRequests','notes'].forEach(k=>d[k]=val(k).trim());
      d.adults=+val('adults')||1;d.children=+val('children')||0;d.rate=+val('rate')||0;d.deposit=+val('deposit')||0;d.cancellationFee=+val('cancellationFee')||0;
      if(!d.guestName)return err.textContent='Enter the guest’s full name.';
      if(!d.checkIn||!d.checkOut||d.checkOut<=d.checkIn)return err.textContent='Check-out must be at least one day after check-in.';
      if(!d.roomId)return err.textContent='Choose a room. If none are listed, change the dates or room type.';
      if(ACTIVE.has(d.status)){
        const c=conflicts(d.roomId,d.checkIn,d.checkOut,ex?.id);
        if(c.length)return err.textContent=`Room ${d.roomId} is already booked by ${c[0].guestName} (${fmt(c[0].checkIn)} – ${fmt(c[0].checkOut)}). Pick another room.`;
        if(isOffline(d.roomId,d.checkIn,d.checkOut))return err.textContent=`Room ${d.roomId} is out of service or under maintenance for part of these dates.`;
      }
      d.roomType=ROOM_BY_ID[d.roomId].type;d.total=d.rate*diff(d.checkIn,d.checkOut);
      if(d.status==='Checked-in'&&!ex?.checkedInAt)d.checkedInAt=TODAY;
      if(d.status==='Checked-out'&&!ex?.checkedOutAt)d.checkedOutAt=TODAY;
      try{
        if(ex){await store.update('bookings',ex.id,d);toast('Reservation saved')}
        else{d.ref='';d.createdAt=TODAY;d.checkedInAt=d.checkedInAt||'';d.checkedOutAt=d.checkedOutAt||'';const rec=await store.set('bookings',uid('b_'),d);toast('Reservation '+rec.ref+' created')}
      }catch(e){err.textContent=e.message;return}
      dlg.close();
    };
  });
}
async function doCheckIn(id){
  const b=S.bookings.get(id);if(!b)return;
  const st=roomState(ROOM_BY_ID[b.roomId]);
  if(st.key==='oos'||st.key==='maint')return toast(`Room ${b.roomId} is ${st.label.toLowerCase()}. Move the guest to another room first.`);
  const other=(IDX.byRoom.get(b.roomId)||[]).find(x=>x.id!==id&&x.status==='Checked-in');
  if(other)return toast(`Room ${b.roomId} is still occupied by ${other.guestName}. Check them out first.`);
  await store.update('bookings',id,{status:'Checked-in',checkedInAt:TODAY,...(b.checkIn>TODAY?{checkIn:TODAY}:{})});
  toast(`${b.guestName} checked in to ${b.roomId}`);
}
async function doCheckOut(id){
  const b=S.bookings.get(id);if(!b)return;
  const upd={status:'Checked-out',checkedOutAt:TODAY};if(b.checkOut>TODAY)upd.checkOut=TODAY;
  await store.update('bookings',id,upd);
  toast(`${b.guestName} checked out. Room ${b.roomId} marked dirty for housekeeping.`);
}

function openRoom(id){
  DLG_LOCK='rooms';
  const r=ROOM_BY_ID[id];const o=S.rooms.get(id)||{};const st=roomState(r);
  const upcoming=(IDX.byRoom.get(id)||[]).filter(b=>ACTIVE.has(b.status)&&b.checkOut>=TODAY).sort((a,b)=>a.checkIn<b.checkIn?-1:1);
  const tks=[...S.tickets.entries()].filter(([,t])=>t.roomId===id).map(([tid,t])=>({id:tid,...t}));
  showDlg(`<form id="rmForm"><div class="dlg-head"><h2>Room ${id}</h2><span class="pill ${st.cls}">${st.label}</span><button type="button" class="btn sm" data-close>Close</button></div>
  <div class="dlg-body">
    <dl class="kv"><dt>Type</dt><dd>${r.type} · ${money(r.rate)} / night</dd><dt>Location</dt><dd>Block ${r.block}, floor ${r.floor}</dd>
    <dt>Current guest</dt><dd>${st.b&&st.b.status==='Checked-in'?`${esc(st.b.guestName)} · out ${fmt(st.b.checkOut)} (${rel(st.b.checkOut)})`:'—'}</dd>
    <dt>Next arrival</dt><dd>${(()=>{const n=upcoming.find(b=>b.status!=='Checked-in');return n?`${esc(n.guestName)} · ${fmt(n.checkIn)} (${rel(n.checkIn)})`:'None booked'})()}</dd></dl>
    <fieldset><legend>Room condition</legend>
      <div class="field"><label for="service">Service status</label><select id="service">${opts([['in','In service'],['maint','Under maintenance'],['oos','Out of service']],o.service||'in')}</select></div>
      <div class="field"><label for="hk">Housekeeping</label><select id="hk">${opts(['Clean','Dirty','Inspected'],o.hk||'Clean')}</select></div>
      <div class="field span2"><label for="reason">Reason</label><select id="reason">${opts(OOS_REASONS,o.reason,'Select a reason')}</select></div>
      <div class="field spanall"><label for="reasonDetail">Details</label><textarea id="reasonDetail" placeholder="What is wrong and what is being done">${esc(o.reasonDetail||'')}</textarea></div>
      <div class="field"><label for="since">Offline since</label><input type="date" id="since" value="${esc(o.since||TODAY)}"></div>
      <div class="field"><label for="expectedReturn">Expected back in service</label><input type="date" id="expectedReturn" value="${esc(o.expectedReturn||'')}"></div>
    </fieldset>
    <div class="err" id="rmErr" role="alert"></div>
    <div><h3 style="margin-bottom:8px">Upcoming stays</h3>${upcoming.length?upcoming.map(b=>`<div class="summary" style="margin-bottom:4px">${statusPill(b.status)} <span><b>${esc(b.guestName)}</b></span><span>${fmt(b.checkIn)} → ${fmt(b.checkOut)}</span></div>`).join(''):'<div class="empty">No upcoming stays.</div>'}</div>
    <div><h3 style="margin-bottom:8px">Maintenance history</h3>${tks.length?tks.map(t=>`<div class="summary" style="margin-bottom:4px">${tStatusPill(t.status)}<span><b>${esc(t.title)}</b></span><span>${esc(t.category)} · ${fmt(t.reportedAt)}</span><button type="button" class="btn sm" data-tk="${t.id}">Open</button></div>`).join(''):'<div class="empty">No tickets for this room.</div>'}</div>
  </div>
  <div class="dlg-foot"><div class="left">${CAN('maintenance','edit')?'<button type="button" class="btn" id="rmTicket">Log maintenance issue</button>':''}${CAN('reservations','edit')?'<button type="button" class="btn" id="rmBook">Book this room</button>':''}</div><button type="button" class="btn" data-close>Discard</button><button class="btn primary" type="submit">Save room status</button></div></form>`,()=>{
    dlg.querySelectorAll('[data-tk]').forEach(b=>b.onclick=()=>openTicket(b.dataset.tk));
    if(!CAN('maintenance'))dlg.querySelectorAll('[data-tk]').forEach(b=>b.remove());
    dlg.querySelector('#rmTicket')?.addEventListener('click',()=>openTicket(null,{roomId:id}));
    dlg.querySelector('#rmBook')?.addEventListener('click',()=>openBooking(null,{roomId:id}));
    dlg.querySelector('#rmForm').onsubmit=async e=>{
      e.preventDefault();
      const d={service:val('service'),hk:val('hk'),reason:val('reason'),reasonDetail:val('reasonDetail').trim(),since:val('since'),expectedReturn:val('expectedReturn')};
      if(d.service!=='in'&&!d.reason)return dlg.querySelector('#rmErr').textContent='Choose a reason for taking the room offline.';
      if(d.service!=='in'&&d.expectedReturn&&d.expectedReturn<d.since)return dlg.querySelector('#rmErr').textContent='Expected return date must be on or after the offline date.';
      if(d.service==='in'){d.reason='';d.reasonDetail='';d.since='';d.expectedReturn=''}
      else{const clash=(IDX.byRoom.get(id)||[]).filter(b=>ACTIVE.has(b.status)&&b.checkIn<(d.expectedReturn||'9999-12-31')&&b.checkOut>d.since);
        if(clash.length&&!dlg.querySelector('#rmErr').dataset.warned){dlg.querySelector('#rmErr').dataset.warned=1;return dlg.querySelector('#rmErr').textContent=`${clash.length} reservation(s) overlap this period, starting with ${clash[0].guestName} (${fmt(clash[0].checkIn)}). Move them to another room. Press Save again to take the room offline anyway.`}}
      try{await store.set('rooms',id,{...o,...d})}catch(e){dlg.querySelector('#rmErr').textContent=e.message;return}
      toast('Room '+id+' updated');dlg.close();
    };
  });
}

function openTicket(id,preset={}){
  DLG_LOCK='maintenance';
  const ex=id?{...S.tickets.get(id),id}:null;
  const t=ex||{priority:'Medium',status:'Open',reportedAt:TODAY,category:'Plumbing',reportedBy:'Front desk',takesOffline:false,...preset};
  const roomOpts=ROOMS.map(r=>[r.id,r.id+' · '+r.type]);
  showDlg(`<form id="tkForm"><div class="dlg-head"><h2>${ex?'Ticket '+esc(ex.no):'Log maintenance issue'}</h2>${ex?tStatusPill(ex.status):''}<button type="button" class="btn sm" data-close>Close</button></div>
  <div class="dlg-body">
    <fieldset><legend>Issue</legend>
      <div class="field"><label for="roomId">Room *</label><select id="roomId">${opts(roomOpts,t.roomId,'Select room')}</select></div>
      <div class="field"><label for="category">Category</label><select id="category">${opts(M_CATS,t.category)}</select></div>
      <div class="field"><label for="priority">Priority</label><select id="priority">${opts(Object.keys(PRIOS).map(p=>[p,p+' (fix within '+PRIO_DAYS[p]+' day'+(PRIO_DAYS[p]>1?'s':'')+')']),t.priority)}</select></div>
      <div class="field span2"><label for="title">Short description *</label><input id="title" value="${esc(t.title||'')}" placeholder="e.g. Geyser leaking into ceiling"></div>
      <div class="field"><label for="reportedBy">Reported by</label><select id="reportedBy">${opts(['Front desk','Housekeeping','Guest','Inspection','Maintenance team','Security'],t.reportedBy)}</select></div>
      <div class="field spanall"><label for="description">Full details</label><textarea id="description">${esc(t.description||'')}</textarea></div>
    </fieldset>
    <fieldset><legend>Work order</legend>
      <div class="field span2"><label for="assignedTo">Assigned to</label><select id="assignedTo">${opts(TEAMS,t.assignedTo,'Unassigned')}</select></div>
      <div class="field"><label for="status">Status</label><select id="status">${opts(M_STATUS,t.status)}</select></div>
      <div class="field"><label for="reportedAt">Reported on</label><input type="date" id="reportedAt" value="${esc(t.reportedAt||TODAY)}"></div>
      <div class="field"><label for="dueDate">Due date</label><input type="date" id="dueDate" value="${esc(t.dueDate||'')}"></div>
      <div class="field"><label for="completedAt">Completed on</label><input type="date" id="completedAt" value="${esc(t.completedAt||'')}"></div>
      <div class="field"><label for="estCost">Estimated cost (R)</label><input type="number" min="0" id="estCost" value="${esc(t.estCost??'')}"></div>
      <div class="field"><label for="actualCost">Actual cost (R)</label><input type="number" min="0" id="actualCost" value="${esc(t.actualCost??'')}"></div>
      <div class="field spanall" style="flex-direction:row;align-items:center;gap:8px"><input type="checkbox" id="takesOffline" style="width:auto" ${t.takesOffline?'checked':''}><label for="takesOffline" style="font-size:13px;color:var(--fg)">Take the room offline (Under maintenance) until this ticket is completed</label></div>
    </fieldset>
    <div class="err" id="tkErr" role="alert"></div>
  </div>
  <div class="dlg-foot"><div class="left">${ex&&ticketOpen(ex)?`<button type="button" class="btn" id="tkDone" data-edit>Complete & return room to service</button>`:''}${ex&&IS_ADMIN?`<button type="button" class="btn danger" id="tkDel" data-edit>Delete ticket</button>`:''}</div><button type="button" class="btn" data-close>Discard</button><button class="btn primary" type="submit">${ex?'Save ticket':'Create ticket'}</button></div></form>`,()=>{
    const pr=dlg.querySelector('#priority'),due=dlg.querySelector('#dueDate');
    const setDue=()=>{if(!due.dataset.touched)due.value=addDays(val('reportedAt')||TODAY,PRIO_DAYS[pr.value])};
    due.oninput=()=>due.dataset.touched=1;if(ex)due.dataset.touched=1;pr.onchange=setDue;dlg.querySelector('#reportedAt').onchange=setDue;if(!ex)setDue();
    const save=async(extra={})=>{
      const d={};['roomId','category','priority','title','reportedBy','description','assignedTo','status','reportedAt','dueDate','completedAt'].forEach(k=>d[k]=val(k).trim());
      d.estCost=+val('estCost')||0;d.actualCost=+val('actualCost')||0;d.takesOffline=dlg.querySelector('#takesOffline').checked;Object.assign(d,extra);
      const err=dlg.querySelector('#tkErr');
      if(!d.roomId)return err.textContent='Choose the room this issue is in.';
      if(!d.title)return err.textContent='Add a short description of the issue.';
      if((d.status==='Completed'||d.status==='Verified')&&!d.completedAt)d.completedAt=TODAY;
      if(d.assignedTo&&d.status==='Open')d.status='Assigned';
      const tid=ex?.id||uid('t_');
      if(!ex)d.no='';
      let saved;try{saved=await (ex?store.update('tickets',tid,d):store.set('tickets',tid,d))}catch(e){err.textContent=e.message;return}
      d.no=saved.no;
      // The server takes the room offline / returns it to service from the ticket.
      toast(ex?'Ticket saved':'Ticket '+d.no+' created');dlg.close();
    };
    dlg.querySelector('#tkForm').onsubmit=e=>{e.preventDefault();save()};
    dlg.querySelector('#tkDone')?.addEventListener('click',()=>save({status:'Completed',completedAt:TODAY,returnRoom:true}));
    dlg.querySelector('#tkDel')?.addEventListener('click',()=>confirmBox('Delete ticket '+ex.no+'?','The ticket and its history are removed. The room’s status is not changed.','Delete ticket',async()=>{try{await store.del('tickets',ex.id);toast('Ticket deleted')}catch(e){}}));
  });
}

function openSchedule(id){
  DLG_LOCK='maintenance';
  const s=id?S.schedules.get(id):{frequency:90,lastDone:TODAY,scope:'All blocks'};
  showDlg(`<form id="scForm"><div class="dlg-head"><h2>${id?'Edit scheduled task':'Add scheduled task'}</h2><button type="button" class="btn sm" data-close>Close</button></div>
  <div class="dlg-body"><fieldset><legend>Preventive task</legend>
    <div class="field span2"><label for="task">Task *</label><input id="task" value="${esc(s.task||'')}" placeholder="e.g. Clean air-con filters"></div>
    <div class="field"><label for="scope">Scope</label><input id="scope" value="${esc(s.scope||'')}"></div>
    <div class="field"><label for="frequency">Repeat every (days)</label><input type="number" min="1" id="frequency" value="${esc(s.frequency)}"></div>
    <div class="field"><label for="lastDone">Last done</label><input type="date" id="lastDone" value="${esc(s.lastDone||'')}"></div>
    <div class="field"><label for="owner">Owner</label><select id="owner">${opts(TEAMS,s.owner,'Unassigned')}</select></div>
  </fieldset><div class="err" id="scErr"></div></div>
  <div class="dlg-foot"><div class="left">${id&&IS_ADMIN?`<button type="button" class="btn danger" id="scDel" data-edit>Delete task</button>`:''}</div><button type="button" class="btn" data-close>Discard</button><button class="btn primary" type="submit">Save task</button></div></form>`,()=>{
    dlg.querySelector('#scDel')?.addEventListener('click',async()=>{try{await store.del('schedules',id);dlg.close();toast('Task deleted')}catch(e){}});
    dlg.querySelector('#scForm').onsubmit=async e=>{e.preventDefault();
      const d={task:val('task').trim(),scope:val('scope').trim(),frequency:+val('frequency')||30,lastDone:val('lastDone')||TODAY,owner:val('owner')};
      if(!d.task)return dlg.querySelector('#scErr').textContent='Name the task.';
      try{await (id?store.update('schedules',id,d):store.set('schedules',uid('s_'),d))}catch(e){dlg.querySelector('#scErr').textContent=e.message;return}
      dlg.close();toast('Task saved')};
  });
}

function openFinder(){
  showDlg(`<div class="dlg-head"><h2>Check availability</h2><button type="button" class="btn sm" data-close>Close</button></div>
  <div class="dlg-body"><fieldset><legend>Dates</legend>
    <div class="field"><label for="fIn">Check-in</label><input type="date" id="fIn" value="${TODAY}"></div>
    <div class="field"><label for="fOut">Check-out</label><input type="date" id="fOut" value="${addDays(TODAY,1)}"></div>
    <div class="field"><label for="fType">Room type</label><select id="fType">${opts(Object.keys(TYPES),'','Any type')}</select></div>
    <div class="field"><label for="fBlock">Block</label><select id="fBlock">${opts(BLOCKS.map(b=>[b,'Block '+b]),'','Any block')}</select></div>
  </fieldset><div id="fRes"></div></div>`,()=>{
    const run=()=>{const ci=val('fIn'),co=val('fOut');const out=dlg.querySelector('#fRes');
      if(!ci||!co||co<=ci){out.innerHTML='<div class="err">Check-out must be after check-in.</div>';return}
      const list=availableRooms(ci,co,val('fType')).filter(r=>!val('fBlock')||r.block===val('fBlock'));
      const byType={};for(const r of list)byType[r.type]=(byType[r.type]||0)+1;
      out.innerHTML=`<div class="summary" style="margin-bottom:10px"><span><b>${list.length}</b> rooms free for ${diff(ci,co)} night(s)</span>${Object.entries(byType).map(([k,v])=>`<span>${k} <b>${v}</b></span>`).join('')}</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px">${list.slice(0,120).map(r=>`<button class="btn sm mono" data-pick="${r.id}">${r.id}</button>`).join('')}${list.length>120?`<span class="chip">+${list.length-120} more</span>`:''}</div>`;
      out.querySelectorAll('[data-pick]').forEach(b=>b.onclick=()=>openBooking(null,{roomId:b.dataset.pick,checkIn:ci,checkOut:co}));
    };
    ['fIn','fOut','fType','fBlock'].forEach(i=>dlg.querySelector('#'+i).onchange=run);run();
  });
}

/* ============ boot ============ */
(async function boot(){
  view.innerHTML='<div class="empty">Loading reservations…</div>';
  store=apiStore();
  try{await refresh();$('#modeChip').textContent='Live · MySQL'}
  catch(e){view.innerHTML=`<div class="empty">${esc(e.message)}</div>`;$('#modeChip').textContent='Not connected'}
  // Pick up colleagues' changes (and keep the server's alert emails flowing) every 20 seconds.
  setInterval(()=>{if(document.visibilityState==='visible'&&!dlg.open)refresh().then(()=>$('#modeChip').textContent='Live · MySQL').catch(()=>{$('#modeChip').textContent='Reconnecting…'})},20000);
})();
