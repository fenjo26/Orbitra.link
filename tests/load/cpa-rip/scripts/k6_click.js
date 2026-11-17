import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import { SharedArray } from 'k6/data';

const PROFILE = __ENV.PROFILE || 'unique';
const RUN = __ENV.RUN || 'runX';
const STAGE_RATE = Number(__ENV.STAGE_RATE);
const START_RATE = Number(__ENV.START_RATE || STAGE_RATE);
const RAMP = Number(__ENV.RAMP || 30);
const HOLD = Number(__ENV.HOLD || 180);
const BASE_URL = __ENV.BASE_URL;
const LOC_PREFIX = 'https://offer.example/?cid=';
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

if (!BASE_URL || !STAGE_RATE) {
  throw new Error('BASE_URL and STAGE_RATE env vars are required');
}

const POOL_SIZE = PROFILE === 'repeat' ? 10000 : PROFILE === 'repeat-tight' ? 50 : 0;

const pool = POOL_SIZE > 0
  ? new SharedArray('visitors', () => JSON.parse(open(__ENV.POOL_FILE)))
  : null;

// ~40 base UAs (mobile + desktop), build suffix appended per request/visitor
const UAS = [
  // Android mobile
  'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
  'Mozilla/5.0 (Linux; Android 14; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
  'Mozilla/5.0 (Linux; Android 13; M2101K6G) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36',
  'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
  'Mozilla/5.0 (Linux; Android 13; Pixel 6a) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
  'Mozilla/5.0 (Linux; Android 14; 2201117TY) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
  'Mozilla/5.0 (Linux; Android 13; CPH2451) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
  'Mozilla/5.0 (Linux; Android 14; V2312) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
  'Mozilla/5.0 (Linux; Android 12; moto g(60)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36',
  'Mozilla/5.0 (Linux; Android 13; RMX3771) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
  'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
  'Mozilla/5.0 (Linux; Android 15; SM-F956B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
  // iPhone mobile
  'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.6723.69 Mobile/15E148 Safari/604.1',
  'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/132.0 Mobile/15E148 Safari/605.1.15',
  'Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
  'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
  'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/131.0.6778.73 Mobile/15E148 Safari/604.1',
  'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
  // Windows desktop
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.2849.68',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.2903.86',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 YaBrowser/24.10.1.661 Safari/537.36',
  'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 YaBrowser/24.10.1.661 Safari/537.36',
  // macOS desktop
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:132.0) Gecko/20100101 Firefox/132.0',
  // Linux desktop
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
  'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
  'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0',
  // misc
  'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.2792.79',
  'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
];

function isPrivateIp(a, b, c) {
  if (a === 0 || a === 10 || a === 127) return true;
  if (a === 100 && b >= 64 && b <= 127) return true;
  if (a === 169 && b === 254) return true;
  if (a === 172 && b >= 16 && b <= 31) return true;
  if (a === 192 && b === 168) return true;
  if (a === 192 && b === 0) return true; // 192.0.0/24 + 192.0.2/24 doc
  if (a === 198 && (b === 18 || b === 19)) return true;
  if (a === 203 && b === 0 && c === 113) return true;
  if (a >= 224) return true; // multicast + reserved
  return false;
}

function randPublicIp() {
  for (;;) {
    const a = 1 + Math.floor(Math.random() * 222);
    const b = Math.floor(Math.random() * 256);
    const c = Math.floor(Math.random() * 256);
    const d = 1 + Math.floor(Math.random() * 254);
    if (!isPrivateIp(a, b, c)) return a + '.' + b + '.' + c + '.' + d;
  }
}

const cOk = new Counter('resp_ok');
const cTimeout = new Counter('err_timeout');
const cNon302 = new Counter('err_non302');
const cBadLoc = new Counter('err_badloc');
const cNetErr = new Counter('err_neterr');
const cLog = new Counter('resp_log');   // one data point per request -> json output
const tDur = new Trend('resp_ms', true);

export const options = {
  scenarios: {
    stage: {
      executor: 'ramping-arrival-rate',
      startRate: START_RATE,
      timeUnit: '1s',
      stages: [
        { duration: RAMP + 's', target: STAGE_RATE },
        { duration: HOLD + 's', target: STAGE_RATE },
      ],
      preAllocatedVUs: Number(__ENV.PRE_VU || Math.min(2500, Math.max(50, STAGE_RATE * 6))),
      maxVUs: 3000,
    },
  },
  thresholds: {
    dropped_iterations: ['count==0'],
  },
};

export default function () {
  const eid = RUN + '-s' + STAGE_RATE + '-v' + __VU + '-i' + __ITER;
  let ip, ua;
  if (PROFILE === 'unique') {
    ip = randPublicIp();
    ua = UAS[Math.floor(Math.random() * UAS.length)] + '; b/' + eid;
  } else {
    const v = pool[Math.floor(Math.random() * pool.length)];
    ip = v.ip;
    ua = v.ua;
  }
  const res = http.get(BASE_URL + '?sub1=' + eid + '&sub2=' + RUN, {
    redirects: 0,
    timeout: '5s',
    discardResponsesBodies: true,
    headers: { 'X-Forwarded-For': ip, 'User-Agent': ua },
    tags: { eid: eid, profile: PROFILE, run: RUN },
  });

  const ms = res.timings.duration;
  let result, cid = '';
  if (res.error) {
    if (/timeout|deadline|timed out/i.test(res.error) || res.error_code === 1004) {
      result = 'timeout'; cTimeout.add(1, { stage: String(STAGE_RATE) });
    } else {
      result = 'neterr'; cNetErr.add(1, { stage: String(STAGE_RATE) });
    }
  } else if (res.status !== 302) {
    result = 'non302'; cNon302.add(1, { stage: String(STAGE_RATE), status: String(res.status) });
  } else {
    const loc = res.headers['Location'] || '';
    cid = loc.startsWith(LOC_PREFIX) ? loc.slice(LOC_PREFIX.length) : '';
    if (!UUID_RE.test(cid)) { result = 'badloc'; cBadLoc.add(1, { stage: String(STAGE_RATE) }); cid = ''; }
    else { result = 'ok'; cOk.add(1, { stage: String(STAGE_RATE) }); }
  }
  tDur.add(ms, { result: result });
  cLog.add(1, {
    eid: eid, ip: ip, profile: PROFILE, run: RUN,
    st: String(res.status), r: result, cid: cid,
    ms: ms.toFixed(1), err: res.error || '',
  });
}
