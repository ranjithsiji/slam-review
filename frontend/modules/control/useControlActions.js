// Control actions shared by the control panel and the Status timeline: every
// admin step (gate, review windows, edit window, close, marks, end) with its
// confirmation dialog. All of them go through the SlamPhaseService APIs.
import { ref } from 'vue';
import { post } from '../../shared/api.js';
import { toast } from '../../shared/ui.js';
import { localToIso, isoToLocal, tzLabel } from '../../shared/clock.js';

/**
 * @param {{slamId: number, st: object, modal: import('vue').Ref, poll: {now: Function}}} ctx
 */
export function useControlActions({ slamId, st, modal, poll }) {
  const busy = ref(false);
  async function api(path, body = {}, ok = 'Done.') {
    busy.value = true;
    try {
      await post(`/api/slams/${slamId}/${path}`, body);
      toast(ok);
      poll.now();
      window.dispatchEvent(new Event('slam:changed'));
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
    const cur = isoToLocal(st.slam.checkin_closes_at || new Date(Date.now() + 15 * 60000).toISOString());
    modal.value.show({
      title: 'Change gate close time', fields: [{ name: 'time', label: `New close time (${tzLabel})`, type: 'time', required: true, value: cur.time }],
      run: (v) => api('checkin-gate/extend', { closes_at: localToIso(cur.date || isoToLocal(new Date().toISOString()).date, v.time) }, 'Gate close time changed.'),
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

  // A performance on stage; the review window is opened and closed separately.
  const startPerformance = (it) => api(`items/${it?.id ?? 0}/perform/start`, {}, 'Performance started.').catch(() => {});
  const endPerformance = (it) => api(`items/${it.id}/perform/end`, {}, 'Performance ended. The review stays open.').catch(() => {});
  const closeGateAndStart = () => api('items/0/perform/start', { close_gate: true }, 'Gate closed. First performance started.').catch(() => {});

  return {
    startPerformance, endPerformance, closeGateAndStart,
    busy, api, act, itemApi, confirmApi, advance, customExtend, openWindowModal, openItem, skip, gateExtend, gateReopen,
    endReviewing, revisionOpen, revisionSkip, calcMarks, endEvent, reflowRemaining,
  };
}
