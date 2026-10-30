// k6 load test: N phones check in, then poll like the reviewer screen and submit a
// review whenever a window is open. Run against a production-like PHP-FPM server.
//
//   php spark slam:loadtest-prepare 1000
//   k6 run -e BASE=https://staging.example -e VUS=1000 tests/load/reviewers.js
//
// During the run, open a review window from the control panel to exercise submits.
// Pass criteria (CLAUDE.md "Live polling design"): p95 state poll < 300 ms, < 1 % errors.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import { Trend, Rate, Counter } from 'k6/metrics';

const BASE = __ENV.BASE || 'http://localhost:8080';
const VUS = Number(__ENV.VUS || 1000);
const data = JSON.parse(open('./badges.json'));
const badges = new SharedArray('badges', () => data.badges);

const pollTime = new Trend('state_poll_ms', true);
const notModified = new Rate('state_304_rate');
const submits = new Counter('reviews_submitted');

export const options = {
  scenarios: {
    hall: { executor: 'per-vu-iterations', vus: Math.min(VUS, badges.length), iterations: 1, maxDuration: __ENV.DURATION || '10m' },
  },
  thresholds: {
    state_poll_ms: ['p(95)<300'],
    http_req_failed: ['rate<0.01'],
  },
};

const jitter = (s) => s * (0.8 + Math.random() * 0.4);

export default function () {
  const badge = badges[__VU - 1];
  // Arrivals spread over the first ~2 minutes, like a gate rush.
  sleep(Math.random() * 120);
  const page = http.get(`${BASE}/in/${badge}`);
  const token = (page.body.match(/name="csrf_test_name" value="([^"]+)"/) || [])[1];
  const res = http.post(`${BASE}/checkin`, { csrf_test_name: token, badge_id: badge }, { redirects: 0 });
  if (!check(res, { 'checked in (303 → /review)': (r) => r.status === 303 && String(r.headers.Location).includes('/review') })) return;
  const csrf = (http.get(`${BASE}/review`).body.match(/name="csrf-token" content="([^"]+)"/) || [])[1];

  let etag = null;
  let reviewed = new Set();
  const until = Date.now() + Number(__ENV.POLL_SECONDS || 480) * 1000;
  while (Date.now() < until) {
    const r = http.get(`${BASE}/api/live/state`, { headers: etag ? { 'If-None-Match': etag } : {}, tags: { name: 'state' } });
    pollTime.add(r.timings.duration);
    notModified.add(r.status === 304);
    if (r.status === 200) {
      etag = r.headers.Etag || r.headers.ETag || null;
      const s = r.json();
      const cur = s.current;
      if (cur && cur.kind === 'performance' && !s.already_reviewed && !reviewed.has(cur.schedule_item_id)) {
        sleep(Math.random() * 60); // people take a while to score
        const body = JSON.stringify({ schedule_item_id: cur.schedule_item_id, answers: s.questions.map((q) => ({ question_id: q.id, score: 1 + Math.floor(Math.random() * 10) })) });
        const p = http.post(`${BASE}/api/review`, body, { headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, tags: { name: 'review' } });
        if (check(p, { 'review accepted': (x) => x.status === 200 })) { submits.add(1); reviewed.add(cur.schedule_item_id); }
      }
    } else if (r.status === 401) {
      break; // slam closed or checked out
    }
    sleep(jitter(4));
  }
}
