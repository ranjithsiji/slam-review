// Client mirror of ScheduleService::reflow/validate (server re-validates on save).
import { addMinutes } from '../../shared/clock.js';

const t = (iso) => Date.parse(iso);
export const endOf = (it) => addMinutes(it.planned_start_at, Number(it.duration_minutes) || 0);
export const isLockedSlot = (it) => it.status === 'open' || it.status === 'closed';

/** Re-flow from index `from`: each slot starts at the previous end (+changeover between performances); pinned/locked keep their time. */
export function reflow(items, changeover, from = 0, movePinned = false) {
  const out = items.map((x) => ({ ...x }));
  const errors = [];
  let prev = null;
  out.forEach((it, i) => {
    if (it.status === 'skipped') return;
    if (i > from && prev) {
      const gap = prev.item_type === 'performance' && it.item_type === 'performance' ? changeover : 0;
      const earliest = addMinutes(endOf(prev), gap);
      const fixed = it.status !== 'pending' || (it.is_pinned && !movePinned);
      if (fixed) {
        if (t(it.planned_start_at) < t(earliest)) errors.push({ index: i, message: it.status === 'pending' ? 'Pinned slot cannot fit: the previous slots end after its pinned time.' : 'A slot that already ran cannot move.' });
      } else {
        it.planned_start_at = earliest;
      }
    }
    prev = it;
  });
  return { items: out, errors };
}

export function validate(slam, items, perfIds) {
  const errors = [];
  const warnings = [];
  const seen = new Set();
  const gate = t(slam.checkin_closes_at || slam.opens_at);
  const limit = t(slam.closes_at);
  let prevEnd = null;
  const active = [];
  items.forEach((it, i) => {
    const d = Number(it.duration_minutes);
    if (!(d >= 1)) errors.push({ index: i, message: 'Duration must be at least 1 minute.' });
    if (it.item_type === 'performance') {
      if (!perfIds.has(it.performance_id)) errors.push({ index: i, message: 'Unknown performance.' });
      else if (seen.has(it.performance_id)) errors.push({ index: i, message: 'This presenter is already in the schedule.' });
      seen.add(it.performance_id);
      if (d > 0 && d < 2) warnings.push({ index: i, message: 'Very short review window.' });
      else if (d > 30) warnings.push({ index: i, message: 'Very long review window.' });
    }
    if (it.status === 'skipped') return;
    const s = t(it.planned_start_at);
    if (prevEnd !== null && s < prevEnd) errors.push({ index: i, message: 'Overlaps the previous slot.' });
    if (s < gate && it.status === 'pending') errors.push({ index: i, message: 'Starts before Gate Close.' });
    prevEnd = s + d * 60000;
    active.push(i);
  });
  if (prevEnd !== null) {
    const withEdit = prevEnd + (slam.edit_window_minutes || 0) * 60000;
    const last = active[active.length - 1];
    if (withEdit > limit) errors.push({ index: last, message: 'Schedule plus edit window runs past the slam close time.' });
    else if (limit - withEdit < 600000) warnings.push({ index: last, message: 'Less than 10 minutes margin before slam close.' });
  }
  if (active.length) {
    if (items[active[0]].item_type === 'break') warnings.push({ index: active[0], message: 'The schedule starts with a break.' });
    const last = active[active.length - 1];
    if (items[last].item_type === 'break') warnings.push({ index: last, message: 'The schedule ends with a break.' });
  }
  return { errors, warnings };
}

export function summary(slam, items) {
  const act = items.filter((i) => i.status !== 'skipped');
  if (!act.length) return { first: null, end: null, perfs: 0, breaks: 0, minutes: 0, margin: null };
  const first = act[0].planned_start_at;
  const end = act.reduce((m, i) => (t(endOf(i)) > t(m) ? endOf(i) : m), endOf(act[0]));
  const margin = Math.round((t(slam.closes_at) - t(end)) / 60000) - (slam.edit_window_minutes || 0);
  return {
    first, end, margin,
    perfs: act.filter((i) => i.item_type === 'performance').length,
    breaks: act.filter((i) => i.item_type === 'break').length,
    minutes: Math.round((t(end) - t(first)) / 60000),
  };
}

export function nextStart(slam, items, type = 'performance') {
  const act = items.filter((i) => i.status !== 'skipped');
  if (!act.length) return slam.reviews_start_at || slam.checkin_closes_at || addMinutes(slam.opens_at, 60);
  const last = act[act.length - 1];
  const gap = last.item_type === 'performance' && type === 'performance' ? slam.changeover_minutes : 0;
  return addMinutes(endOf(last), gap);
}

export const BREAK_LABELS = { tea: 'Tea break', lunch: 'Lunch break', other: 'Break' };
