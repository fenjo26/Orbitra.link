import http from 'k6/http';
import { check } from 'k6';
// Every 302's cid is logged so cidrun.sh can prove it exists in clicks.
// MODE=repeat: 50 fixed IP/UA pairs. MODE=nat: 20 carrier IPs, a different phone (UA) per request.
const RATE = parseInt(__ENV.RATE || '100'), MODE = __ENV.MODE || 'repeat', RUN = __ENV.RUN || 'c';
const BASE = __ENV.BASE || 'http://127.0.0.1', ALIAS = __ENV.ALIAS || 'loadtest';
export const options = { discardResponseBodies: true,
  scenarios: { s: { executor: 'constant-arrival-rate', rate: RATE, timeUnit: '1s', duration: __ENV.DUR || '60s', preAllocatedVUs: RATE * 2, maxVUs: 3000 } },
  summaryTrendStats: ['med','p(95)','p(99)','max'] };
const UAS = ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124.0', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4) Safari/604.1', 'Mozilla/5.0 (Linux; Android 14) Chrome/124.0 Mobile'];
export default function () {
  let ip, ua;
  if (MODE === 'repeat') { const n = Math.floor(Math.random()*50); ip = `31.13.${n}.7`; ua = UAS[n % 3]; }
  else { // nat: 20 carrier IPs, many different phones behind each
    ip = `176.59.${Math.floor(Math.random()*20)}.1`; ua = UAS[2] + ' build/' + Math.floor(Math.random()*100000); }
  const r = http.get(`${BASE}/${ALIAS}?sub1=${RUN}-${__VU}-${__ITER}&sub2=${RUN}`, { headers: { 'User-Agent': ua, 'X-Forwarded-For': ip }, redirects: 0, timeout: '5s' });
  check(r, { '302': (x) => x.status === 302 });
  const m = (r.headers['Location'] || '').match(/cid=([0-9a-f-]{36})/);
  console.log('CID ' + (m ? m[1] : 'NONE') + ' ' + r.status);
}
