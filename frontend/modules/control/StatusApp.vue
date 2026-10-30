<template>
  <ConnBanner :status="poll.status.value" />
  <TablerLoading v-if="!st.slam" desc="Loading the status" />
  <template v-else>
    <!-- NOW: what is happening and the one thing to do next -->
    <div class="card now-card mb-3" :class="`now-${now.tone}`">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-start gap-3">
          <span class="now-icon" aria-hidden="true"><Icon :name="now.icon" /></span>
          <div class="flex-fill min-w-0">
            <div class="now-kicker">{{ now.kicker }}</div>
            <h2 class="now-title ml-text">{{ now.title }}</h2>
            <div v-if="now.subtitle" class="text-secondary ml-text">{{ now.subtitle }}</div>
          </div>
          <div v-if="st.paused" class="text-end">
            <div class="countdown countdown-xl text-red">{{ fmtCountdown(pausedSeconds) }}</div>
            <div class="small text-secondary">paused for</div>
          </div>
          <div v-else-if="now.timers.length" class="d-flex gap-4 text-end">
            <div v-for="t in now.timers" :key="t.label">
              <Countdown :until="t.until" size="xl" :icon="false" />
              <div class="small text-secondary">{{ t.label }}</div>
            </div>
          </div>
        </div>

        <div v-if="scheduleClock" class="d-flex flex-wrap align-items-center gap-2 mt-3">
          <span class="badge fs-4" :class="scheduleClock.cls"><Icon :name="scheduleClock.icon" cls="icon-inline me-1" />{{ scheduleClock.text }}</span>
          <span class="small text-secondary">{{ scheduleClock.hint }}</span>
          <button v-if="!st.paused" type="button" class="btn btn-sm" :disabled="blocked" @click="shiftRemaining(scheduleClock.suggest)"><Icon name="nav.schedule" />Adjust schedule…</button>
        </div>

        <div v-if="st.talk" class="talk-strip mt-3"><TalkNow :talk="st.talk" /></div>

        <div v-if="now.primary || now.facts.length" class="d-flex flex-wrap align-items-center gap-3 mt-3">
          <button v-if="now.primary" type="button" class="btn btn-lg" :class="now.primary.cls || 'btn-primary'" :disabled="blocked" @click="now.primary.run()"><Icon v-if="now.primary.icon" :name="now.primary.icon" />{{ now.primary.label }}</button>
          <a v-else-if="now.primaryHref" class="btn btn-lg btn-primary" :href="now.primaryHref.href">{{ now.primaryHref.label }}</a>
          <span v-for="f in now.facts" :key="f" class="text-secondary"><Icon name="status.info" cls="icon-inline me-1" />{{ f }}</span>
        </div>

        <div v-if="st.turnout && ['reviewing', 'reviews_complete', 'revision'].includes(st.slam.status)" class="turnout-now mt-3">
          <div class="d-flex flex-wrap align-items-baseline gap-2">
            <strong>{{ st.turnout.open ? 'Voting now' : 'Last review' }}:</strong><span class="ml-text">{{ st.turnout.presenter_name }}</span>
            <span class="ms-md-auto fs-3 fw-bold">{{ st.turnout.voted }} of {{ st.turnout.checked_in }} voted</span>
            <span class="badge" :class="st.turnout.percent >= 70 ? 'bg-green-lt' : st.turnout.percent >= 40 ? 'bg-orange-lt' : 'bg-red-lt'">{{ st.turnout.percent }}%</span>
          </div>
          <div class="progress progress-sm my-1" role="progressbar" :aria-valuenow="st.turnout.percent" aria-valuemin="0" aria-valuemax="100" aria-label="Voting turnout"><div class="progress-bar" :class="st.turnout.percent >= 70 ? 'bg-green' : st.turnout.percent >= 40 ? 'bg-orange' : 'bg-red'" :style="{ width: st.turnout.percent + '%' }"></div></div>
          <div class="d-flex flex-wrap align-items-center gap-2 small text-secondary">
            <span>{{ st.turnout.not_voted }} checked in but not voted · {{ st.turnout.voted_total }} votes counted in total</span>
            <a v-if="st.idle_count > 0" class="ms-md-auto text-orange fw-bold" :href="`/admin/slams/${st.slam.id}/not-reviewing`"><Icon name="status.warning" cls="icon-inline me-1" />{{ st.idle_count }} not reviewing → check out</a>
          </div>
        </div>

        <div v-if="now.next" class="now-next mt-3"><Icon name="action.advance" cls="icon-inline me-1" /><strong>Up next:</strong> <span class="ml-text">{{ now.next }}</span></div>
      </div>

      <div v-if="!connected" class="card-footer bg-red-lt text-red"><Icon name="status.offline" cls="icon-inline me-1" />No live connection: the buttons are paused so you never act on an out-of-date screen. They come back by themselves.</div>
      <div v-else-if="st.staff?.length" class="card-footer small text-secondary"><Icon name="person.user" cls="icon-inline me-1" />Also running this slam right now: <strong>{{ st.staff.map((x) => `${x.name} (${x.role})`).join(', ') }}</strong>. Changes by anyone appear here within a few seconds.</div>
      <div class="card-footer d-flex flex-wrap align-items-center gap-3">
        <div class="flex-fill" style="min-width: 14rem">
          <div class="d-flex justify-content-between small text-secondary mb-1"><span>{{ progressText }}<template v-if="st.pauses?.seconds"> · paused {{ Math.max(1, Math.round(st.pauses.seconds / 60)) }} min in total</template></span><span>{{ ended ? 'Finished' : `Server time ${time(serverIso)} ${tzLabel}` }}</span></div>
          <div class="progress progress-sm" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="Slam progress"><div class="progress-bar" :class="ended ? 'bg-green' : 'bg-orange'" :style="{ width: progress + '%' }"></div></div>
        </div>
        <div v-if="!ended" class="d-flex flex-wrap align-items-center gap-2">
          <span class="small text-secondary">Run mode</span>
          <div class="btn-group" role="group" aria-label="Run mode">
            <button type="button" class="btn btn-sm" :class="st.slam.auto_mode === 'auto' ? 'btn-primary' : ''" :aria-pressed="st.slam.auto_mode === 'auto'" :disabled="blocked" @click="setMode('auto')">Automatic</button>
            <button type="button" class="btn btn-sm" :class="st.slam.auto_mode === 'manual' ? 'btn-primary' : ''" :aria-pressed="st.slam.auto_mode === 'manual'" :disabled="blocked" @click="setMode('manual')">Manual</button>
          </div>
          <button v-if="canPause" type="button" class="btn btn-sm btn-outline-danger" :disabled="blocked" @click="pause"><Icon name="action.hold" />Pause</button>
          <button v-if="st.slam.auto_mode === 'auto' && !st.slam.hold" type="button" class="btn btn-sm btn-outline-warning" :disabled="blocked" @click="act('hold', {}, 'Held: the system will not start the next step by itself.')"><Icon name="action.hold" />Hold</button>
          <button v-else-if="st.slam.hold" type="button" class="btn btn-sm btn-success" :disabled="blocked" @click="act('resume', {}, 'Resumed.')"><Icon name="action.resume" />Resume</button>
        </div>
      </div>
    </div>

    <div v-if="st.paused" class="alert alert-warning"><Icon name="action.hold" cls="me-1" />The slam is paused. Buttons on the cards are off until you resume. Resuming adds the paused time to the running timer and moves the following slots later by it.</div>
    <div v-if="st.past_hard_stop" class="alert alert-danger"><Icon name="status.warning" cls="me-1" />Projected end plus the edit window runs past the hard stop at {{ time(st.slam.closes_at) }}. Consider shortening windows or skipping.</div>
    <div v-if="st.late_minutes > 0" class="alert alert-warning d-flex align-items-center gap-2 flex-wrap">
      <Icon name="status.time" /><div class="me-auto">Running late by <strong>{{ st.late_minutes }} min</strong>. Projected end {{ time(st.projected_end) }}.</div>
      <button type="button" class="btn btn-warning" @click="reflowRemaining">Re-flow remaining</button>
    </div>

    <!-- Vertical timeline: green = done, orange = now, grey = still to come -->
    <!-- Who is live right now -->
    <div v-if="st.presence && !['draft', 'scheduled', 'ended'].includes(st.slam.status)" class="row row-cards mb-3" aria-label="Live now">
      <div class="col-12 col-md-4">
        <div class="card card-accent accent-blue h-100"><div class="card-body d-flex align-items-center gap-3">
          <span class="live-dot" :class="{ on: st.presence.users.inside > 0 }" aria-hidden="true"></span>
          <div class="flex-fill"><div class="text-secondary small text-uppercase fw-bold">Reviewers live now</div>
            <div class="h1 m-0">{{ st.presence.users.inside }}<span class="fs-4 text-secondary fw-normal"> of {{ st.presence.users.total }} registered</span></div>
            <div class="small text-secondary">{{ st.presence.users.phone }} with their phone linked</div></div>
        </div></div>
      </div>
      <div class="col-12 col-md-4">
        <div class="card card-accent accent-purple h-100"><div class="card-body d-flex align-items-center gap-3">
          <span class="live-dot" :class="{ on: st.presence.judges.inside > 0 }" aria-hidden="true"></span>
          <div class="flex-fill"><div class="text-secondary small text-uppercase fw-bold">Judges live now</div>
            <div class="h1 m-0">{{ st.presence.judges.inside }}<span class="fs-4 text-secondary fw-normal"> of {{ st.presence.judges.total }}</span></div>
            <div class="small text-secondary">{{ st.presence.judges.phone }} with their phone linked</div></div>
        </div></div>
      </div>
      <div class="col-12 col-md-4">
        <div class="card card-accent accent-green h-100"><div class="card-body d-flex align-items-center gap-3">
          <span class="live-dot" :class="{ on: st.presence.volunteers.online > 0 }" aria-hidden="true"></span>
          <div class="flex-fill"><div class="text-secondary small text-uppercase fw-bold">Volunteers live now</div>
            <div class="h1 m-0">{{ st.presence.volunteers.online }}<span class="fs-4 text-secondary fw-normal"> of {{ st.presence.volunteers.total }}</span></div>
            <div class="small text-secondary ml-text">{{ st.presence.volunteers.names.length ? st.presence.volunteers.names.join(', ') : 'No desk is open' }}</div></div>
        </div></div>
      </div>
    </div>

    <ol class="status-timeline" aria-label="Slam timeline">
      <template v-for="n in nodes" :key="n.key">
        <li v-if="n.groupStart" class="status-group" aria-hidden="true"><span>Performances</span><span class="text-secondary fw-normal">{{ doneCount }} of {{ perfs.length }} done</span></li>
        <li :class="`is-${n.state}`" :aria-current="n.state === 'current' ? 'step' : undefined">
          <span class="st-dot" :class="[`st-dot-${n.state}`, { 'st-dot-next': n.isNext }]" aria-hidden="true"><Icon :name="n.state === 'done' ? 'status.ok' : n.icon" /></span>
          <div class="card status-card" :class="[`status-card-${n.state}`, { 'status-card-next': n.isNext }]">
            <div class="card-body py-3">
              <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="me-auto min-w-0">
                  <h3 class="h3 m-0 ml-text" :class="{ 'text-decoration-line-through text-secondary': n.state === 'skipped' }">{{ n.title }}</h3>
                  <div v-if="n.subtitle" class="small text-secondary ml-text text-truncate">{{ n.subtitle }}</div>
                </div>
                <span v-if="n.lateBy" class="badge" :class="n.lateBy > 0 ? 'bg-orange-lt' : 'bg-green-lt'">{{ n.lateBy > 0 ? `${n.lateBy} min late` : `${-n.lateBy} min early` }}</span>
                <button v-if="canChange(n)" type="button" class="btn btn-sm btn-ghost-secondary" :disabled="blocked" @click="changeTime(n.item)"><Icon name="status.time" />Change time</button>
                <span class="badge" :class="chip(n).cls"><Icon :name="chip(n).icon" cls="icon-inline me-1" />{{ chip(n).text }}</span>
                <span class="text-secondary small">{{ whenText(n) }}</span>
              </div>

              <!-- Performance: two lanes, because the review can open while presenting and runs on after it ends -->
              <div v-if="n.item && n.item.item_type === 'performance' && n.state !== 'skipped' && (n.state === 'current' || n.state === 'done' || n.isNext)" class="lanes mt-3">
                <div class="lane">
                  <span class="lane-name">Performance</span>
                  <span class="badge" :class="perfChip(n.item).cls">{{ perfChip(n.item).text }}</span>
                </div>
                <div class="lane">
                  <span class="lane-name">Review</span>
                  <span class="badge" :class="reviewChip(n.item).cls">{{ reviewChip(n.item).text }}</span>
                  <span v-if="n.item.status === 'open'" class="small text-secondary">closes {{ time(n.item.window_closes_at) }} (<Countdown :until="n.item.window_closes_at" :icon="false" />)</span>
                </div>
              </div>
              <div v-else-if="n.item && n.item.item_type === 'break' && n.item.status === 'open' && st.current?.ends_at" class="small text-secondary mt-2">Until {{ time(st.current.ends_at) }} (<Countdown :until="st.current.ends_at" :icon="false" />)</div>

              <!-- Done: one line -->
              <div v-if="n.state === 'done' && n.summary" class="text-secondary mt-2">{{ n.summary }}</div>
              <div v-else-if="n.state === 'upcoming' && n.hint" class="text-secondary small mt-1">{{ n.hint }}</div>

              <!-- Step details when it is happening -->
              <div v-if="n.state === 'current' && n.step" class="mt-3">
                <div v-if="n.key === 'setup'">
                  <div v-if="st.readiness.length" class="alert alert-warning mb-0"><h4 class="alert-title">Not ready to open</h4><ul class="mb-0"><li v-for="m in st.readiness" :key="m">{{ m }}</li></ul></div>
                  <div v-else class="text-secondary">{{ idleText }}</div>
                </div>
                <div v-else-if="n.key === 'checkin_open'">
                  <div class="d-flex align-items-center gap-3 mb-2">
                    <div class="h1 m-0">{{ checkedIn }}</div>
                    <div class="flex-fill">
                      <div class="small text-secondary">of {{ st.counts.registered }} registered are inside ({{ st.counts.users_in }} users, {{ st.counts.judges_in }} judges)</div>
                      <div class="progress progress-sm mt-1"><div class="progress-bar bg-green" :style="{ width: pct(checkedIn, st.counts.registered) + '%' }"></div></div>
                    </div>
                  </div>
                  <div v-if="st.slam.checkin_closes_at" class="text-secondary small">Gate closes at {{ time(st.slam.checkin_closes_at) }} (<Countdown :until="st.slam.checkin_closes_at" :icon="false" />)</div>
                  <div class="text-secondary small">Attendees check themselves in by scanning the QR code on their badge.</div>
                </div>
                <div v-else-if="n.key === 'checkin_closed'" class="text-secondary">{{ checkedIn }} inside. Start the first performance when the presenter is ready. Late arrivals: volunteers or admins use Late check-in.</div>
                <div v-else-if="n.key === 'revision' && st.revision" class="d-flex align-items-center gap-3">
                  <div class="flex-fill text-secondary">{{ st.counts.revised }} reviewer(s) have edited their scores so far.</div>
                  <div class="text-end"><Countdown :until="st.revision.closes_at" size="xl" /><div class="small text-secondary">closes {{ time(st.revision.closes_at) }}</div></div>
                </div>
                <div v-else class="text-secondary">{{ idleText }}</div>
              </div>
              <div v-if="n.state === 'current' && st.next_due_at && st.slam.auto_mode === 'auto' && !st.slam.hold && n.step" class="small text-secondary mt-2"><Icon name="status.time" cls="icon-inline me-1" />Next automatic step at {{ time(st.next_due_at) }} (<Countdown :until="st.next_due_at" :icon="false" />)</div>
            </div>

            <!-- Stage controls for the performance on stage -->
            <div v-if="stagePanel(n)" class="stage-panel card-body border-top">
              <div v-for="r in stagePanel(n)" :key="r.key" class="stage-row">
                <button type="button" class="btn btn-lg stage-btn" :class="r.enabled ? r.cls : 'btn-outline-secondary'" :disabled="blocked || !r.enabled" @click="r.run()"><Icon :name="r.icon" />{{ r.label }}</button>
                <div class="stage-hint">
                  <span class="text-secondary small">{{ r.hint }}</span><span v-if="r.done" class="badge bg-green-lt ms-2">Done</span>
                  <div v-if="r.turn" class="turnout mt-1">
                    <div class="d-flex flex-wrap justify-content-between gap-2"><strong>{{ r.turn.voted }} of {{ r.turn.checked_in }} checked in have voted</strong><span class="badge" :class="r.turn.percent >= 70 ? 'bg-green-lt' : r.turn.percent >= 40 ? 'bg-orange-lt' : 'bg-red-lt'">{{ r.turn.percent }}%</span></div>
                    <div class="progress progress-sm my-1" role="progressbar" :aria-valuenow="r.turn.percent" aria-valuemin="0" aria-valuemax="100" aria-label="Voting turnout"><div class="progress-bar" :class="r.turn.percent >= 70 ? 'bg-green' : r.turn.percent >= 40 ? 'bg-orange' : 'bg-red'" :style="{ width: r.turn.percent + '%' }"></div></div>
                    <span class="small text-secondary">{{ r.turn.not_voted }} not voted yet</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Actions on the card -->
            <div v-if="actionsFor(n).length" class="card-footer">
              <div class="btn-list">
                <template v-for="a in actionsFor(n)" :key="a.label">
                  <a v-if="a.href" class="btn" :class="a.cls" :href="a.href"><Icon v-if="a.icon" :name="a.icon" />{{ a.label }}</a>
                  <button v-else type="button" class="btn" :class="a.cls" :disabled="blocked" @click="a.run()"><Icon v-if="a.icon" :name="a.icon" />{{ a.label }}</button>
                </template>
              </div>
            </div>
          </div>
        </li>
      </template>
    </ol>
  </template>
  <ActionModal ref="modal" />
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import TablerLoading from '@vue-tabler/Loading.vue';
import { createPoller } from '../../shared/poller.js';
import { startTicker, now as clockNow, time, dateTime, tzLabel, fmtCountdown } from '../../shared/clock.js';
import { useControlActions } from './useControlActions.js';
import { toast } from '../../shared/ui.js';
import Icon from '../../shared/components/Icon.vue';
import Countdown from '../../shared/components/Countdown.vue';
import ConnBanner from '../../shared/components/ConnBanner.vue';
import TalkNow from '../../shared/components/TalkNow.vue';
import ActionModal from '../../shared/components/ActionModal.vue';

const props = defineProps({ boot: Object });
const slamId = props.boot.slam_id;
const st = reactive({ phases: [], items: [], readiness: [], alerts: [], counts: {}, phase_times: {} });
const modal = ref(null);
const serverIso = computed(() => new Date(clockNow.value).toISOString());
const dt = (iso) => (iso ? dateTime(iso) : '');

const poll = createPoller(`/api/slams/${slamId}/control-state`, {
  interval: 4000,
  onData: (d) => Object.assign(st, d),
  deadlines: () => [st.current?.closes_at, st.current?.ends_at, st.revision?.closes_at, st.next_due_at, st.slam?.checkin_closes_at, ...st.items.map((i) => i.window_closes_at)],
  onAuthError: () => { window.location.href = '/admin/login'; },
});
// Several people can run one slam: actions are blocked while this screen has no fresh view of it.
const connected = computed(() => poll.status.value === 'ok' && poll.lastOk.value !== null && clockNow.value - poll.lastOk.value < 25000);
const blocked = computed(() => busy.value || !connected.value);
const changeText = { 'slam.phase_changed': 'changed the phase', 'window.opened': 'opened a review', 'window.extended': 'changed the review time', 'window.closed.manual': 'closed a review', 'window.closed.timer': 'closed a review', 'performance.started': 'started a presentation', 'performance.ended': 'closed a presentation', 'schedule.break_started': 'started a break', 'schedule.break_ended_early': 'ended a break', 'schedule.item_skipped': 'skipped an item', 'slam.paused': 'paused the slam', 'slam.resumed': 'resumed the slam', 'phase.mode_changed': 'changed the run mode', 'phase.hold': 'held the slam', 'phase.resume': 'resumed automatic mode', 'checkin_gate.opened': 'opened the gates', 'checkin_gate.closed': 'closed the gates', 'schedule.shifted': 'adjusted the schedule' };
let seenChange = null;
watch(() => st.last_change?.id, (id) => {
  const c = st.last_change;
  if (!c || id === undefined) return;
  if (seenChange !== null && id !== seenChange && c.by_id !== st.me?.id) toast(`${c.by} ${changeText[c.action] || 'made a change'}.`, 'info');
  seenChange = id;
});
const {
  busy, api, act, itemApi, confirmApi, advance, customExtend, openItem, skip, gateExtend, gateReopen, startPerformance, endPerformance, closeGateAndStart,
  endReviewing, revisionOpen, revisionSkip, calcMarks, endEvent, reflowRemaining, pause, resume, shiftRemaining, changeTime,
} = useControlActions({ slamId, st, modal, poll });
const setMode = (mode) => { if (st.slam.auto_mode !== mode) act('mode', { mode }, mode === 'manual' ? 'Manual: every step waits for you.' : 'Automatic from the planned times.'); };

// ---- the timeline: steps before the performances, one node per performance or break, steps after ----
const BEFORE = [
  { key: 'setup', title: 'Setup', phases: ['draft', 'scheduled'], icon: 'phase.draft', plan: null, hint: 'Presenters, schedule, questions and attendees are prepared; the slam is marked ready.' },
  { key: 'open', title: 'Slam open', phases: ['open'], icon: 'phase.open', plan: 'opens_at', hint: 'Admin and volunteer panels go live.' },
  { key: 'checkin_open', title: 'Gates open', phases: ['checkin_open'], icon: 'phase.checkin_open', plan: 'checkin_opens_at', hint: 'Attendees scan the QR code on their badge to check themselves in.' },
  { key: 'checkin_closed', title: 'Gate close', phases: ['checkin_closed'], icon: 'phase.checkin_closed', plan: 'checkin_closes_at', hint: 'Check-in stops. Late arrivals are checked in by a volunteer or admin.' },
];
const AFTER = [
  { key: 'reviews_complete', title: 'All reviews closed', phases: ['reviews_complete'], icon: 'phase.reviews_complete', plan: null, hint: 'Every performance has been reviewed or skipped.' },
  { key: 'revision', title: 'Review edit', phases: ['revision'], icon: 'phase.revision', plan: null, hint: 'Reviewers can change the scores they gave.' },
  { key: 'revision_closed', title: 'Edit close', phases: ['revision_closed'], icon: 'phase.revision_closed', plan: null, hint: 'Scores are locked permanently.' },
  { key: 'closed', title: 'Slam close', phases: ['closed'], icon: 'phase.closed', plan: 'closes_at', planLabel: 'hard stop', hint: 'Everyone is checked out; reviewers see a thank-you.' },
  { key: 'marks_calculated', title: 'Marks calculated', phases: ['marks_calculated'], icon: 'phase.marks_calculated', plan: null, hint: 'The official marks snapshot is made.' },
  { key: 'ended', title: 'Event ends', phases: ['ended'], icon: 'phase.ended', plan: 'event_ends_at', hint: 'Everything becomes read-only.' },
];
const ended = computed(() => st.slam?.status === 'ended');
const curIdx = computed(() => st.phases.findIndex((p) => p.key === st.slam?.status));
const idxOf = (k) => st.phases.findIndex((p) => p.key === k);
const stepNode = (s) => {
  const idxs = s.phases.map(idxOf);
  const state = ended.value || curIdx.value > Math.max(...idxs) ? 'done' : curIdx.value >= Math.min(...idxs) ? 'current' : 'upcoming';
  const actual = s.phases.map((p) => st.phase_times?.[p]).find(Boolean) || null;

  return { key: s.key, step: s, title: s.title, icon: s.icon, hint: s.hint, state, actual, planned: s.plan ? st.slam?.[s.plan] || null : null, planLabel: s.planLabel, isNext: false };
};

const isPerforming = (i) => !!i.performing_started_at && !i.performing_ended_at && i.status !== 'skipped';
const perfs = computed(() => st.items.filter((i) => i.item_type === 'performance'));
const doneCount = computed(() => perfs.value.filter((i) => ['closed', 'skipped'].includes(i.status)).length);
const itemState = (i) => (i.status === 'skipped' ? 'skipped' : i.status === 'closed' ? 'done' : i.status === 'open' || isPerforming(i) ? 'current' : 'upcoming');
const gateIsClosed = computed(() => ['checkin_closed', 'reviewing'].includes(st.slam?.status));
const anyRunning = computed(() => st.items.some((i) => itemState(i) === 'current'));
const nextPerf = computed(() => (gateIsClosed.value && !anyRunning.value ? perfs.value.find((i) => i.status === 'pending') || null : null));
const PAUSABLE = ['open', 'checkin_open', 'checkin_closed', 'reviewing', 'reviews_complete', 'revision', 'revision_closed'];
const canPause = computed(() => !st.paused && PAUSABLE.includes(st.slam?.status));
const pausedSeconds = computed(() => (st.paused ? Math.max(0, Math.round((clockNow.value - Date.parse(st.paused.since)) / 1000)) : 0));
const canChange = (n) => !!n.item && n.item.status === 'pending' && !isPerforming(n.item) && !st.paused && gateOrBefore.value;
const gateOrBefore = computed(() => ['open', 'checkin_open', 'checkin_closed', 'reviewing'].includes(st.slam?.status));
// Behind / ahead of the ORIGINAL plan (never rewritten by pauses or shifts).
const driftMin = computed(() => {
  const d = st.drift;
  if (!d) return null;

  return d.basis === 'waiting' ? Math.round((clockNow.value - Date.parse(d.original)) / 60000) : d.minutes;
});
const scheduleClock = computed(() => {
  const m = driftMin.value;
  if (m === null || ended.value) return null;
  if (m >= 1) return { cls: 'bg-orange-lt', icon: 'status.time', text: `${m} min behind schedule`, hint: st.drift.basis === 'waiting' ? 'The next performance was planned earlier. This grows while you wait.' : 'This performance started later than the original plan.', suggest: m };
  if (m <= -1) return { cls: 'bg-green-lt', icon: 'status.ok', text: `${-m} min ahead of schedule`, hint: 'You can start the next performance early, or keep the plan.', suggest: m };

  return { cls: 'bg-secondary-lt', icon: 'status.ok', text: 'On schedule', hint: 'Compared with the original plan.', suggest: 5 };
});
const itemNode = (i) => {
  const state = itemState(i);
  const startedAt = i.performing_started_at || i.actual_open_at;
  const lateBy = state !== 'upcoming' && state !== 'skipped' && startedAt && i.item_type === 'performance' ? Math.round((Date.parse(startedAt) - Date.parse(i.original_start_at)) / 60000) : 0;
  const movedFrom = state === 'upcoming' && i.original_start_at && i.original_start_at !== i.planned_start_at ? time(i.original_start_at) : '';
  const brk = i.item_type === 'break';
  const ran = i.actual_open_at ? `${time(i.performing_started_at || i.actual_open_at)}–${time(i.performing_ended_at || i.actual_close_at)}` : '';

  return {
    key: `i${i.id}`, item: i, title: brk ? i.label : i.presenter_name, subtitle: brk ? '' : i.topic, state,
    icon: brk ? `break.${i.break_type || 'other'}` : 'person.user', isNext: nextPerf.value?.id === i.id,
    planned: i.projected_start_at || i.planned_start_at, actual: i.performing_started_at || i.actual_open_at, lateBy: Math.abs(lateBy) >= 1 ? lateBy : 0, movedFrom,
    summary: state === 'done' ? (brk ? `Ran ${ran}.` : `Performed ${ran}. Review closed ${time(i.actual_close_at)}, ${i.reviews} review(s).`) : '',
    hint: brk ? `${i.duration_minutes} min` : `${i.duration_minutes} min review window`,
  };
};
const nodes = computed(() => {
  const out = [...BEFORE.map(stepNode)];
  st.items.forEach((i, k) => { const n = itemNode(i); if (k === 0) n.groupStart = true; out.push(n); });
  out.push(...AFTER.map(stepNode));

  return out;
});

const chip = (n) => (n.state === 'done' ? { text: 'Done', cls: 'bg-green-lt', icon: 'status.ok' }
  : n.state === 'current' ? { text: 'Now', cls: 'bg-orange-lt', icon: 'status.live' }
    : n.state === 'skipped' ? { text: 'Skipped', cls: 'bg-secondary-lt', icon: 'action.skip' }
      : n.isNext ? { text: 'Up next', cls: 'bg-orange-lt', icon: 'action.advance' } : { text: 'Upcoming', cls: 'bg-secondary-lt', icon: 'status.time' });
function whenText(n) {
  if (n.state === 'done') return n.actual ? `at ${time(n.actual)}` : '';
  if (n.state === 'current') return n.actual ? `since ${time(n.actual)}` : '';
  if (n.planned) return `${n.planLabel || 'planned'} ${n.item ? time(n.planned) : dt(n.planned)}${n.movedFrom ? ` (was ${n.movedFrom})` : ''}`;

  return n.step && n.step.plan ? 'by hand' : '';
}
const perfChip = (i) => (i.performing_ended_at ? { text: `Ended ${time(i.performing_ended_at)}`, cls: 'bg-green-lt' }
  : i.performing_started_at ? { text: `On stage since ${time(i.performing_started_at)}`, cls: 'bg-orange-lt' } : { text: 'Not started', cls: 'bg-secondary-lt' });
const reviewChip = (i) => (i.status === 'closed' ? { text: `Closed · ${i.reviews} review(s)`, cls: 'bg-green-lt' }
  : i.status === 'open' ? { text: `Open · ${i.reviews} so far`, cls: 'bg-orange-lt' } : { text: 'Not open', cls: 'bg-secondary-lt' });

const checkedIn = computed(() => (st.counts.users_in || 0) + (st.counts.judges_in || 0));
const pct = (n, d) => (d ? Math.min(100, Math.round((100 * n) / d)) : 0);
const idleText = computed(() => ({
  draft: 'Setup. Mark the slam ready when everything is configured.', scheduled: 'Ready. Open the slam when you are set.', open: 'Slam open. Open the gates when people can come in.',
  checkin_open: 'Check-in gate is open.', checkin_closed: 'Gate closed.', reviewing: 'Between performances.',
  reviews_complete: st.slam?.edit_window_minutes ? `The ${st.slam.edit_window_minutes}-minute edit window opens next.` : 'The edit phase is set to be skipped; scores lock next.',
  revision_closed: 'Scores are locked. Close the slam when you are ready.', closed: 'Slam closed. Marks are calculated next.',
  marks_calculated: 'Marks calculated. End the event when the results are final.', ended: 'The event has ended. Everything is read-only.',
}[st.slam?.status] || ''));

// ---- buttons on each card ----
// The three controls for the performance on stage, in the order they are used: open the review (can be while the
// speaker is still presenting), close the presentation, close the review. Disabled ones show what is already done.
function stagePanel(n) {
  const i = n.item;
  if (!i || i.item_type !== 'performance' || st.paused || i.status === 'skipped' || i.status === 'closed') return null;
  const performing = isPerforming(i);
  const open = i.status === 'open';
  const ended = !!i.performing_ended_at;
  if (!performing && !open && !(i.status === 'pending' && ended)) return null;
  const turn = st.turnout && st.turnout.item_id === i.id ? st.turnout : null;
  return [
    { key: 'open', label: 'Open review', icon: 'status.live', cls: 'btn-primary', enabled: !open, done: open, run: () => openItem(i),
      hint: open ? 'Reviewers can vote now' : performing ? 'The speaker is still on stage; you can let reviewers start voting' : 'Let reviewers vote on this talk' },
    { key: 'end', label: 'Close presentation', icon: 'action.stop', cls: 'btn-warning', enabled: performing, done: !performing, run: () => endPerformance(i),
      hint: performing ? 'Press when the speaker has finished' : 'The presentation is over' },
    { key: 'close', label: 'Close review', icon: 'action.stop', cls: 'btn-danger', enabled: open, done: false, turn,
      run: () => confirmApi('window/close', 'Close this review window now?', 'Reviewers can no longer submit for this performance.', { note: 'closed by admin' }),
      hint: open ? 'Voting is live; closes by itself at the countdown' : 'Opens with the button above' },
  ];
}
const stagePrimary = (rows) => (rows ? rows.find((r) => r.key === (rows[0].enabled ? 'open' : rows[1].enabled ? 'end' : 'close')) : null);

// A pending break can be started when it is the next thing in the running order and nothing is on stage or in review.
const canStartBreak = (i) => st.slam.status === 'reviewing' && !anyRunning.value && st.items.find((x) => x.status === 'pending')?.id === i.id;

function itemActions(n) {
  const i = n.item;
  const out = [];
  const add = (label, cls, run, icon, href, primary = false) => out.push({ label, cls, run, icon, href, primary });
  if (i.item_type === 'break') {
    if (i.status === 'open') {
      for (const m of [5, 10]) add(`+${m} min`, '', () => itemApi(i.id, 'extend-break', { minutes: m }, `Break extended by ${m} min.`), 'action.extend');
      add('End break now', 'btn-outline-warning', () => itemApi(i.id, 'end-break', {}, 'Break ended.'));
    } else if (i.status === 'pending' && gateIsClosed.value) {
      if (canStartBreak(i)) add('Start break', 'btn-primary', () => act(`items/${i.id}/break-start`, {}, 'Break started.'), `break.${i.break_type || 'other'}`, undefined, true);
      add('Skip…', 'btn-ghost-secondary', () => skip(i), 'action.skip');
    }

    return out;
  }
  if (stagePanel(n)) {
    // Open review / Close presentation / Close review live in the stage panel; here only the extras.
    if (i.status === 'open') {
      for (const m of [1, 2, 5]) add(`+${m} min`, '', () => api('window/extend', { minutes: m }, `Extended by ${m} min.`).catch(() => {}), 'action.extend');
      add('Other…', '', () => customExtend('window/extend', 'Extend or shorten the review window'));
    } else if (i.status === 'pending') add('Skip…', 'btn-ghost-secondary', () => skip(i), 'action.skip');
  } else if (n.isNext) {
    add('Start presentation', 'btn-primary', () => startPerformance(i), 'action.resume');
    add('Open review now', '', () => openItem(i), 'status.live');
    add('Skip…', 'btn-ghost-secondary', () => skip(i), 'action.skip');
    add('End reviewing early…', 'btn-outline-danger', endReviewing);
  }

  return out;
}
function stepActions(n) {
  if (n.state !== 'current') return [];
  const out = [];
  const add = (label, cls, run, icon, href) => out.push({ label, cls, run, icon, href });
  switch (n.key) {
    case 'setup':
      if (st.advance_label) add(st.advance_label, 'btn-primary', advance, 'action.advance');
      add('Edit slam details', '', null, 'action.edit', `/admin/slams/${slamId}/edit`);
      break;
    case 'open': add('Open check-in gate', 'btn-success', () => api('checkin-gate/open', {}, 'Gate opened.').catch(() => {}), 'phase.checkin_open'); break;
    case 'checkin_open':
      add('Close gate', 'btn-warning', () => api('checkin-gate/close', {}, 'Gate closed.').catch(() => {}), 'phase.checkin_closed');
      add('Change gate close time', '', gateExtend);
      if (perfs.value.some((i) => i.status === 'pending')) add('Close gate and start first performance', 'btn-primary', closeGateAndStart, 'action.resume');
      break;
    case 'checkin_closed': add('Reopen gate', 'btn-outline-warning', gateReopen); break;
    case 'reviews_complete':
      add('Open edit window…', 'btn-primary', revisionOpen, 'phase.revision');
      add('Skip edit phase…', 'btn-outline-secondary', revisionSkip);
      break;
    case 'revision':
      for (const m of [2, 5]) add(`+${m} min`, '', () => api('revision/extend', { minutes: m }, `Extended by ${m} min.`).catch(() => {}), 'action.extend');
      add('Other…', '', () => customExtend('revision/extend', 'Extend or shorten the edit window'));
      add('Close & lock scores', 'btn-outline-danger', () => confirmApi('revision/close', 'Close the edit window now?', 'Scores will be locked permanently.'), 'phase.revision_closed');
      break;
    case 'revision_closed':
      add('Close slam', 'btn-primary', () => confirmApi('close', 'Close the slam now?', 'Everyone still checked in is checked out; reviewer screens end with a thank-you. Marks are calculated next.'), 'phase.closed');
      break;
    case 'closed': add('Calculate marks', 'btn-primary', calcMarks, 'action.calculate'); break;
    case 'marks_calculated':
      add('Marks screen', 'btn-primary', null, 'nav.marks', `/admin/slams/${slamId}/marks`);
      add('Recalculate marks', '', calcMarks, 'action.refresh');
      add('End event…', 'btn-outline-danger', endEvent, 'phase.ended');
      break;
    default: break;
  }

  return out;
}
const actionsFor = (n) => (st.paused ? [] : n.item ? itemActions(n) : stepActions(n));

// ---- the "Now" card ----
const now = computed(() => {
  const s = st.slam;
  const base = { tone: 'idle', icon: 'status.time', kicker: 'Right now', title: '', subtitle: '', timers: [], facts: [], primary: null, primaryHref: null, next: '' };
  const runItem = st.items.find((i) => i.item_type === 'performance' && itemState(i) === 'current');
  const runBreak = st.items.find((i) => i.item_type === 'break' && i.status === 'open');
  const current = nodes.value.find((n) => n.state === 'current' && n.step);
  const firstOf = (list) => list.find((a) => a.primary) || list.find((a) => !a.href && /btn-primary|btn-success|btn-warning/.test(a.cls || '')) || null;
  const nextText = (() => {
    const p = nextPerf.value || perfs.value.find((i) => i.status === 'pending' && !isPerforming(i) && i.id !== runItem?.id);
    return p ? `${p.presenter_name} (${time(p.projected_start_at || p.planned_start_at)})` : '';
  })();
  if (st.paused) {
    return {
      ...base, tone: 'paused', icon: 'action.hold', kicker: 'Paused', title: 'The slam is paused', subtitle: st.paused.reason ? `Reason: ${st.paused.reason}` : 'All timers are stopped and reviewers cannot submit.',
      primary: { label: 'Resume', cls: 'btn-success', icon: 'action.resume', run: resume, primary: true },
      facts: ['On resume the paused time is added to the running timer and the schedule moves later by it.'],
    };
  }
  if (ended.value) return { ...base, tone: 'done', icon: 'phase.ended', title: 'The event has ended', subtitle: 'Everything is read-only. Marks and exports remain available.' };
  if (runItem) {
    const open = runItem.status === 'open';
    const node = nodes.value.find((n) => n.item?.id === runItem.id);
    return {
      ...base, tone: 'live', icon: isPerforming(runItem) ? 'person.user' : 'status.live', kicker: isPerforming(runItem) ? (open ? 'On stage · review open' : 'On stage') : 'Performance over · review still open',
      title: runItem.presenter_name, subtitle: runItem.topic,
      timers: open ? [{ label: 'review closes in', until: runItem.window_closes_at }] : [],
      facts: open ? [`${runItem.reviews} review(s) so far`, `${checkedIn.value} inside`] : [],
      primary: (() => { const r = stagePrimary(stagePanel(node)); return r ? { label: r.label, cls: r.cls, icon: r.icon, run: r.run, primary: true } : firstOf(itemActions(node)); })(), next: nextText,
    };
  }
  if (runBreak) {
    const node = nodes.value.find((n) => n.item?.id === runBreak.id);
    return { ...base, tone: 'live', icon: `break.${runBreak.break_type || 'other'}`, kicker: 'Break', title: runBreak.label, timers: st.current?.ends_at ? [{ label: 'break ends in', until: st.current.ends_at }] : [], primary: firstOf(itemActions(node)), next: nextText };
  }
  const firstPending = st.items.find((x) => x.status === 'pending');
  if (gateIsClosed.value && !anyRunning.value && s.status === 'reviewing' && firstPending?.item_type === 'break') {
    const node = nodes.value.find((n) => n.item?.id === firstPending.id);
    return { ...base, tone: 'ready', icon: `break.${firstPending.break_type || 'other'}`, kicker: 'Between performances', title: `Ready for the ${firstPending.label}`, subtitle: nextText ? `Then: ${nextText}` : '', primary: firstOf(itemActions(node)), facts: [`${checkedIn.value} inside`] };
  }
  if (gateIsClosed.value && nextPerf.value) {
    const node = nodes.value.find((n) => n.item?.id === nextPerf.value.id);
    const first = !perfs.value.some((i) => i.status !== 'pending');
    return { ...base, tone: 'ready', icon: 'action.resume', kicker: first ? 'Gates closed' : 'Between performances', title: first ? 'Ready for the first performance' : 'Ready for the next performance', subtitle: `${nextPerf.value.presenter_name}: ${nextPerf.value.topic || ''}`, primary: firstOf(itemActions(node)), facts: [`${checkedIn.value} inside`] };
  }
  if (current) {
    const acts = stepActions(current);
    const timers = [];
    if (s.status === 'checkin_open' && s.checkin_closes_at) timers.push({ label: 'gate closes in', until: s.checkin_closes_at });
    if (s.status === 'revision' && st.revision) timers.push({ label: 'edit window closes in', until: st.revision.closes_at });
    return {
      ...base, tone: 'live', icon: current.icon, kicker: 'Right now', title: current.title, subtitle: idleText.value, timers,
      facts: s.status === 'checkin_open' ? [`${checkedIn.value} of ${st.counts.registered} inside`] : s.status === 'revision' ? [`${st.counts.revised} edited so far`] : [],
      primary: firstOf(acts), next: nextText,
    };
  }
  return { ...base, title: s.status_label, subtitle: idleText.value };
});

const progress = computed(() => (ended.value ? 100 : Math.round((nodes.value.filter((n) => n.state === 'done' || n.state === 'skipped').length / nodes.value.length) * 100)));
const progressText = computed(() => {
  const total = perfs.value.filter((i) => i.status !== 'skipped').length;
  const done = perfs.value.filter((i) => i.status === 'closed').length;

  return total ? `${st.slam?.status_label} · ${done} of ${total} performances reviewed` : st.slam?.status_label;
});

onMounted(() => { startTicker(); poll.start(); });
</script>
