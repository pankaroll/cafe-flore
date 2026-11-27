import assert from 'node:assert/strict';import{readFileSync}from'node:fs';
const base='http://127.0.0.1:32768';
const page=await fetch(base+'/setup.php');const html=await page.text();assert.equal(page.status,200);const cookie=page.headers.get('set-cookie')?.split(';')[0];const csrf=html.match(/name="csrf" value="([a-f0-9]+)"/)[1];
const data={csrf,install_key:readFileSync(new URL('../private/install-key.txt',import.meta.url),'utf8').trim(),db_host:'cafe-flore-ovh-test-db',db_port:'3306',db_name:'flore_test',db_user:'flore_test',db_password:'flore_test_only',public_url:base,mode:'fresh',owner_name:'Właściciel',owner_pin:'654321'};
const response=await fetch(base+'/setup.php',{method:'POST',headers:{Origin:base,Cookie:cookie,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data)});const text=await response.text();if(response.status!==200||!text.includes('Instalacja zakończona.')){console.log(text.slice(text.indexOf('<body>'),text.indexOf('<form')));throw new Error('Installation failed '+response.status)}
assert.equal((await fetch(base+'/setup.php')).status,404);
const auth=await fetch(base+'/api/auth',{method:'POST',headers:{Origin:base,'Content-Type':'application/json'},body:JSON.stringify({pin:'654321'})});const user=await auth.json();assert.equal(auth.status,200);assert.equal(user.user.role,'admin');
const session=auth.headers.get('set-cookie').split(';')[0];const rows=await(await fetch(base+'/api/data?day=2026-11-26',{headers:{Cookie:session}})).json();assert.equal(rows.routines.length,58);assert.equal(rows.tasks.length,50);
console.log(JSON.stringify({installer:'passed',templates:rows.routines.length,tasks:rows.tasks.length,ownerLogin:'passed'}));
