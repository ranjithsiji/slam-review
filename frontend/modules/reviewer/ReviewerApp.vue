<template>
  <ConnBanner :status="poll.status.value" />
  <header class="navbar navbar-expand sticky-top bg-body border-bottom">
    <div class="container container-tight d-flex align-items-center">
      <img :src="logo.light" alt="Kerala Science Slam" class="brand-logo brand-logo-light me-2" style="height:2.25rem">
      <img :src="logo.dark" alt="Kerala Science Slam" class="brand-logo brand-logo-dark me-2" style="height:2.25rem">
      <div class="me-auto min-w-0">
        <div class="fw-bold text-truncate">{{ s.slam?.name || 'Science Slam' }}</div>
        <div class="small text-secondary text-truncate">{{ me.name }} · <code>{{ me.badge_id }}</code> · {{ me.user_type === 'judge' ? 'Expert' : 'Judge' }}</div>
      </div>
      <StatusBadge v-if="s.phase" group="phase" :value="s.phase" extra="me-2 d-none d-sm-inline-flex" />
      <button type="button" class="btn btn-ghost-secondary btn-icon" :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'" @click="toggleTheme">
        <Icon :name="dark ? 'theme.light' : 'theme.dark'" />
      </button>
    </div>
  </header>

  <main class="container container-tight py-3 has-bottom-nav" style="padding-bottom: 9rem !important">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
      <span class="small" :class="poll.status.value === 'offline' ? 'text-danger' : 'text-secondary'" role="status" aria-live="polite">
        {{ poll.status.value === 'offline' ? 'No connection. Still trying.' : updatedAt ? `Updated ${updatedAt}` : 'Connecting…' }}
      </span>
      <button type="button" class="btn btn-sm flex-shrink-0" :disabled="refreshing" data-refresh @click="refresh">
        <span v-if="refreshing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Refresh
      </button>
    </div>
    <div v-if="welcome" class="alert alert-success" role="status">{{ welcome }}</div>
    <div v-if="voteNotice" class="alert d-flex gap-2 align-items-start" :class="voteNotice.ok ? 'alert-success' : 'alert-warning'" role="status" aria-live="polite">
      <div class="me-auto ml-text">{{ voteNotice.text }}</div>
      <button type="button" class="btn-close" aria-label="Dismiss" @click="voteNotice = null"></button>
    </div>
    <div v-if="s.paused" class="alert alert-warning d-flex gap-2 align-items-start" role="status" aria-live="assertive">
      <Icon name="action.hold" cls="icon-lg flex-none" />
      <div><div class="fw-bold">Paused for a moment</div><div>The timer is stopped, so nothing is lost. You will get the full remaining time when we restart.<span v-if="s.paused.reason"> ({{ s.paused.reason }})</span></div></div>
    </div>
    <div v-if="!loaded" class="text-center py-5"><div class="spinner-border" role="status"></div></div>

    <!-- NOW -->
    <template v-else-if="tab === 'now'">
      <!-- Live performance -->
      <template v-if="screen === 'review'">
        <div class="card mb-3">
          <div class="card-status-top bg-red"></div>
          <div class="card-body">
            <div class="d-flex align-items-center mb-3">
              <span class="badge bg-red-lt me-auto"><Icon name="status.live" cls="icon-inline me-1" />Review open<template v-if="cur.performing === false"> · performance ended</template></span>
              <span v-if="s.paused" class="badge bg-orange-lt fs-3">Paused</span>
              <Countdown v-else :until="cur.closes_at" size="xl" />
            </div>
            <div v-if="s.talk" class="small text-secondary mb-2"><Icon name="status.time" cls="icon-inline me-1" />Talk started {{ time(s.talk.started_at) }}</div>
            <PresenterCard :item="cur" />
          </div>
        </div>

        <div v-if="s.judge_offline" class="card">
          <div class="card-body text-center py-4">
            <Icon name="person.judge" cls="icon-xl text-purple mb-2" />
            <div class="h2">Experts' marks are collected after the slam</div>
            <div class="text-secondary">For this slam the organisers enter each expert's mark at the end. Keep your score sheet; nothing to submit here.</div>
          </div>
        </div>
        <div v-else-if="s.already_reviewed" class="card overflow-hidden">
          <div class="card-body py-4">
            <SubmitSuccess :answers="s.my_review?.answers || []" :q-index="qIndex" :q-max="qMax" :celebrate="justSubmitted">
              <div class="text-secondary mt-3">You can change your marks in the edit window at the end of the slam.</div>
              <NextUp :next="s.next" class="mt-3" />
            </SubmitSuccess>
          </div>
        </div>
        <div v-else-if="queued" class="card text-center" role="status" aria-live="polite">
          <div class="card-body py-4">
            <div class="spinner-border text-primary mb-3" aria-hidden="true"></div>
            <div class="h2">Your vote is saved on this phone</div>
            <div class="text-secondary">It will be sent as soon as the connection comes back. Keep this page open if you can; if you close it, open it again before the review closes.</div>
          </div>
        </div>
        <form v-else class="card" @submit.prevent="openConfirm">
          <div class="card-body">
            <ScoreQuestion v-for="(q, i) in s.questions" :key="`${cur.schedule_item_id}-${q.id}`" :question="q" :index="i" v-model="answers[q.id]" :disabled="sending" />
            <label class="form-label" for="comment">Comment (optional)</label>
            <textarea id="comment" v-model="comment" class="form-control" rows="2" maxlength="1000" :disabled="sending"></textarea>
            <div v-if="submitError" class="alert alert-danger mt-3 mb-0" role="alert">{{ submitError }}</div>
          </div>
          <div class="sticky-actions">
            <div class="vote-progress mb-2" aria-hidden="true"><i v-for="q in s.questions" :key="q.id" :class="{ on: typeof answers[q.id] === 'number' && answers[q.id] > 0 }"></i></div>
            <button class="btn btn-primary btn-lg w-100 vote-submit" :class="{ ready: complete && !s.paused }" type="submit" :disabled="!complete || sending || !!s.paused">
              {{ s.paused ? 'Paused: please wait' : complete ? 'Review and submit' : `Answer all ${s.questions.length} questions (${answeredCount} done)` }}
            </button>
          </div>
        </form>
      </template>

      <!-- Break -->
      <div v-else-if="screen === 'break'" class="card text-center">
        <div class="card-status-top bg-orange"></div>
        <div class="card-body py-5">
          <Icon :name="`break.${cur.break_type || 'other'}`" cls="icon-xl text-orange mb-2" />
          <div class="h1 ml-text">{{ cur.label }}</div>
          <div class="mb-3"><Countdown :until="cur.ends_at" size="xl" /></div>
          <NextUp :next="s.next" />
        </div>
        <div v-if="s.ads?.length" class="card-footer"><AdCarousel :ads="s.ads" /></div>
      </div>

      <!-- Edit window -->
      <EditList v-else-if="screen === 'edit'" ref="editList" :questions="s.questions" :closes-at="s.revision?.closes_at" :paused="!!s.paused" />

      <!-- Messages (with the talk on stage when there is one) -->
      <template v-else>
        <div v-if="s.talk" class="card mb-3"><div class="card-status-top bg-red"></div><div class="card-body"><TalkNow :talk="s.talk" /></div></div>
        <div class="card text-center">
          <div class="card-body py-5">
            <Icon :name="message.icon" :cls="`icon-xl mb-2 text-${message.color}`" />
            <div class="h2">{{ message.title }}</div>
            <p class="text-secondary">{{ message.text }}</p>
            <NextUp v-if="message.showNext" :next="s.next" />
            <div v-if="s.late_minutes > 0 && message.showNext" class="mt-2"><span class="badge bg-orange-lt">Running about {{ s.late_minutes }} min late</span></div>
          </div>
        </div>
      </template>
    </template>

    <!-- SCHEDULE -->
    <template v-else-if="tab === 'schedule'">
      <h2 class="h3">Running order</h2>
      <div class="list-group">
        <div v-for="r in s.running_order" :key="r.id" class="list-group-item slot-card" :class="[`slot-${r.kind === 'break' ? 'break' : r.status}`]">
          <div class="d-flex gap-3 align-items-center">
            <div class="text-center" style="min-width:3.5rem"><div class="fw-bold">{{ time(r.planned_start_at) }}</div><div class="small text-secondary">{{ r.duration_minutes }} min</div></div>
            <Icon v-if="r.kind === 'break'" :name="`break.${r.break_type || 'other'}`" cls="text-orange" />
            <div class="flex-fill min-w-0">
              <div class="fw-bold ml-text text-truncate">{{ r.kind === 'break' ? r.label : r.presenter_name }}</div>
              <div v-if="r.topic" class="small text-secondary ml-text">{{ r.topic }}</div>
            </div>
            <StatusBadge group="slot" :value="r.status" extra="flex-shrink-0" />
          </div>
        </div>
      </div>
    </template>

    <!-- ME -->
    <template v-else>
      <div class="card mb-3">
        <div class="card-body">
          <div class="h2 mb-1 ml-text">{{ me.name }}</div>
          <div class="mb-2"><code class="fs-3">{{ me.badge_id }}</code> <StatusBadge group="user_type" :value="me.user_type" /></div>
          <StatusBadge group="checkin" value="checked_in" />
          <div class="mt-3 text-secondary">Reviews submitted: <strong>{{ s.me?.reviewed_count ?? 0 }}</strong></div>
        </div>
      </div>
      <div class="card mb-3">
        <div class="card-body">
          <div class="fw-bold">{{ s.slam?.name }}</div>
          <div class="text-secondary"><Icon name="place.venue" cls="icon-inline me-1" />{{ s.slam?.venue_name }}<span v-if="s.slam?.address">, {{ s.slam.address }}</span></div>
          <div class="text-secondary"><Icon name="nav.slams" cls="icon-inline me-1" />{{ longDate(s.slam?.opens_at) }}</div>
          <div v-if="s.slam?.contact_name" class="mt-2"><Icon name="contact.phone" cls="icon-inline me-1" />Help: {{ s.slam.contact_name }} <a v-if="s.slam.contact_phone" :href="`tel:${s.slam.contact_phone}`">{{ s.slam.contact_phone }}</a></div>
        </div>
      </div>
      <button type="button" class="btn btn-outline-danger w-100" @click="checkout">Leaving? Check out</button>
      <p class="small text-secondary mt-2">After check-out you cannot review again once check-in has closed.</p>
    </template>
  </main>
  <VoteConfirm v-if="confirming && screen === 'review'" :item="cur" :questions="s.questions" :answers="answers" :comment="comment" :sending="sending" :error="submitError" @cancel="confirming = false" @confirm="submit" />

  <nav class="fixed-bottom bg-body border-top" aria-label="Sections" style="padding-bottom: env(safe-area-inset-bottom)">
    <div class="container container-tight d-flex">
      <button v-for="t in tabs" :key="t.key" type="button" class="btn btn-ghost-secondary flex-fill flex-column py-2 border-0 rounded-0" :class="{ 'text-primary': tab === t.key }" :aria-current="tab === t.key ? 'page' : undefined" @click="tab = t.key">
        <Icon :name="t.icon" /><span class="small">{{ t.label }}</span>
      </button>
    </div>
  </nav>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, defineComponent, h } from 'vue';
import { api, post } from '../../shared/api.js';
import { createPoller } from '../../shared/poller.js';
import { startTicker, time, longDate } from '../../shared/clock.js';
import Icon from '../../shared/components/Icon.vue';
import StatusBadge from '../../shared/components/StatusBadge.vue';
import Countdown from '../../shared/components/Countdown.vue';
import ConnBanner from '../../shared/components/ConnBanner.vue';
import TalkNow from '../../shared/components/TalkNow.vue';
import AdCarousel from '../../shared/components/AdCarousel.vue';
import ScoreQuestion from './ScoreQuestion.vue';
import PresenterCard from './PresenterCard.vue';
import VoteConfirm from './VoteConfirm.vue';
import SubmitSuccess from './SubmitSuccess.vue';
import EditList from './EditList.vue';

const props = defineProps({ boot: Object });
const me = props.boot.me || {};
const metaContent = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';
const logo = { light: metaContent('brand-logo'), dark: metaContent('brand-logo-dark') };
const welcome = ref(props.boot.welcome || '');
const s = reactive({});
const loaded = ref(false);
const tab = ref('now');
const tabs = [{ key: 'now', label: 'Now', icon: 'status.live' }, { key: 'schedule', label: 'Schedule', icon: 'nav.schedule' }, { key: 'me', label: 'Me', icon: 'person.badge' }];
const answers = reactive({});
const comment = ref('');
const sending = ref(false);
const queued = ref(false); // this performance's vote is saved on the phone, waiting for a connection
const voteNotice = ref(null);
const submitError = ref('');
const confirming = ref(false);
const justSubmitted = ref(false);
const dark = ref(document.documentElement.getAttribute('data-bs-theme') === 'dark');

const NextUp = defineComponent({
  props: { next: Object },
  setup(p) {
    return () => (p.next
      ? h('div', { class: 'text-secondary' }, [
          'Up next: ',
          h('strong', { class: 'ml-text' }, p.next.kind === 'break' ? p.next.label : p.next.presenter_name),
          p.next.topic ? h('div', { class: 'ml-text small' }, p.next.topic) : null,
          h('div', { class: 'small' }, `Planned ${time(p.next.planned_start_at)}${p.next.projected_start_at && p.next.projected_start_at !== p.next.planned_start_at ? ` · expected ${time(p.next.projected_start_at)}` : ''}`),
        ])
      : null);
  },
});

const cur = computed(() => s.current || {});
const screen = computed(() => {
  if (s.phase === 'reviewing' && cur.value.kind === 'performance') return 'review';
  if (s.phase === 'reviewing' && cur.value.kind === 'break') return 'break';
  if (s.phase === 'revision') return 'edit';
  return 'message';
});
const message = computed(() => {
  switch (s.phase) {
    case 'open':
    case 'checkin_open':
    case 'checkin_closed':
      return { icon: 'status.ok', color: 'green', title: 'You are checked in', text: 'Keep this page open. The review form appears here when a performance starts.', showNext: true };
    case 'reviewing':
      if (s.presenting) return { icon: 'status.live', color: 'orange', title: `Now presenting: ${s.presenting.presenter_name}`, text: `${s.presenting.topic || ''} The review form opens here soon.`.trim(), showNext: true };
      return { icon: 'status.time', color: 'primary', title: 'Waiting for the next performance', text: 'The review form will appear here automatically.', showNext: true };
    case 'reviews_complete':
      return { icon: 'phase.reviews_complete', color: 'secondary', title: 'Reviews closed', text: 'The edit window opens soon. You will be able to adjust your scores.' };
    case 'revision_closed':
      return { icon: 'status.lock', color: 'secondary', title: 'Scores are locked', text: 'Thank you for reviewing! The slam closes shortly.' };
    default:
      return { icon: 'status.time', color: 'secondary', title: 'Please wait', text: '' };
  }
});
const complete = computed(() => (s.questions || []).length > 0 && s.questions.every((q) => typeof answers[q.id] === 'number' && answers[q.id] > 0));
const qIndex = (qid) => (s.questions || []).findIndex((q) => q.id === qid) + 1;
const qMax = (qid) => (s.questions || []).find((q) => q.id === qid)?.max_score || 10;

// Marks are kept on the phone until the server has them, so a reload, a crash or a lost connection loses nothing.
const draftPrefix = `slam-vote:${me.badge_id}:`;
const draftKey = (id) => draftPrefix + id;
function readDraft(id) {
  try { return JSON.parse(localStorage.getItem(draftKey(id))); } catch { return null; }
}
function writeDraft(id, draft) {
  try { localStorage.setItem(draftKey(id), JSON.stringify(draft)); } catch {}
}
function clearDraft(id) {
  try { localStorage.removeItem(draftKey(id)); } catch {}
}
function draftItemIds() {
  try {
    return Object.keys(localStorage).filter((k) => k.startsWith(draftPrefix)).map((k) => Number(k.slice(draftPrefix.length)));
  } catch { return []; }
}

// Reset the form when a new performance opens, restoring anything saved for it.
watch(() => cur.value.schedule_item_id, (id) => {
  Object.keys(answers).forEach((k) => delete answers[k]);
  comment.value = '';
  submitError.value = '';
  confirming.value = false;
  justSubmitted.value = false;
  queued.value = false;
  const draft = id ? readDraft(id) : null;
  if (!draft) return;
  if (s.already_reviewed) { clearDraft(id); return; }
  Object.assign(answers, draft.answers || {});
  comment.value = draft.comment || '';
  if (draft.body) {
    queued.value = true;
    deliver(draft);
  }
});

function saveTyping() {
  const id = cur.value.schedule_item_id;
  if (!id || screen.value !== 'review' || s.already_reviewed || queued.value || sending.value) return;
  if (readDraft(id)?.body) return;
  if (Object.keys(answers).length || comment.value) {
    writeDraft(id, { answers: { ...answers }, comment: comment.value });
  }
}
watch(answers, saveTyping);
watch(comment, saveTyping);

let draftsChecked = false;
function checkOtherDrafts() {
  if (draftsChecked) return;
  draftsChecked = true;
  // Votes waiting from an earlier performance are sent (the server decides); unsent typing for one is dropped.
  for (const id of draftItemIds()) {
    if (id === cur.value.schedule_item_id) continue;
    const draft = readDraft(id);
    if (draft?.body) deliver(draft);
    else clearDraft(id);
  }
}

// The running order is kept on the phone and fetched again only when the poll reports a new version.
const scheduleKey = () => `slam-schedule:${s.slam?.id}`;
let scheduleVersion = null;
let scheduleRetry = null;
function loadStoredSchedule() {
  try {
    const stored = JSON.parse(localStorage.getItem(scheduleKey()));
    if (stored?.running_order) { s.running_order = stored.running_order; scheduleVersion = stored.version; }
  } catch {}
}
async function fetchSchedule() {
  clearTimeout(scheduleRetry);
  try {
    const doc = await api('/api/live/schedule');
    s.running_order = doc.running_order;
    scheduleVersion = doc.version;
    try { localStorage.setItem(scheduleKey(), JSON.stringify(doc)); } catch {}
  } catch {
    scheduleRetry = setTimeout(fetchSchedule, 20000); // the phone keeps showing the copy it has
  }
}
let storedChecked = false;
let scheduleWait = null;
function syncSchedule(version) {
  if (!storedChecked) { storedChecked = true; loadStoredSchedule(); }
  if (version === scheduleVersion) return;
  // Spread the phones out: they all learn about a change within seconds of each other.
  clearTimeout(scheduleWait);
  scheduleWait = setTimeout(fetchSchedule, s.running_order ? Math.random() * 8000 : 0);
}

const poll = createPoller('/api/live/state', {
  interval: 4000,
  onData: (data) => {
    Object.assign(s, data);
    loaded.value = true;
    if (s.already_reviewed && queued.value) queued.value = false;
    checkOtherDrafts();
    syncSchedule(data.schedule_version);
    if (data.poll_seconds) poll.setInterval(data.poll_seconds * 1000);
  },
  onAuthError: (body) => { window.location.href = body?.reason === 'slam_closed' ? '/review/thanks' : '/checkin'; },
  deadlines: () => [s.current?.closes_at, s.current?.ends_at, s.revision?.closes_at, s.next?.projected_start_at],
});

// The backup for when automatic updates have not come through: fetch everything again now.
const refreshing = ref(false);
const updatedAt = computed(() => (poll.lastOk.value ? new Date(poll.lastOk.value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : ''));
async function refresh() {
  if (refreshing.value) return;
  refreshing.value = true;
  window.dispatchEvent(new Event('slam-retry')); // a vote waiting on this phone tries again too
  scheduleVersion = null;
  const pause = new Promise((r) => setTimeout(r, 2000)); // one tap at a time, whatever the connection does
  await Promise.all([poll.now({ fresh: true }), pause]);
  refreshing.value = false;
}

const answeredCount = computed(() => (s.questions || []).filter((q) => typeof answers[q.id] === 'number' && answers[q.id] > 0).length);
function openConfirm() {
  if (!complete.value || s.paused) return;
  submitError.value = '';
  confirming.value = true;
  if (navigator.vibrate) navigator.vibrate(15);
}

async function submit() {
  if (!complete.value || sending.value) return;
  sending.value = true;
  submitError.value = '';
  const id = cur.value.schedule_item_id;
  const draft = {
    answers: { ...answers },
    comment: comment.value,
    name: cur.value.presenter_name || '',
    body: { schedule_item_id: id, answers: s.questions.map((q) => ({ question_id: q.id, score: answers[q.id] })), comment: comment.value },
  };
  writeDraft(id, draft);
  await deliver(draft);
  sending.value = false;
}

// Wait before the next try: backed off, but at once when the connection or the screen comes back.
function waitToRetry(attempt) {
  return new Promise((resolve) => {
    const done = () => {
      clearTimeout(timer);
      window.removeEventListener('online', done);
      window.removeEventListener('slam-retry', done);
      document.removeEventListener('visibilitychange', onVisible);
      resolve();
    };
    const onVisible = () => { if (!document.hidden) done(); };
    const ms = Math.min(15000, 1000 * 2 ** attempt) * (0.8 + Math.random() * 0.4);
    const timer = setTimeout(done, ms);
    window.addEventListener('online', done);
    window.addEventListener('slam-retry', done);
    document.addEventListener('visibilitychange', onVisible);
  });
}

// Sends a saved vote until the server gives an answer. The endpoint is idempotent and refuses a closed review,
// so retrying is always safe and no vote can arrive late.
const delivering = new Set();
async function deliver(draft) {
  const id = draft.body.schedule_item_id;
  if (delivering.has(id)) return;
  delivering.add(id);
  const isCurrent = () => cur.value.schedule_item_id === id;
  for (let attempt = 0; ; attempt++) {
    try {
      await post('/api/review', draft.body);
      clearDraft(id);
      if (isCurrent()) {
        s.already_reviewed = true;
        s.my_review = { answers: draft.body.answers };
        justSubmitted.value = attempt === 0;
        queued.value = false;
        confirming.value = false;
        if (navigator.vibrate) navigator.vibrate([30, 40, 60]);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
      if (attempt > 0) voteNotice.value = { ok: true, text: `Your vote${draft.name ? ` for ${draft.name}` : ''} has been sent.` };
      poll.now();
      break;
    } catch (e) {
      const tryAgain = !e.status || e.status >= 500 || e.status === 429 || e.reason === 'paused';
      if (tryAgain) {
        if (isCurrent()) {
          queued.value = true;
          confirming.value = false;
          sending.value = false;
        }
        await waitToRetry(attempt);
        continue;
      }
      const closed = e.reason === 'window_closed' || e.reason === 'no_open_window';
      if (closed || (e.status !== 401 && e.status !== 403)) {
        // A final answer: nothing more can be done with this vote. A lost sign-in keeps it for after the next scan.
        if (closed) clearDraft(id);
        else writeDraft(id, { answers: draft.answers, comment: draft.comment });
      }
      if (isCurrent()) {
        queued.value = false;
        submitError.value = e.message;
      }
      if (closed || !isCurrent()) {
        voteNotice.value = { ok: false, text: closed
          ? `Your vote${draft.name ? ` for ${draft.name}` : ''} could not be sent: the review closed before the connection came back.`
          : e.message };
      }
      poll.now();
      break;
    }
  }
  delivering.delete(id);
}

async function checkout() {
  if (!window.confirm('Check out now? You will not be able to review the remaining performances if check-in has closed.')) return;
  try {
    const res = await post('/api/checkout');
    window.location.href = res.redirect || '/checkin';
  } catch (e) {
    window.alert(e.message);
  }
}

function toggleTheme() {
  dark.value = !dark.value;
  document.documentElement.setAttribute('data-bs-theme', dark.value ? 'dark' : 'light');
  try { localStorage.setItem('slam-theme', dark.value ? 'dark' : 'light'); } catch {}
}

onMounted(() => {
  startTicker();
  poll.start();
  if (welcome.value) setTimeout(() => { welcome.value = ''; }, 6000);
});
</script>
