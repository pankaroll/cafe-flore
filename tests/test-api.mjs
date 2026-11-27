import assert from 'node:assert/strict';
const base='http://127.0.0.1:32768';
const day=new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Warsaw',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
const created={members:[],tasks:[]};
let checks=0;
async function request(path,method='GET',body,token='',ip='198.51.100.11'){
 const r=await fetch(base+'/api/'+path,{method,headers:{'Content-Type':'application/json',Origin:base,'CF-Connecting-IP':ip,...(token?{Cookie:token}:{})},...(body?{body:JSON.stringify(body)}:{})});
 const json=await r.json();return {status:r.status,body:json,cookie:r.headers.get('set-cookie')?.split(';')[0]};
}
function check(value,msg){assert.ok(value,msg);checks++;}
await request('auth');
check((await request('data?day='+day)).status===401,'anonymous data denied');
const owner=await request('auth','POST',{pin:'654321'});check(owner.status===200&&owner.body.user.role==='admin','owner PIN login');
const cookie=owner.cookie;
for(const [name,pin] of [['TEST_QA_A','987'],['TEST_QA_B','986']]){const r=await request('members','POST',{name,pin},cookie);check(r.status===201,'create employee with 3 digit PIN');created.members.push(r.body.id);}
check((await request('members','POST',{name:'TEST_QA_DUP',pin:'987'},cookie)).status===409,'duplicate PIN rejected');
const a=await request('auth','POST',{pin:'987'},'', '198.51.100.12');const b=await request('auth','POST',{pin:'986'},'', '198.51.100.13');
check(a.status===200&&b.status===200,'employee PIN login');
check((await request('members','POST',{name:'TEST_QA_BAD',pin:'985'},a.cookie)).status===403,'employee cannot create accounts');
check((await request('settings','PATCH',{cafeName:'TEST_QA_BAD'},a.cookie)).status===403,'employee cannot change settings');
for(const [title,assigneeId] of [['TEST_QA_ASSIGNED',created.members[0]],['TEST_QA_OTHER',created.members[1]],['TEST_QA_SHARED',null]]){
 const r=await request('tasks','POST',{title,description:'TEST_QA',day,time:'09:00',category:'opening',priority:'normal',assigneeId},cookie);check(r.status===201,'create assigned/shared task');created.tasks.push(r.body.id);
}
let ad=await request('data?day='+day,'GET',null,a.cookie);
check(ad.body.tasks.some(t=>t.id===created.tasks[0])&&!ad.body.tasks.some(t=>t.id===created.tasks[1])&&ad.body.tasks.some(t=>t.id===created.tasks[2]),'employee sees own/shared tasks only');
check(!JSON.stringify(ad.body).includes('pin_hash'),'PIN hashes never returned');
check((await request('tasks','PATCH',{id:created.tasks[1],version:0,action:'status',status:'done'},a.cookie)).status===403,'cannot complete another persons task');
check((await request('tasks','POST',{title:'TEST_QA_BAD',day,category:'day',priority:'normal'},a.cookie)).status===403,'employee cannot create tasks');
const t=created.tasks[0];
check((await request('tasks','PATCH',{id:t,version:0,action:'status',status:'doing'},a.cookie)).status===200,'start task');
check((await request('tasks','PATCH',{id:t,version:0,action:'status',status:'done'},a.cookie)).status===409,'stale version rejected');
check((await request('tasks','PATCH',{id:t,version:1,action:'status',status:'done',note:'TEST_QA potwierdzone'},a.cookie)).status===200,'complete task with note');
let adminData=(await request('data?day='+day,'GET',null,cookie)).body;
check(adminData.tasks.find(x=>x.id===t).completed_by===created.members[0],'completion records employee');
check(adminData.events.some(e=>e.task_id===t&&e.action==='completed'&&e.note==='TEST_QA potwierdzone'),'owner sees audit and note');
const shared=created.tasks[2];
const concurrent=await Promise.all([request('tasks','PATCH',{id:shared,version:0,action:'status',status:'done',note:'TEST_QA race'},a.cookie),request('tasks','PATCH',{id:shared,version:0,action:'status',status:'done',note:'TEST_QA race'},b.cookie)]);
check(concurrent.filter(r=>r.status===200).length===1&&concurrent.filter(r=>r.status===409).length===1,'simultaneous completion has one winner');
adminData=(await request('data?day='+day,'GET',null,cookie)).body;
check(adminData.events.filter(e=>e.task_id===shared&&e.action==='completed').length===1,'concurrent completion produces exactly one audit');
const forged=await fetch(base+'/api/settings',{method:'PATCH',headers:{'Content-Type':'application/json',Origin:'https://evil.example',Cookie:cookie},body:JSON.stringify({cafeName:'TEST_QA_BAD'})});check(forged.status===403,'cross-site mutation rejected');
check((await request('members','PATCH',{id:created.members[0],name:'TEST_QA_A',pin:'984'},cookie)).status===200,'reset employee PIN');
check((await request('data?day='+day,'GET',null,a.cookie)).status===401,'PIN reset revokes sessions');
check((await request('auth','POST',{pin:'987'},'', '198.51.100.12')).status===401,'old PIN no longer works');
const anew=await request('auth','POST',{pin:'984'},'', '198.51.100.12');check(anew.status===200,'new PIN works');
check((await request('members','PATCH',{id:created.members[0],action:'toggle'},cookie)).status===200,'deactivate employee');
check((await request('data?day='+day,'GET',null,anew.cookie)).status===401,'deactivation revokes sessions');
check((await request('auth','POST',{pin:'984'},'', '198.51.100.12')).status===401,'deactivated PIN rejected');
check((await request('tasks','DELETE',{id:t,version:2},cookie)).status===200,'owner deletes task');
adminData=(await request('data?day='+day,'GET',null,cookie)).body;
check(!adminData.tasks.some(x=>x.id===t)&&adminData.events.some(x=>x.task_id===t&&x.action==='completed'),'deleted task preserves completion audit');
for(let i=0;i<10;i++)await request('auth','POST',{pin:'00000000'},'', '198.51.100.99');
check((await request('auth','POST',{pin:'00000000'},'', '198.51.100.99')).status===429,'login guessing rate limited');
check((await request('auth','DELETE',{},b.cookie)).status===200&&(await request('data?day='+day,'GET',null,b.cookie)).status===401,'logout invalidates session');
console.log(JSON.stringify({passed:checks,created}));
