<template>
  <ConnBanner :status="poll.status.value" />
  <header class="navbar navbar-expand sticky-top bg-body border-bottom">
    <div class="container container-tight d-flex align-items-center">
      <img :src="logo.light" alt="Kerala Science Slam" class="brand-logo brand-logo-light me-2" style="height:2.25rem">
      <img :src="logo.dark" alt="Kerala Science Slam" class="brand-logo brand-logo-dark me-2" style="height:2.25rem">
      <div class="me-auto min-w-0">
        <div class="fw-bold text-truncate">{{ s.slam?.name || 'Science Slam' }}</div>
        <div class="small text-secondary text-truncate">{{ me.name }} · <code>{{ me.badge_id }}</code> · {{ me.user_type === 'judge' ? 'Judge' : 'Reviewer' }}</div>
      </div>
      <StatusBadge v-if="s.phase" group="phase" :value="s.phase" extra="me-2 d-none d-sm-inline-flex" />
      <button type="button" class="btn btn-ghost-secondary btn-icon" :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'" @click="toggleTheme">
        <Icon :name="dark ? 'theme.light' : 'theme.dark'" />
      </button>
    </div>
  </header>

  <main class="container container-tight py-3 has-bottom-nav" style="padding-bottom: 9rem !important">
    <div v-if="welcome" class="alert alert-success" role="status">{{ welcome }}</div>
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
            <PresenterCard :item="cur" />
          </div>
        </div>

        <div v-if="s.judge_offline" class="card">
          <div class="card-body text-center py-4">
            <Icon name="person.judge" cls="icon-xl text-purple mb-2" />
            <div class="h2">Judges' marks are collected after the slam</div>
            <div class="text-secondary">For this slam the organisers enter each judge's mark at the end. Keep your score sheet; nothing to submit here.</div>
          </div>
        </div>
        <div v-else-if="s.already_reviewed" class="card">
          <div class="card-body text-center py-4">
            <Icon name="status.ok" cls="icon-xl text-green mb-2" />
            <div class="h2">Review submitted</div>
            <div class="text-secondary mb-2">Thank you! You can change scores in the edit window at the end.</div>
            <div v-if="s.my_review" class="d-flex flex-wrap justify-content-center gap-2">
              <span v-for="a in s.my_review.answers" :key="a.question_id" class="badge bg-primary-lt fs-4">{{ qIndex(a.question_id) }}: {{ a.score }}<span class="text-secondary">/{{ qMax(a.question_id) }}</span></span>
            </div>
          </div>
        </div>
        <form v-else class="card" @submit.prevent="submit">
          <div class="card-body">
            <ScoreQuestion v-for="(q, i) in s.questions" :key="`${cur.schedule_item_id}-${q.id}`" :question="q" :index="i" v-model="answers[q.id]" :disabled="sending" />
            <label class="form-label" for="comment">Comment (optional)</label>
            <textarea id="comment" v-model="comment" class="form-control" rows="2" maxlength="1000" :disabled="sending"></textarea>
            <div v-if="submitError" class="alert alert-danger mt-3 mb-0" role="alert">{{ submitError }}</div>
          </div>
          <div class="sticky-actions">
            <button class="btn btn-primary btn-lg w-100" type="submit" :disabled="!complete || sending || !!s.paused">
              <span v-if="sending" class="spinner-border spinner-border-sm me-2"></span>
              {{ s.paused ? 'Paused: please wait' : sending ? (retrying ? 'Retrying…' : 'Sending…') : complete ? 'Submit review' : `Answer all ${s.questions.length} questions` }}
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
      </div>

      <!-- Edit window -->
      <EditList v-else-if="screen === 'edit'" ref="editList" :questions="s.questions" :closes-at="s.revision?.closes_at" :paused="!!s.paused" />

      <!-- Messages -->
      <div v-else class="card text-center">
        <div class="card-body py-5">
          <Icon :name="message.icon" :cls="`icon-xl mb-2 text-${message.color}`" />
          <div class="h2">{{ message.title }}</div>
          <p class="text-secondary">{{ message.text }}</p>
          <NextUp v-if="message.showNext" :next="s.next" />
          <div v-if="s.late_minutes > 0 && message.showNext" class="mt-2"><span class="badge bg-orange-lt">Running about {{ s.late_minutes }} min late</span></div>
        </div>
      </div>
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
      <p class="small text-secondary mt-2">After check-out you cannot review again once the gate has closed.</p>
    </template>
  </main>

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
import { post } from '../../shared/api.js';
import { createPoller } from '../../shared/poller.js';
import { startTicker, time, longDate } from '../../shared/clock.js';
import Icon from '../../shared/components/Icon.vue';
import StatusBadge from '../../shared/components/StatusBadge.vue';
import Countdown from '../../shared/components/Countdown.vue';
import ConnBanner from '../../shared/components/ConnBanner.vue';
import ScoreQuestion from './ScoreQuestion.vue';
import PresenterCard from './PresenterCard.vue';
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
const retrying = ref(false);
const submitError = ref('');
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

// Reset the form when a new performance opens.
watch(() => cur.value.schedule_item_id, () => {
  Object.keys(answers).forEach((k) => delete answers[k]);
  comment.value = '';
  submitError.value = '';
});

const poll = createPoller('/api/live/state', {
  interval: 4000,
  onData: (data) => {
    Object.assign(s, data);
    loaded.value = true;
    if (data.poll_seconds) poll.setInterval(data.poll_seconds * 1000);
  },
  onAuthError: () => { window.location.href = '/review/thanks'; },
  deadlines: () => [s.current?.closes_at, s.current?.ends_at, s.revision?.closes_at, s.next?.projected_start_at],
});

async function submit() {
  if (!complete.value || sending.value) return;
  sending.value = true;
  submitError.value = '';
  const body = { schedule_item_id: cur.value.schedule_item_id, answers: s.questions.map((q) => ({ question_id: q.id, score: answers[q.id] })), comment: comment.value };
  // The endpoint is idempotent, so retrying after a network failure is safe.
  for (let attempt = 0; attempt < 4; attempt++) {
    try {
      await post('/api/review', body);
      s.already_reviewed = true;
      s.my_review = { answers: body.answers };
      poll.now();
      break;
    } catch (e) {
      if (e.status && e.status < 500) { submitError.value = e.message; break; }
      retrying.value = true;
      if (attempt === 3) submitError.value = 'Could not reach the server. Check your connection and try again.';
      else await new Promise((r) => setTimeout(r, 1000 * (attempt + 1)));
    }
  }
  retrying.value = false;
  sending.value = false;
}

async function checkout() {
  if (!window.confirm('Check out now? You will not be able to review the remaining performances if the gate has closed.')) return;
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
