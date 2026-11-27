import assert from 'node:assert/strict';
import { loginRedirect } from '../frontend/src/lib/login-url.ts';
const base = 'http://127.0.0.1:32768';
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; }
const current = 'http://localhost:32768/';
const info = await (await fetch(base + '/api/auth')).json();
check(info.publicUrl === base, 'anonymous initialization returns configured address');
check(!('pin_pepper' in info) && !('database' in info), 'configuration secrets excluded');
const blocked = await fetch(base + '/api/auth', {
  method: 'POST', headers: {Origin: new URL(current).origin, 'Content-Type': 'application/json'},
  body: JSON.stringify({pin: '654321'}),
});
check(blocked.status === 403 && (await blocked.json()).error === 'Nieprawidłowe źródło żądania.', 'reported error reproduced on alias');
const target = loginRedirect(info.publicUrl, current);
check(target === base + '/', 'move to configured address before PIN entry');
const login = await fetch(target + 'api/auth', {
  method: 'POST', headers: {Origin: new URL(target).origin, 'Content-Type': 'application/json'},
  body: JSON.stringify({pin: '654321'}),
});
check(login.status === 200, 'login succeeds after address correction');
check((await login.json()).user.role === 'admin', 'owner account preserved');
const cookie = login.headers.get('set-cookie')?.split(';')[0];
check(Boolean(cookie), 'session issued');
const auth = await (await fetch(base + '/api/auth', {headers: {Cookie: cookie}})).json();
check(auth.user.role === 'admin', 'session recognized on canonical address');
check(loginRedirect(auth.publicUrl, target) === null, 'no redirect loop after login');
console.log(JSON.stringify({login_flow_checks: checks, result: 'passed'}));
