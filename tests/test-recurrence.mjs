import assert from 'node:assert/strict';
import {writeFileSync} from 'node:fs';
const base='http://127.0.0.1:32768';
const date=new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Warsaw',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
function plus(n){const d=new Date(date+'T12:00:00Z');d.setUTCDate(d.getUTCDate()+n);return d.toISOString().slice(0,10)}
function bit(day){return 1<<new Date(day+'T12:00:00Z').getUTCDay()}
const created={routines:[],members:[],tasks:[]};let checks=0;
async function request(path,method='GET',body,token=''){
 const r=await fetch(base+'/api/'+path,{method,headers:{'Content-Type':'application/json',Origin:base,'CF-Connecting-IP':'198.51.100.41',...(token?{Cookie:token}:{})},...(body?{body:JSON.stringify(body)}:{})});return {status:r.status,body:await r.json(),cookie:r.headers.get('set-cookie')?.split(';')[0]};
}
function check(value,msg){assert.ok(value,msg);checks++}
let cookie;
const cleanupPath='/Users/karol/Documents/Codex/2026-11-26/pot/work/cleanup-recurrence.sql';
function writeCleanup(){const list=a=>a.map(v=>"'"+v+"'").join(',');const rid=list(created.routines),mid=list(created.members);let sql='';
 if(rid)sql+=`DELETE FROM events WHERE task_id IN (SELECT id FROM tasks WHERE recurrence_id IN (${rid}));\nDELETE FROM tasks WHERE recurrence_id IN (${rid});\nDELETE FROM routine_rules WHERE routine_id IN (${rid});\nDELETE FROM routines WHERE id IN (${rid});\n`;
 if(mid)sql+=`DELETE FROM sessions WHERE member_id IN (${mid});\nDELETE FROM members WHERE id IN (${mid});\n`;
 sql+="DELETE FROM events WHERE title LIKE 'TEST_REC_%';\n";writeFileSync(cleanupPath,sql);
}
try{
 await request('auth');const auth=await request('auth','POST',{pin:'654321'});check(auth.status===200,'owner login');cookie=auth.cookie;
 const old=(await request('data?day='+date,'GET',null,cookie)).body;check(old.tasks.every(t=>'recurrence_id'in t),'old tasks still readable');
 const create=async fields=>{const r=await request('routines','POST',{title:'TEST_REC_DAILY',description:'test',time:'08:00',category:'opening',priority:'normal',assigneeId:'',startsOn:date,endsOn:'',weekdays:127,...fields},cookie);check(r.status===201,'create routine: '+JSON.stringify(r.body));created.routines.push(r.body.id);return r.body.id;};
 const daily=await create({});
 const read=async(day,token=cookie)=>(await request('data?day='+day,'GET',null,token)).body;
 const occurrence=(data,id)=>data.tasks.filter(t=>t.recurrence_id===id);
 let current=await read(date);check(occurrence(current,daily).length===1,'one occurrence today');const first=occurrence(current,daily)[0];
 check(first.time==='08:00'&&first.category==='opening','category and deadline inherited');
 const again=await Promise.all(Array.from({length:6},()=>read(date)));check(again.every(d=>occurrence(d,daily).length===1),'parallel reads do not duplicate tasks');
 check(again.every(d=>occurrence(d,daily)[0].version===0),'polling does not change occurrence version');
 let future=await read(plus(1));check(occurrence(future,daily).length===1&&occurrence(future,daily)[0].id!==first.id,'next day has separate task');
 check((await request('tasks','PATCH',{id:first.id,version:0,action:'status',status:'done',note:'TEST_REC_NOTE'},cookie)).status===200,'complete today');
 check(occurrence(await read(plus(1)),daily)[0].status==='todo','tomorrow remains pending');
 const changed={id:daily,version:0,title:'TEST_REC_UPDATED',description:'updated',time:'09:30',category:'opening',priority:'high',assigneeId:'',startsOn:date,endsOn:'',weekdays:127};
 const update=await request('routines','PATCH',changed,cookie);check(update.status===200&&update.body.effectiveFrom===plus(1),'editing applies tomorrow');
 current=await read(date);check(occurrence(current,daily)[0].title==='TEST_REC_DAILY'&&occurrence(current,daily)[0].status==='done'&&occurrence(current,daily)[0].note==='TEST_REC_NOTE','today completion and original text retained');
 future=await read(plus(1));check(occurrence(future,daily)[0].title==='TEST_REC_UPDATED'&&occurrence(future,daily)[0].time==='09:30','already generated future task updates');
 check((await request('routines','PATCH',changed,cookie)).status===409,'stale schedule edit rejected');
 const custom=await create({title:'TEST_REC_CUSTOM',weekdays:bit(plus(2))});
 check(occurrence(await read(plus(2)),custom).length===1,'selected weekday generated');
 check(occurrence(await read(plus(3)),custom).length===0,'other weekday omitted');
 check(occurrence(await read(plus(9)),custom).length===1,'schedule repeats following week');
 const bounded=await create({title:'TEST_REC_BOUNDED',startsOn:plus(2),endsOn:plus(3)});
 check(occurrence(await read(date),bounded).length===0,'no task before start');
 check(occurrence(await read(plus(2)),bounded).length===1&&occurrence(await read(plus(3)),bounded).length===1,'start and end inclusive');
 check(occurrence(await read(plus(4)),bounded).length===0,'no task after end');
 check((await request('routines','POST',{...changed,startsOn:date,weekdays:0},cookie)).status===400,'empty schedule rejected');
 check((await request('routines','POST',{...changed,startsOn:date,weekdays:128},cookie)).status===400,'invalid weekday mask rejected');
 check((await request('routines','POST',{...changed,startsOn:plus(2),endsOn:plus(1)},cookie)).status===400,'reversed dates rejected');
 check((await request('routines','POST',{...changed,startsOn:plus(-1)},cookie)).status===400,'retroactive new cycle rejected');
 const remove=(await read(plus(2))).tasks.find(t=>t.recurrence_id===daily);
 check((await request('tasks','DELETE',{id:remove.id,version:remove.version},cookie)).status===200,'skip one occurrence');
 check(occurrence(await read(plus(2)),daily).length===0,'skipped occurrence not regenerated');
 check(occurrence(await read(plus(3)),daily).length===1,'skip does not affect other dates');
 check((await request('routines','PATCH',{id:daily,version:1,action:'toggle'},cookie)).status===200,'pause cycle');
 check(occurrence(await read(date),daily)[0].status==='done','pause retains today');
 check(occurrence(await read(plus(1)),daily).length===0,'pause hides pending future occurrence');
 check((await request('tasks','PATCH',{id:future.tasks.find(t=>t.recurrence_id===daily).id,version:1,action:'status',status:'done'},cookie)).status===404,'hidden paused occurrence cannot be completed');
 check((await request('routines','PATCH',{id:daily,version:2,action:'toggle'},cookie)).status===200,'resume cycle');
 check(occurrence(await read(plus(1)),daily).length===1,'resume restores future occurrence');
 check(occurrence(await read(plus(2)),daily).length===0,'resume does not resurrect skipped day');
 current=await read(date);check(current.events.some(e=>e.task_id===first.id&&e.action==='completed'&&e.note==='TEST_REC_NOTE'),'completion audit preserved across schedule changes');
 const worker=await request('members','POST',{name:'TEST_REC_WORKER',pin:'983'},cookie);check(worker.status===201,'create test worker');created.members.push(worker.body.id);
 const login=await request('auth','POST',{pin:'983'});check(login.status===200,'worker login');
 check((await request('routines','POST',{...changed},login.cookie)).status===403,'worker cannot create cycle');
 check((await request('routines','PATCH',{id:daily,version:3,action:'toggle'},login.cookie)).status===403,'worker cannot pause cycle');
 const assigned=await create({title:'TEST_REC_ASSIGNED',assigneeId:worker.body.id});
 const ownerOnly=await create({title:'TEST_REC_OWNER',assigneeId:'owner'});
 const workerData=await read(date,login.cookie);check(workerData.routines.length===0,'schedule management data not exposed to employee');
 check(occurrence(workerData,assigned).length===1&&occurrence(workerData,ownerOnly).length===0,'employee sees own and shared recurring tasks only');
 const task=occurrence(workerData,assigned)[0];check((await request('tasks','PATCH',{id:task.id,version:task.version,action:'status',status:'done',note:'TEST_REC_WORKER'},login.cookie)).status===200,'worker completes recurring task');
 current=await read(date);check(occurrence(current,assigned)[0].completed_by===worker.body.id,'owner sees who completed recurrence');
 const tomorrow=(await read(plus(1))).tasks.find(t=>t.recurrence_id===daily);check((await request('tasks','PATCH',{id:tomorrow.id,version:tomorrow.version,title:tomorrow.title,description:'',day:plus(4),time:'09:00',category:'opening',priority:'normal',assigneeId:''},cookie)).status===400,'moving recurring occurrence rejected');

 const cache=await create({title:'TEST_REC_STALE_ASSIGNMENT',assigneeId:worker.body.id});
 const cached=occurrence(await read(plus(1),login.cookie),cache)[0];
 const cachedRoutine=(await read(date)).routines.find(r=>r.id===cache);
 check((await request('routines','PATCH',{id:cache,version:0,title:'TEST_REC_STALE_ASSIGNMENT',description:'',time:'08:00',category:'opening',priority:'normal',assigneeId:'owner',weekdays:127,startsOn:date,endsOn:''},cookie)).status===200,'future assignment changed');
 check((await request('tasks','PATCH',{id:cached.id,version:cached.version,action:'status',status:'done'},login.cookie)).status===409,'cached old assignment cannot bypass new schedule');
 const mask=await create({title:'TEST_REC_MASK_CHANGE'});
 const oldFuture=occurrence(await read(plus(2)),mask)[0];
 check((await request('routines','PATCH',{id:mask,version:0,title:'TEST_REC_MASK_CHANGE',description:'',time:'08:00',category:'opening',priority:'normal',assigneeId:'',weekdays:bit(plus(3)),startsOn:date,endsOn:''},cookie)).status===200,'weekday schedule updated');
 check(occurrence(await read(plus(2)),mask).length===0,'removed weekday hides pre-generated task');
 check(occurrence(await read(plus(3)),mask).length===1,'updated weekday generates task');
 check(occurrence(await read(date),mask).length===1,'weekday edit preserves today even without previous read');
 console.log(JSON.stringify({passed:checks,created}));
}finally{writeCleanup()}
