import assert from 'node:assert/strict';
import { loginRedirect } from '../frontend/src/lib/login-url.ts';

const cases = [
  ['http opened on phone', 'https://cafe.example', 'http://cafe.example/', 'https://cafe.example/'],
  ['www alias', 'https://cafe.example', 'https://www.cafe.example/', 'https://cafe.example/'],
  ['hosting alias', 'https://cafe.example', 'https://cafe.cluster030.hosting.ovh.net/', 'https://cafe.example/'],
  ['configured www', 'https://www.cafe.example', 'https://cafe.example/', 'https://www.cafe.example/'],
  ['subdirectory', 'https://cafe.example/tasks', 'http://cafe.example/tasks/', 'https://cafe.example/tasks/'],
  ['existing trailing slash', 'https://cafe.example/tasks/', 'http://cafe.example/tasks/', 'https://cafe.example/tasks/'],
  ['same origin', 'https://cafe.example', 'https://cafe.example/', null],
  ['direct index', 'https://cafe.example', 'https://cafe.example/index.html', null],
  ['no loop on default port', 'https://CAFE.example:443', 'https://cafe.example/', null],
  ['local dev port', 'http://localhost:32768', 'http://localhost:32768/', null],
  ['different port', 'https://cafe.example:8443', 'https://cafe.example/', 'https://cafe.example:8443/'],
  ['old backend', undefined, 'https://cafe.example/', null],
];
for (const [label, configured, current, expected] of cases) assert.equal(loginRedirect(configured, current), expected, label);
assert.throws(() => loginRedirect('javascript:alert(1)', 'https://cafe.example/'));
console.log(JSON.stringify({login_address_checks: cases.length + 1, result: 'passed'}));
