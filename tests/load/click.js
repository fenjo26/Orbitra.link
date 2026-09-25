import http from 'k6/http';
import { check } from 'k6';
const RATE = parseInt(__ENV.RATE || '20');
const DUR = __ENV.DUR || '60s';
const MODE = __ENV.MODE || 'unique';   // unique | repeat
const RUN = __ENV.RUN || 'r';
export const options = {
  discardResponseBodies: true,
  scenarios: { s: { executor: 'constant-arrival-rate', rate: RATE, timeUnit: '1s', duration: DUR,
    preAllocatedVUs: Math.min(RATE * 2, 2000), maxVUs: 3000 } },
  summaryTrendStats: ['avg','med','p(95)','p(99)','max'],
};
const UAS = ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36'];
const O1=[31,37,46,62,77,78,79,85,89,91,93,94,95,176,178,185,188,195,212,213];
function rip() { return `${O1[Math.floor(Math.random()*O1.length)]}.${Math.floor(Math.random()*255)}.${Math.floor(Math.random()*255)}.${1+Math.floor(Math.random()*253)}`; }
export default function () {
  let ip, ua;
  if (MODE === 'repeat') { const n = Math.floor(Math.random()*50); ip = `31.13.${n}.7`; ua = UAS[n % 3]; }
  else { ip = rip(); ua = UAS[Math.floor(Math.random()*3)] + ' r' + Math.random().toString(36).slice(2,8); }
  const id = `${RUN}-${__VU}-${__ITER}`;
  const r = http.get(`${__ENV.BASE || "http://127.0.0.1"}/${__ENV.ALIAS || "loadtest"}?sub1=${id}&sub2=${RUN}`, {
    headers: { 'User-Agent': ua, 'X-Forwarded-For': ip, 'Accept-Language': 'el-GR,el;q=0.9' },
    redirects: 0, timeout: '5s' });
  check(r, { '302': (x) => x.status === 302 });
}
