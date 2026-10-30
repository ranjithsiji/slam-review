# Load test: ~1000 phones polling

What it proves: a hall of ~1000 phones, each polling `GET /api/live/state` about every 4 s (about 250 requests/s), keeps
p95 under 300 ms with under 1 % errors, and that the PHP pool does not run out of workers.

## 1. Install k6 (no root needed)
```bash
curl -sL https://github.com/grafana/k6/releases/download/v0.55.0/k6-v0.55.0-linux-amd64.tar.gz | tar xz && mv k6-*/k6 ~/bin/   # or: sudo apt install k6
```

## 2. Where to run it
- **Never through Cloudflare**: its bot and rate protection will block or distort one-address traffic. Point k6 at the origin
  (nginx) from another machine, or run it on the server itself against `https://127.0.0.1` with `-k` and a Host header
  (k6: `--insecure-skip-tls-verify`, and `hosts: {'slam.ans9.com': '127.0.0.1'}` in options). Running on the same server
  also uses its CPU, so the numbers are slightly pessimistic.
- **Do it at a quiet time**: the server is shared with other sites and 250 requests/s is real load.

## 3. Prepare data (a separate "Load test" slam with 1000 fake attendees, the gate open)
```bash
php spark slam:loadtest-prepare 1000 --allow-production      # writes tests/load/badges.json
```
Copy `tests/load/badges.json` to wherever k6 runs. The live demo slam is not touched.

## 4. Run
```bash
k6 run -e BASE=https://127.0.0.1 -e VUS=1000 -e ARRIVAL_SECONDS=300 -e POLL_SECONDS=480 --insecure-skip-tls-verify tests/load/reviewers.js
```
Ramp first: `-e VUS=200`, then 500, then 1000. While it runs, open and close review windows from the Status screen of the
load-test slam, so the submit path and the shared-state refresh are exercised under load.

## 5. Watch the pool (on the server, second terminal)
```bash
watch -n1 "curl -sk --resolve slam.ans9.com:443:127.0.0.1 https://slam.ans9.com/fpm-status | egrep 'active processes|idle processes|listen queue:|max listen queue|max children reached'"
```
Healthy: `listen queue` stays 0 and `max children reached` stays 0. If the queue grows, raise `pm.max_children`
in `/etc/php/8.4/fpm/pool.d/slam.conf` (about 40 MB of RAM per worker) and reload php8.4-fpm. Also watch
`mariadb -e 'show processlist'` and `free -m`.

## Pass criteria
p95 `state_poll_ms` under 300 ms, `http_req_failed` under 1 %, state 304 rate high (unchanged state is cheap),
no 429s (limits are per phone session, 60 polls a minute; per-IP check-in limit needs `ARRIVAL_SECONDS` of 300 or more).
