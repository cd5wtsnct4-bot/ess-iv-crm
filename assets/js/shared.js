/* ESS IV CRM — shared constants, date helpers and the example-data generator. */
'use strict';

/* ============ constants ============ */
const BLOCKS=['A','B','C','D','E','F'], FLOORS=10, PER_FLOOR=10;
const TYPES={Single:650,Double:850,Twin:850,Suite:1450,Accessible:650};
const BOOKING_STATUSES=['Provisional','Confirmed','Checked-in','Checked-out','Cancelled','No-show'];
const ACTIVE=new Set(['Provisional','Confirmed','Checked-in']);
const SOURCES=['Walk-in','Phone','Email','Online portal','Corporate account','Travel agent'];
const GUEST_TYPES=['Individual','Corporate','Contractor','Group','Staff','Student'];
const PAY=['Company account','Card','EFT','Cash'];
const OOS_REASONS=['Plumbing leak / blockage','Electrical fault','Air-con / HVAC failure','Water damage','Pest control treatment','Renovation / refurbishment','Deep clean','Fire & safety compliance','Damage by guest','Furniture / fixtures broken','Door lock / access fault','Used for storage / staff','Other'];
const M_CATS=['Plumbing','Electrical','HVAC','Carpentry & furniture','Painting & finishes','Appliances','Locks & access','Fire & safety','Pest control','IT / Wi-Fi','Cleaning','Other'];
const M_STATUS=['Open','Assigned','In progress','Awaiting parts','Completed','Verified'];
const PRIOS={Critical:'var(--oos)',High:'var(--mnt)',Medium:'var(--dirty)',Low:'var(--vac)'};
const PRIO_DAYS={Critical:1,High:3,Medium:7,Low:14};
const TEAMS=['In-house: Plumbing','In-house: Electrical','In-house: General handyman','Contractor: HVAC services','Contractor: Pest control','Contractor: Fire safety','Housekeeping'];

const ROOMS=[];
for(const b of BLOCKS)for(let f=1;f<=FLOORS;f++)for(let n=1;n<=PER_FLOOR;n++){
  let type=n<=4?'Single':n<=7?'Double':n<=9?'Twin':'Suite';
  if(f===1&&n<=2)type='Accessible';
  ROOMS.push({id:b+(f*100+n),block:b,floor:f,n,type,rate:TYPES[type]});
}
const ROOM_BY_ID=Object.fromEntries(ROOMS.map(r=>[r.id,r]));

/* ============ dates ============ */
const pad=n=>String(n).padStart(2,'0');
const iso=d=>d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());
const parse=s=>{const[y,m,d]=s.split('-').map(Number);return new Date(y,m-1,d)};
const addDays=(s,n)=>{const d=parse(s);d.setDate(d.getDate()+n);return iso(d)};
const diff=(a,b)=>Math.round((parse(b)-parse(a))/864e5);
const TODAY=(window.APP&&window.APP.today)||iso(new Date()); // server date, so every desk agrees on "today"
const DOW=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
const MON=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const fmt=s=>{if(!s)return '—';const d=parse(s);return DOW[d.getDay()]+' '+d.getDate()+' '+MON[d.getMonth()]};
const rel=s=>{const n=diff(TODAY,s);return n===0?'today':n===1?'tomorrow':n===-1?'yesterday':n>0?'in '+n+' days':(-n)+' days ago'};
const money=n=>'R '+Math.round(+n||0).toLocaleString('en-ZA');
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const uid=p=>p+Date.now().toString(36)+Math.random().toString(36).slice(2,6);

/* ============ example data (only used on request or in offline demo) ============ */
function demoData(){
  let seed=42;const rnd=()=>{seed=(seed*16807)%2147483647;return seed/2147483647};
  const pick=a=>a[Math.floor(rnd()*a.length)];
  const first=['Thabo','Lerato','Sipho','Naledi','Pieter','Anele','Johan','Zanele','Kagiso','Ayesha','Michael','Nomsa','Ruan','Precious','David','Fatima','Bongani','Chantel','Mpho','Liam'];
  const last=['Mokoena','Dlamini','Naidoo','van der Merwe','Nkosi','Botha','Khumalo','Pillay','Smith','Ndlovu','Jacobs','Mahlangu','Coetzee','Molefe','Adams'];
  const cos=['Eskom contractor','Sasol','Murray & Roberts','Transnet','Independent','Aurecon','WBHO','Group: Mining induction'];
  const out={bookings:{},rooms:{},tickets:{},schedules:{}};
  const oos=new Set();
  // rooms out of service / maintenance
  const offline=[['A305','oos','Water damage','Ceiling collapsed after geyser burst; drying and re-plaster',-6,5],
    ['B708','maint','Air-con / HVAC failure','Compressor replacement booked',-2,2],
    ['C108','maint','Plumbing leak / blockage','Shower drain blocked',-1,1],
    ['D1004','oos','Renovation / refurbishment','Suite refurbishment phase 2',-20,25],
    ['E210','maint','Electrical fault','DB board tripping',0,2],
    ['F601','oos','Pest control treatment','Bed bug treatment, 72h vacancy',-1,3],
    ['A1010','maint','Door lock / access fault','Card reader replaced',-1,1],
    ['C902','oos','Fire & safety compliance','Smoke detector failed inspection',-3,4]];
  for(const[id,svc,reason,detail,s,e]of offline){out.rooms[id]={service:svc,reason,reasonDetail:detail,since:addDays(TODAY,s),expectedReturn:addDays(TODAY,e),hk:'Dirty',example:true};oos.add(id)}
  let k=0;
  for(const r of ROOMS){
    if(oos.has(r.id))continue;
    let day=-95+Math.floor(rnd()*8); // ~3 months of history so the Profit & Loss has something to show
    while(day<14){
      const gap=Math.floor(rnd()*(rnd()<.6?2:7));day+=gap;
      const len=4+Math.floor(rnd()*(rnd()<.4?8:24));
      if(day>=14)break;
      const ci=addDays(TODAY,day),co=addDays(TODAY,day+len);
      let status=co<TODAY?'Checked-out':ci<=TODAY?'Checked-in':(rnd()<.8?'Confirmed':'Provisional');
      if(ci===TODAY&&rnd()<.6)status='Confirmed';
      if(co===TODAY&&rnd()<.4)status='Checked-out';
      if(co<TODAY&&co>=addDays(TODAY,-1)&&rnd()<.3)status='Checked-in'; // overdue departures
      if(status==='Checked-in'&&ci===addDays(TODAY,-1)&&rnd()<.05)status='Confirmed'; // missed arrivals
      let cancellationFee=0,cancelledAt='';
      if(rnd()<.07){ // cancellations and no-shows
        status=ci<=TODAY&&rnd()<.4?'No-show':'Cancelled';
        cancelledAt=status==='No-show'?ci:addDays(ci,-1-Math.floor(rnd()*7));if(cancelledAt>TODAY)cancelledAt=TODAY;
        cancellationFee=status==='No-show'||rnd()<.5?r.rate:0;
      }
      const g=pick(first)+' '+pick(last);const id='ex'+(++k);
      const nights=len;const co2=pick(cos);
      out.bookings[id]={ref:'ESS-'+String(10000+k),guestName:g,idNumber:String(7000000000000+Math.floor(rnd()*2e12)),
        nationality:rnd()<.85?'South Africa':pick(['Zimbabwe','Mozambique','Lesotho','UK','India']),phone:'+27 8'+Math.floor(1e7+rnd()*9e7),
        email:g.toLowerCase().replace(/[^a-z]+/g,'.')+'@example.com',company:co2,guestType:co2.startsWith('Group')?'Group':co2==='Independent'?'Individual':'Contractor',
        roomId:r.id,roomType:r.type,checkIn:ci,checkOut:co,arrivalTime:pick(['12:00','14:00','15:30','17:00','19:00']),departureTime:'10:00',
        adults:r.type==='Single'?1:1+Math.floor(rnd()*2),children:0,rate:r.rate,total:r.rate*nights,deposit:rnd()<.6?r.rate:0,
        payment:pick(PAY),billingRef:rnd()<.5?'PO-'+Math.floor(1e5+rnd()*9e5):'',source:pick(SOURCES),status,
        specialRequests:rnd()<.15?pick(['Ground floor preferred','Late arrival','Extra pillows','Halaal meals','Early breakfast']):'',
        vehicleReg:rnd()<.4?pick(['GP','MP','NW','KZN'])+' '+Math.floor(100+rnd()*900)+'-'+Math.floor(100+rnd()*900):'',emergencyName:'',emergencyPhone:'',notes:'',
        createdAt:addDays(ci,-Math.floor(rnd()*20)),checkedInAt:status==='Checked-in'||status==='Checked-out'?ci:'',checkedOutAt:status==='Checked-out'?co:'',cancellationFee,cancelledAt,example:true};
      day+=len;
    }
    if(rnd()<.12)out.rooms[r.id]={...(out.rooms[r.id]||{}),service:'in',hk:'Dirty',example:true};
  }
  const tks=[['A305','Plumbing','Critical','Burst geyser – ceiling and floor damage','Contractor: HVAC services','In progress',-6,1,true,18500],
    ['B708','HVAC','High','Air-con compressor failure','Contractor: HVAC services','Awaiting parts',-2,2,true,0],
    ['C108','Plumbing','High','Shower drain blocked','In-house: Plumbing','Assigned',-1,1,true,0],
    ['E210','Electrical','Critical','DB board tripping','In-house: Electrical','In progress',0,1,true,0],
    ['A1010','Locks & access','High','Card reader not responding','In-house: General handyman','Open',-1,0,true,0],
    ['C902','Fire & safety','Critical','Smoke detector failed inspection','Contractor: Fire safety','Assigned',-3,-1,true,0],
    ['F601','Pest control','High','Bed bug report from guest','Contractor: Pest control','Completed',-1,2,true,2400],
    ['B404','Carpentry & furniture','Low','Wardrobe door hinge loose','In-house: General handyman','Open',-4,10,false,0],
    ['D205','IT / Wi-Fi','Medium','Weak Wi-Fi signal','Contractor: HVAC services','Open',-2,3,false,0],
    ['E808','Painting & finishes','Low','Scuffed walls after checkout','In-house: General handyman','Completed',-9,-2,false,850],
    ['F310','Appliances','Medium','Bar fridge not cooling','In-house: Electrical','Verified',-12,-6,false,1200]];
  tks.forEach(([room,cat,prio,title,team,status,rep,due,off,cost],i)=>{
    out.tickets['ext'+i]={no:'MT-'+(2400+i),roomId:room,category:cat,priority:prio,title,description:'',reportedBy:pick(['Front desk','Housekeeping','Guest','Inspection']),
      assignedTo:team,status,reportedAt:addDays(TODAY,rep),dueDate:addDays(TODAY,due),completedAt:status==='Completed'||status==='Verified'?addDays(TODAY,Math.min(0,due)):'',
      estCost:cost||Math.round(500+rnd()*3000),actualCost:cost,takesOffline:off,example:true};
  });
  const sch=[['Air-con filter clean','All blocks',90,-80],['Fire extinguisher & detector check','All blocks',180,-185],['Geyser inspection','Blocks A–C',365,-200],
    ['Pest control preventive spray','All blocks',90,-88],['Deep clean rotation','Block D',30,-35],['Mattress rotation','Block E',120,-40]];
  sch.forEach(([task,scope,freq,last],i)=>out.schedules['exs'+i]={task,scope,frequency:freq,lastDone:addDays(TODAY,last),owner:pick(TEAMS),example:true});
  return out;
}

