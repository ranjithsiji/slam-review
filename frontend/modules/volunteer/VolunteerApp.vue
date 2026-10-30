<template>
  <ConnBanner :status="poll.status.value" />
  <div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <div class="me-auto">
        <div class="fw-bold fs-3">{{ st.slam?.name || '…' }}</div>
        <div class="text-secondary small"><Icon name="place.venue" cls="icon-inline me-1" />{{ st.slam?.venue_name }}</div>
      </div>
      <StatusBadge v-if="st.slam" group="phase" :value="st.slam.status" />
      <div class="text-center"><div class="h2 m-0">{{ st.counts?.checked_in ?? '–' }}</div><div class="small text-secondary">inside</div></div>
      <div class="text-center"><div class="h2 m-0">{{ st.counts?.registered ?? '–' }}</div><div class="small text-secondary">registered</div></div>
    </div>
    <div v-if="st.slam && !gateOpen" class="card-footer small" :class="readOnly ? 'text-secondary' : 'text-warning'">
      <template v-if="readOnly">The slam has closed. This panel is read-only.</template>
      <template v-else-if="beforeGate">Check-in has not opened yet<span v-if="st.slam.checkin_opens_at"> (opens {{ time(st.slam.checkin_opens_at) }})</span>. You can still search.</template>
      <template v-else>The check-in gate is closed. New check-ins need an admin late-entry override.</template>
    </div>
  </div>

  <div class="row row-cards">
    <div class="col-12 col-lg-6">
      <div class="card h-100">
        <div class="card-header"><h3 class="card-title"><Icon name="qr.scan" cls="me-1" />Check in a badge</h3></div>
        <div class="card-body">
          <div v-show="scanning" class="mb-3">
            <div id="qr-reader" class="rounded overflow-hidden" style="max-width:420px"></div>
            <button type="button" class="btn btn-outline-secondary w-100 mt-2" @click="stopScan">Stop camera</button>
          </div>
          <button v-if="!scanning" type="button" class="btn btn-primary btn-lg w-100 mb-3" :disabled="!gateOpen || busy" @click="startScan">
            <Icon name="media.camera" />Scan badge QR
          </button>
          <form class="d-flex gap-2" @submit.prevent="checkinCode(typed)">
            <label class="visually-hidden" for="typed-id">Badge ID</label>
            <input id="typed-id" v-model="typed" class="form-control text-uppercase fw-bold" placeholder="Type ID, e.g. KR48213" maxlength="60" autocomplete="off" autocapitalize="characters" :disabled="!gateOpen">
            <button class="btn btn-primary" type="submit" :disabled="!gateOpen || busy || typed.trim().length < 7">Check in</button>
          </form>
          <div v-if="scanError" class="alert alert-warning mt-3 mb-0">{{ scanError }}</div>
          <div v-if="result" class="alert mt-3 mb-0" :class="result.ok ? 'alert-success' : 'alert-danger'" role="status" aria-live="assertive">
            <div class="d-flex gap-2 align-items-start">
              <Icon :name="result.ok ? 'status.ok' : 'status.error'" cls="icon-lg" />
              <div>
                <div class="fw-bold fs-3 ml-text">{{ result.title }}</div>
                <div>{{ result.text }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card h-100">
        <div class="card-header"><h3 class="card-title"><Icon name="action.search" cls="me-1" />Find a registration</h3></div>
        <div class="card-body">
          <label class="visually-hidden" for="q">Name, mobile or email</label>
          <input id="q" v-model="q" class="form-control mb-2" placeholder="Name, mobile or email (3+ characters)" autocomplete="off" @input="debouncedSearch">
          <div v-if="searching" class="text-secondary small">Searching…</div>
          <div v-else-if="q.trim().length >= 3 && searched && !results.length" class="alert alert-warning mb-0">
            <div class="fw-bold">Not registered</div>
            Only pre-registered attendees can check in. Please direct the person to the organisers.
          </div>
          <div class="list-group list-group-flush">
            <div v-for="a in results" :key="a.id" class="list-group-item px-0">
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="flex-fill min-w-0">
                  <div class="fw-bold ml-text">{{ a.name }}</div>
                  <div class="small text-secondary"><code>{{ a.badge_id }}</code> · {{ a.user_type }} · {{ a.mobile }}<span v-if="!Number(a.is_active)" class="text-danger"> · inactive</span></div>
                </div>
                <StatusBadge group="checkin" :value="Number(a.checked_in) ? 'checked_in' : a.last_state === 'forced' ? 'forced_out' : a.last_state ? 'checked_out' : 'never'" />
                <div class="btn-list">
                  <button v-if="!Number(a.checked_in) && gateOpen" type="button" class="btn btn-primary" :disabled="busy || !Number(a.is_active)" @click="checkinAttendee(a)">Check in</button>
                  <button v-if="!Number(a.checked_in) && !gateOpen && boot.is_admin && !readOnly && !beforeGate" type="button" class="btn btn-warning" @click="lateEntry(a)">Late entry</button>
                  <button v-if="Number(a.checked_in) && !readOnly" type="button" class="btn btn-outline-danger" @click="forceOut(a)">Force out</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { get, post } from '../../shared/api.js';
import { createPoller } from '../../shared/poller.js';
import { startTicker, time } from '../../shared/clock.js';
import { dialog, toast } from '../../shared/ui.js';
import Icon from '../../shared/components/Icon.vue';
import StatusBadge from '../../shared/components/StatusBadge.vue';
import ConnBanner from '../../shared/components/ConnBanner.vue';

const props = defineProps({ boot: Object });
const boot = props.boot;
const slamQ = boot.is_admin ? `?slam=${boot.slam_id}` : '';
const st = reactive({});
const typed = ref('');
const busy = ref(false);
const result = ref(null);
const scanning = ref(false);
const scanError = ref('');
const q = ref('');
const results = ref([]);
const searching = ref(false);
const searched = ref(false);
let scanner = null;
let lastCode = '';
let lastAt = 0;

const gateOpen = computed(() => st.slam?.status === 'checkin_open');
const readOnly = computed(() => !!st.slam?.read_only);
const beforeGate = computed(() => ['draft', 'scheduled', 'open'].includes(st.slam?.status));

const poll = createPoller(`/api/volunteer/state${slamQ}`, {
  interval: 5000,
  onData: (d) => Object.assign(st, d),
  onAuthError: () => { window.location.href = '/volunteer/login'; },
});

function refreshLists() {
  window.slamTables?.['recent-checkins']?.ajax.reload(null, false);
  if (q.value.trim().length >= 3) search();
  poll.now();
}

async function doCheckin(body) {
  busy.value = true;
  try {
    const res = await post('/api/volunteer/checkin', { ...body, slam_id: boot.slam_id });
    result.value = { ok: true, title: res.attendee.name, text: `${res.attendee.badge_id} · ${res.attendee.user_type === 'judge' ? 'Judge' : 'User'} — checked in. Ask them to scan their badge on their own phone to start reviewing.` };
    if (navigator.vibrate) navigator.vibrate(60);
    refreshLists();
  } catch (e) {
    result.value = { ok: false, title: e.reason === 'already_checked_in' ? 'Already checked in' : e.reason === 'not_registered' ? 'Not registered' : 'Not checked in', text: e.message };
    if (navigator.vibrate) navigator.vibrate([80, 60, 80]);
  } finally {
    busy.value = false;
  }
}
const checkinCode = (code) => { if (code.trim()) doCheckin({ code: code.trim() }).then(() => { typed.value = ''; }); };
const checkinAttendee = (a) => doCheckin({ attendee_id: a.id });

async function startScan() {
  scanError.value = '';
  scanning.value = true;
  try {
    const { Html5Qrcode } = await import('html5-qrcode');
    scanner = new Html5Qrcode('qr-reader', { verbose: false });
    await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 240, height: 240 } }, (text) => {
      const now = Date.now();
      if (text === lastCode && now - lastAt < 4000) return; // same badge held in front of the camera
      lastCode = text;
      lastAt = now;
      checkinCode(text);
    });
  } catch (e) {
    scanning.value = false;
    scanError.value = 'Camera not available. Allow camera access, or type the ID below.';
  }
}
async function stopScan() {
  try { await scanner?.stop(); scanner?.clear(); } catch {}
  scanner = null;
  scanning.value = false;
}

let t = null;
function debouncedSearch() { clearTimeout(t); t = setTimeout(search, 400); }
async function search() {
  const term = q.value.trim();
  if (term.length < 3) { results.value = []; searched.value = false; return; }
  searching.value = true;
  try {
    const res = await get(`/api/attendees/search?q=${encodeURIComponent(term)}${boot.is_admin ? `&slam=${boot.slam_id}` : ''}`);
    results.value = res.data;
    searched.value = true;
  } catch (e) {
    toast(e.message, 'error');
  } finally {
    searching.value = false;
  }
}

async function forceOut(a) {
  const reason = await dialog({ title: `Force check-out: ${a.name}`, message: 'Their phone session ends immediately.', reason: true, danger: true, confirmText: 'Force check-out' });
  if (reason === null) return;
  try {
    await post(`/api/force-checkout/${a.id}`, { reason });
    toast('Checked out.');
    refreshLists();
  } catch (e) { toast(e.message, 'error'); }
}

async function lateEntry(a) {
  const reason = await dialog({ title: `Late entry: ${a.name}`, message: 'Admin override after Gate Close. A reason is required and logged.', reason: true, confirmText: 'Allow late entry' });
  if (reason === null) return;
  try {
    await post('/api/checkin/late-override', { attendee_id: a.id, reason });
    toast('Late entry allowed. Ask them to scan their badge on their phone.');
    refreshLists();
  } catch (e) { toast(e.message, 'error'); }
}

onMounted(() => { startTicker(); poll.start(); });
onBeforeUnmount(() => { stopScan(); poll.stop(); });
</script>
