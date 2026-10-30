<template>
  <ConnBanner :status="poll.status.value" />
  <TablerLoading v-if="!st.slam" desc="Loading the control panel" />
  <template v-else>
    <!-- Timeline + mode -->
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
          <StatusBadge group="phase" :value="st.slam.status" extra="fs-4" />
          <span class="badge" :class="modeBadge.cls">{{ modeBadge.text }}</span>
          <span class="text-secondary small ms-auto">Server time {{ time(serverIso) }} IST</span>
        </div>
        <div class="phase-steps mb-3" aria-label="Slam timeline">
          <span v-for="p in st.phases" :key="p.key" class="badge" :class="[phaseIdx(p.key) < phaseIdx(st.slam.status) ? 'bg-green-lt' : phaseIdx(p.key) === phaseIdx(st.slam.status) ? 'bg-primary-lt current' : 'bg-secondary-lt']">{{ p.label }}</span>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button v-if="st.advance_label" type="button" class="btn btn-primary" :disabled="busy" @click="advance"><Icon name="action.advance" />{{ st.advance_label }}</button>
          <button v-if="!st.slam.hold" type="button" class="btn btn-outline-warning" :disabled="busy" @click="act('hold', {}, 'Held: the system will not start the next step by itself.')"><Icon name="action.hold" />Hold next step</button>
          <button v-else type="button" class="btn btn-success" :disabled="busy" @click="act('resume', {}, 'Resumed.')"><Icon name="action.resume" />Resume auto</button>
          <button type="button" class="btn btn-outline-secondary" :disabled="busy" @click="act('mode', { mode: st.slam.auto_mode === 'auto' ? 'manual' : 'auto' }, 'Mode changed.')">
            {{ st.slam.auto_mode === 'auto' ? 'Switch to manual' : 'Switch to auto' }}
          </button>
        </div>
        <div v-if="st.next_due_at && st.slam.auto_mode === 'auto' && !st.slam.hold" class="small text-secondary mt-2">Next automatic step at {{ time(st.next_due_at) }} (<Countdown :until="st.next_due_at" :icon="false" />)</div>
      </div>
    </div>

    <!-- Alerts -->
    <div v-if="st.readiness.length" class="alert alert-warning"><h4 class="alert-title">Not ready to open</h4><ul class="mb-0"><li v-for="m in st.readiness" :key="m">{{ m }}</li></ul></div>
    <div v-if="st.past_hard_stop" class="alert alert-danger"><Icon name="status.warning" cls="me-1" />Projected end plus the edit window runs past the hard stop at {{ time(st.slam.closes_at) }}. Consider shortening windows or skipping.</div>
    <div v-if="st.late_minutes > 0" class="alert alert-warning d-flex align-items-center gap-2 flex-wrap">
      <Icon name="status.time" /><div class="me-auto">Running late by <strong>{{ st.late_minutes }} min</strong>. Projected end {{ time(st.projected_end) }}.</div>
      <button type="button" class="btn btn-warning" @click="reflowRemaining">Re-flow remaining</button>
    </div>
    <div v-for="a in st.alerts.slice(0, 2)" :key="a.id" class="alert alert-info small py-2"><Icon name="status.info" cls="icon-inline me-1" />{{ time(a.at) }} — {{ alertText(a) }}</div>

    <div class="row row-cards">
      <!-- Current activity -->
      <div class="col-12 col-lg-7">
        <div class="card h-100">
          <div class="card-header"><h3 class="card-title">Now</h3></div>
          <div class="card-body">
            <template v-if="st.window">
              <div class="d-flex align-items-center gap-3 mb-3">
                <img v-if="currentItem?.thumb" :src="currentItem.thumb" alt="" class="avatar avatar-lg avatar-photo">
                <div class="flex-fill">
                  <div class="badge bg-red-lt mb-1"><Icon name="status.live" cls="icon-inline me-1" />Review window open</div>
                  <div class="fw-bold fs-3 ml-text">{{ currentItem?.presenter_name }}</div>
                  <div class="text-secondary ml-text">{{ currentItem?.topic }}</div>
                </div>
                <div class="text-end"><Countdown :until="st.window.closes_at" size="xl" /><div class="small text-secondary">closes {{ time(st.window.closes_at) }}</div></div>
              </div>
              <div class="mb-3"><strong>{{ st.window.reviews }}</strong> review(s) so far · {{ st.counts.users_in + st.counts.judges_in }} checked in<span v-if="st.window.extended_minutes"> · extended {{ st.window.extended_minutes }} min</span></div>
              <div class="btn-list">
                <button v-for="m in [1, 2, 5]" :key="m" type="button" class="btn" :disabled="busy" @click="api(`window/extend`, { minutes: m }, `Extended by ${m} min.`)"><Icon name="action.extend" />+{{ m }} min</button>
                <button type="button" class="btn" @click="customExtend('window/extend', 'Extend or shorten the review window')">Other…</button>
                <button type="button" class="btn btn-outline-danger" @click="confirmApi('window/close', 'Close this review window now?', 'Reviewers can no longer submit for this performance.', { note: 'closed by admin' })"><Icon name="action.stop" />Close now</button>
              </div>
            </template>
            <template v-else-if="st.current?.kind === 'break'">
              <div class="d-flex align-items-center gap-3 mb-3">
                <Icon :name="`break.${st.current.break_type || 'other'}`" cls="icon-xl text-orange" />
                <div class="flex-fill"><div class="fw-bold fs-3 ml-text">{{ st.current.label }}</div><div class="text-secondary">until {{ time(st.current.ends_at) }}</div></div>
                <Countdown :until="st.current.ends_at" size="xl" />
              </div>
              <div class="btn-list">
                <button v-for="m in [5, 10]" :key="m" type="button" class="btn" @click="itemApi(st.current.schedule_item_id, 'extend-break', { minutes: m }, `Break extended by ${m} min.`)">+{{ m }} min</button>
                <button type="button" class="btn btn-outline-warning" @click="itemApi(st.current.schedule_item_id, 'end-break', {}, 'Break ended.')">End break now</button>
              </div>
            </template>
            <template v-else-if="st.slam.status === 'revision' && st.revision">
              <div class="d-flex align-items-center gap-3 mb-3">
                <Icon name="phase.revision" cls="icon-xl text-purple" />
                <div class="flex-fill"><div class="fw-bold fs-3">Edit window open</div><div class="text-secondary">{{ st.counts.revised }} reviewer(s) have edited so far</div></div>
                <Countdown :until="st.revision.closes_at" size="xl" />
              </div>
              <div class="btn-list">
                <button v-for="m in [2, 5]" :key="m" type="button" class="btn" @click="api('revision/extend', { minutes: m }, `Extended by ${m} min.`)">+{{ m }} min</button>
                <button type="button" class="btn" @click="customExtend('revision/extend', 'Extend or shorten the edit window')">Other…</button>
                <button type="button" class="btn btn-outline-danger" @click="confirmApi('revision/close', 'Close the edit window now?', 'Scores will be locked permanently.')">Close &amp; lock scores</button>
              </div>
            </template>
            <div v-else class="text-secondary">{{ idleText }}</div>

            <hr>
            <!-- Phase-specific actions -->
            <div class="btn-list">
              <template v-if="st.slam.status === 'open'">
                <button type="button" class="btn btn-success" @click="api('checkin-gate/open', {}, 'Gate opened.')"><Icon name="phase.checkin_open" />Open check-in gate</button>
              </template>
              <template v-if="st.slam.status === 'checkin_open'">
                <button type="button" class="btn btn-warning" @click="api('checkin-gate/close', {}, 'Gate closed.')"><Icon name="phase.checkin_closed" />Close gate</button>
                <button type="button" class="btn" @click="gateExtend">Change gate close time</button>
                <button type="button" class="btn btn-primary" @click="openWindowModal(true)"><Icon name="status.live" />Close check-in and open review</button>
              </template>
              <template v-if="st.slam.status === 'checkin_closed'">
                <button type="button" class="btn btn-outline-warning" @click="gateReopen">Reopen gate</button>
              </template>
              <template v-if="['checkin_closed', 'reviewing'].includes(st.slam.status) && !st.window">
                <button type="button" class="btn btn-primary" @click="openWindowModal(false)"><Icon name="status.live" />Open next performance…</button>
              </template>
              <template v-if="st.slam.status === 'reviewing' && !st.window">
                <button type="button" class="btn btn-outline-danger" @click="endReviewing">End reviewing early…</button>
              </template>
              <template v-if="st.slam.status === 'reviews_complete'">
                <button type="button" class="btn btn-primary" @click="revisionOpen">Open edit window…</button>
                <button type="button" class="btn btn-outline-secondary" @click="revisionSkip">Skip edit phase…</button>
              </template>
              <template v-if="st.slam.status === 'revision_closed'">
                <button type="button" class="btn btn-primary" @click="confirmApi('close', 'Close the slam now?', 'Everyone still checked in is checked out; reviewer screens end with a thank-you. Marks are calculated next.')">Close slam</button>
              </template>
              <template v-if="['closed', 'marks_calculated'].includes(st.slam.status)">
                <button type="button" class="btn btn-primary" @click="calcMarks">{{ st.slam.status === 'closed' ? 'Calculate marks' : 'Recalculate marks' }}</button>
                <a class="btn" :href="`/admin/slams/${slamId}/marks`">Marks screen</a>
              </template>
              <template v-if="st.slam.status === 'marks_calculated'">
                <button type="button" class="btn btn-outline-danger" @click="endEvent">End event…</button>
              </template>
            </div>
          </div>
        </div>
      </div>

      <!-- Numbers -->
      <div class="col-12 col-lg-5">
        <div class="card mb-3">
          <div class="card-body row text-center g-0">
            <div class="col"><div class="h1 m-0">{{ st.counts.users_in }}</div><div class="small text-secondary">users in</div></div>
            <div class="col"><div class="h1 m-0">{{ st.counts.judges_in }}</div><div class="small text-secondary">judges in</div></div>
            <div class="col"><div class="h1 m-0">{{ st.counts.registered }}</div><div class="small text-secondary">registered</div></div>
          </div>
        </div>
        <div class="card">
          <div class="list-group list-group-flush small">
            <div class="list-group-item d-flex"><span class="me-auto text-secondary">Slam open</span>{{ dt(st.slam.opens_at) }}</div>
            <div class="list-group-item d-flex"><span class="me-auto text-secondary">Gates open</span>{{ dt(st.slam.checkin_opens_at) || 'by hand' }}</div>
            <div class="list-group-item d-flex"><span class="me-auto text-secondary">Gate close</span>{{ dt(st.slam.checkin_closes_at) || 'by hand' }}</div>
            <div class="list-group-item d-flex"><span class="me-auto text-secondary">Hard stop</span><strong>{{ dt(st.slam.closes_at) }}</strong></div>
            <div class="list-group-item d-flex"><span class="me-auto text-secondary">Projected end</span>{{ dt(st.projected_end) || '—' }}</div>
          </div>
        </div>
      </div>

      <!-- Running order -->
      <div class="col-12">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Running order</h3>
            <div class="card-actions"><a :href="`/admin/slams/${slamId}/schedule`" class="btn btn-sm"><Icon name="nav.schedule" />Schedule maker</a></div></div>
          <div class="list-group list-group-flush">
            <div v-for="it in st.items" :key="it.id" class="list-group-item slot-card" :class="[`slot-${it.item_type === 'break' ? 'break' : it.status}`]">
              <div class="d-flex flex-wrap align-items-center gap-3">
                <div style="min-width:6.5rem">
                  <div class="fw-bold">{{ time(it.planned_start_at) }} <span class="text-secondary fw-normal">· {{ it.duration_minutes }}m</span> <Icon v-if="it.is_pinned" name="action.pin" cls="icon-inline text-secondary" label="pinned" /></div>
                  <div v-if="it.status === 'pending' && it.projected_start_at && it.projected_start_at !== it.planned_start_at" class="small text-warning">expected {{ time(it.projected_start_at) }}</div>
                  <div v-if="it.actual_open_at" class="small text-secondary">ran {{ time(it.actual_open_at) }}–{{ time(it.actual_close_at) }}</div>
                </div>
                <Icon v-if="it.item_type === 'break'" :name="`break.${it.break_type || 'other'}`" cls="text-orange" />
                <div class="flex-fill min-w-0">
                  <div class="fw-bold ml-text text-truncate">{{ it.item_type === 'break' ? it.label : it.presenter_name }}</div>
                  <div v-if="it.topic" class="small text-secondary ml-text text-truncate">{{ it.topic }}</div>
                </div>
                <span v-if="it.item_type === 'performance' && it.status !== 'pending'" class="badge bg-secondary-lt">{{ it.reviews }} reviews</span>
                <StatusBadge group="slot" :value="it.status" />
                <div v-if="it.status === 'pending'" class="btn-list">
                  <button v-if="it.item_type === 'performance' && ['checkin_closed', 'reviewing'].includes(st.slam.status) && !st.window" type="button" class="btn btn-sm" @click="openItem(it)">Open now</button>
                  <button type="button" class="btn btn-sm btn-ghost-secondary" @click="skip(it)"><Icon name="action.skip" />Skip</button>
                </div>
              </div>
            </div>
            <div v-if="!st.items.length" class="list-group-item text-secondary">No schedule yet.</div>
          </div>
        </div>
      </div>
    </div>
  </template>
  <ActionModal ref="modal" />
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import TablerLoading from '@vue-tabler/Loading.vue';
import { post } from '../../shared/api.js';
import { createPoller } from '../../shared/poller.js';
import { startTicker, now, time, dateTime, isoToIst, istToIso } from '../../shared/clock.js';
import { toast } from '../../shared/ui.js';
import Icon from '../../shared/components/Icon.vue';
import StatusBadge from '../../shared/components/StatusBadge.vue';
import Countdown from '../../shared/components/Countdown.vue';
import ConnBanner from '../../shared/components/ConnBanner.vue';
import ActionModal from '../../shared/components/ActionModal.vue';

const props = defineProps({ boot: Object });
const slamId = props.boot.slam_id;
const st = reactive({ phases: [], items: [], readiness: [], alerts: [], counts: {} });
const busy = ref(false);
const modal = ref(null);
const serverIso = computed(() => new Date(now.value).toISOString());
const dt = (iso) => (iso ? dateTime(iso) : '');

const poll = createPoller(`/api/slams/${slamId}/control-state`, {
  interval: 4000,
  onData: (d) => Object.assign(st, d),
  deadlines: () => [st.window?.closes_at, st.current?.ends_at, st.revision?.closes_at, st.next_due_at],
  onAuthError: () => { window.location.href = '/admin/login'; },
});

const phaseIdx = (k) => st.phases.findIndex((p) => p.key === k);
const modeBadge = computed(() => (st.slam?.auto_mode === 'manual' ? { text: 'MANUAL', cls: 'bg-orange-lt' } : st.slam?.hold ? { text: 'HELD', cls: 'bg-orange-lt' } : { text: 'AUTO', cls: 'bg-blue-lt' }));
const currentItem = computed(() => st.items.find((i) => i.id === st.window?.schedule_item_id));
const idleText = computed(() => ({
  draft: 'Setup. Mark the slam ready when everything is configured.', scheduled: 'Waiting for the slam open time.', open: 'Slam open. Waiting for gates to open.',
  checkin_open: 'Check-in gate is open.', checkin_closed: 'Gate closed. The first performance opens at its planned time.', reviewing: 'Between performances.',
  reviews_complete: 'All reviews closed.', revision_closed: 'Scores are locked. The slam closes after the grace period.', closed: 'Slam closed.',
  marks_calculated: 'Marks calculated. End the event when results are final.', ended: 'Event ended. Everything is read-only.',
}[st.slam?.status] || ''));
const alertText = (a) => ({ readiness_failed: `Readiness check failed: ${(a.meta?.missing || []).join(' ')}`, late_resume: `Resumed late after downtime (${Math.round((a.meta?.late_by_seconds || 0) / 60)} min); the window got its full duration.` }[a.reason] || a.reason);

async function api(path, body = {}, ok = 'Done.') {
  busy.value = true;
  try {
    await post(`/api/slams/${slamId}/${path}`, body);
    toast(ok);
    poll.now();
  } catch (e) {
    toast(e.message, 'error');
    throw e;
  } finally {
    busy.value = false;
  }
}
const act = (path, body, ok) => api(path, body, ok).catch(() => {});
async function itemApi(id, path, body, ok) {
  try { await post(`/api/schedule/items/${id}/${path}`, body); toast(ok); poll.now(); } catch (e) { toast(e.message, 'error'); }
}
const confirmApi = (path, title, message, body = {}) => modal.value.show({ title, message, danger: true, confirmText: 'Confirm', run: () => api(path, body) });
const advance = () => modal.value.show({ title: st.advance_label, message: 'Start this step immediately?', confirmText: 'Advance now', run: () => api('advance', {}, 'Advanced.') });
const customExtend = (path, title) => modal.value.show({
  title, fields: [{ name: 'minutes', label: 'Minutes (negative shortens)', type: 'number', required: true, value: 3 }],
  run: (v) => api(path, { minutes: Number(v.minutes) }, 'Updated.'),
});
function openWindowModal(closeGate) {
  const next = st.items.find((i) => i.status === 'pending' && i.item_type === 'performance');
  if (!next) { toast('No pending performance.', 'error'); return; }
  openItem(next, closeGate);
}
function openItem(it, closeGate = false) {
  modal.value.show({
    title: `Open review: ${it.presenter_name}`, message: closeGate ? 'This also closes the check-in gate.' : '',
    fields: [{ name: 'minutes', label: 'Review window (minutes)', type: 'number', required: true, value: it.duration_minutes }],
    run: (v) => api('window/open', { schedule_item_id: it.id, minutes: Number(v.minutes), close_gate: closeGate }, 'Review window opened.'),
  });
}
const skip = (it) => modal.value.show({
  title: `Skip ${it.item_type === 'break' ? it.label : it.presenter_name}?`, danger: true, confirmText: 'Skip',
  fields: [{ name: 'reason', label: 'Reason', required: true }],
  run: (v) => api(`items/${it.id}/skip`, { reason: v.reason }, 'Skipped.'),
});
function gateExtend() {
  const cur = isoToIst(st.slam.checkin_closes_at || new Date(Date.now() + 15 * 60000).toISOString());
  modal.value.show({
    title: 'Change gate close time', fields: [{ name: 'time', label: 'New close time (IST)', type: 'time', required: true, value: cur.time }],
    run: (v) => api('checkin-gate/extend', { closes_at: istToIso(cur.date || isoToIst(new Date().toISOString()).date, v.time) }, 'Gate close time changed.'),
  });
}
const gateReopen = () => modal.value.show({
  title: 'Reopen the check-in gate', message: 'Only possible before the first review window opens.', confirmText: 'Reopen',
  fields: [{ name: 'reason', label: 'Reason', required: true }, { name: 'minutes', label: 'Close again after (minutes, blank = by hand)', type: 'number' }],
  run: (v) => api('checkin-gate/reopen', { reason: v.reason, closes_at: v.minutes ? new Date(Date.now() + Number(v.minutes) * 60000).toISOString() : null }, 'Gate reopened.'),
});
const endReviewing = () => modal.value.show({
  title: 'End reviewing early', message: 'All remaining pending performances are skipped. This cannot be undone.', danger: true, confirmText: 'Skip the rest',
  fields: [{ name: 'reason', label: 'Reason', required: true }], run: (v) => api('end-reviewing', { reason: v.reason }, 'Reviewing ended.'),
});
const revisionOpen = () => modal.value.show({
  title: 'Open the edit window', fields: [{ name: 'minutes', label: 'Minutes', type: 'number', required: true, value: st.slam.edit_window_minutes || 10 }],
  run: (v) => api('revision/open', { minutes: Number(v.minutes) }, 'Edit window opened.'),
});
const revisionSkip = () => modal.value.show({
  title: 'Skip the edit phase', message: 'Scores lock immediately.', danger: true, confirmText: 'Skip & lock',
  fields: [{ name: 'reason', label: 'Reason', required: true }], run: (v) => api('revision/skip', { reason: v.reason }, 'Edit phase skipped; scores locked.'),
});
const calcMarks = () => modal.value.show({
  title: st.slam.status === 'closed' ? 'Calculate marks' : 'Recalculate marks', message: 'Creates a new immutable snapshot; older runs are kept.',
  fields: [{ name: 'note', label: 'Note (optional)' }], run: (v) => api('marks/calculate', { note: v.note }, 'Marks calculated.'),
});
const endEvent = () => modal.value.show({
  title: 'End the event', message: 'Everything becomes read-only and the current marks run is marked final. This cannot be undone.', danger: true, confirmText: 'End event',
  fields: [{ name: 'confirm', label: 'Type END to confirm', required: true }], run: (v) => api('end', { confirm: v.confirm }, 'Event ended.'),
});
function reflowRemaining() {
  modal.value.show({
    title: `Re-flow remaining by ${st.late_minutes} min?`, message: 'Shifts the planned times of all pending, unpinned slots by the current delay (public schedule updates too).', confirmText: 'Re-flow',
    run: async () => {
      const res = await post(`/api/slams/${slamId}/schedule/reflow-remaining`, { preview: false });
      if (res.errors?.length) throw new Error(res.errors[0].message);
      toast(`Re-flowed ${res.changes.length} slot(s).`);
      poll.now();
    },
  });
}

onMounted(() => { startTicker(); poll.start(); });
</script>
