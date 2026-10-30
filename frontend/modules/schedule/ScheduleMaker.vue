<template>
  <TablerLoading v-if="!loaded" desc="Loading the schedule" />
  <template v-else>
    <!-- Summary strip -->
    <div class="card mb-3">
      <div class="card-body py-2">
        <div class="row g-2 text-center small">
          <div class="col-4 col-md-2"><div class="text-secondary">First start</div><div class="fw-bold">{{ time(sum.first) || '—' }}</div></div>
          <div class="col-4 col-md-2"><div class="text-secondary">{{ live ? 'Projected end' : 'Planned end' }}</div><div class="fw-bold">{{ time(live && data.projection?.projected_end ? data.projection.projected_end : sum.end) || '—' }}</div></div>
          <div class="col-4 col-md-2"><div class="text-secondary">Performances</div><div class="fw-bold">{{ sum.perfs }}</div></div>
          <div class="col-4 col-md-2"><div class="text-secondary">Breaks</div><div class="fw-bold">{{ sum.breaks }}</div></div>
          <div class="col-4 col-md-2"><div class="text-secondary">Running time</div><div class="fw-bold">{{ sum.minutes }} min</div></div>
          <div class="col-4 col-md-2"><div class="text-secondary">Margin to close</div><div class="fw-bold" :class="sum.margin !== null && sum.margin < 0 ? 'text-danger' : sum.margin !== null && sum.margin < 10 ? 'text-warning' : ''">{{ sum.margin === null ? '—' : `${sum.margin} min` }}</div></div>
        </div>
      </div>
      <div v-if="live && !data.locked" class="card-footer small d-flex flex-wrap gap-2 align-items-center">
        <span class="badge bg-red-lt"><Icon name="status.live" cls="icon-inline me-1" />Live mode</span>
        <span>Slots that ran or are running are locked; pending slots stay editable.</span>
        <span v-if="data.projection?.late_minutes > 0" class="badge bg-orange-lt">Running late by {{ data.projection.late_minutes }} min</span>
        <a class="btn btn-sm ms-auto" :href="`/admin/slams/${slamId}/status`">Status</a>
      </div>
      <div v-if="data.locked" class="card-footer small text-secondary">Reviewing has finished; the schedule is read-only.</div>
    </div>

    <div v-if="dirty" class="alert alert-warning d-flex align-items-center gap-2 py-2">
      <Icon name="status.warning" /><span class="me-auto">Unsaved changes (draft v{{ baseVersion }}). Judges and the public see only saved versions.</span>
      <button type="button" class="btn btn-sm" @click="discard">Discard</button>
    </div>

    <!-- Toolbar -->
    <div class="d-flex flex-wrap gap-2 mb-3 no-print">
      <div class="btn-group">
        <button type="button" class="btn btn-primary" :disabled="readOnly" @click="addPresenter"><Icon name="action.add" />Add presenter</button>
        <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-label="More add options" :disabled="readOnly"></button>
        <div class="dropdown-menu">
          <button type="button" class="dropdown-item" @click="addExisting">Add existing presenter…</button>
          <button v-for="p in unscheduled" :key="p.id" type="button" class="dropdown-item" @click="addSlot(p.id)">↳ {{ p.presenter_name }} (not in schedule)</button>
        </div>
      </div>
      <button type="button" class="btn" :disabled="readOnly" @click="addBreak('tea')"><Icon name="break.tea" />Tea</button>
      <button type="button" class="btn" :disabled="readOnly" @click="addBreak('lunch')"><Icon name="break.lunch" />Lunch</button>
      <button type="button" class="btn" :disabled="readOnly" @click="addBreak('other')"><Icon name="break.other" />Break</button>
      <div class="dropdown">
        <button type="button" class="btn dropdown-toggle" data-bs-toggle="dropdown">More</button>
        <div class="dropdown-menu">
          <button type="button" class="dropdown-item" :disabled="readOnly || live" @click="openGenerate"><Icon name="action.shuffle" cls="me-2" />Generate schedule…</button>
          <button type="button" class="dropdown-item" :disabled="readOnly" @click="reflowAll"><Icon name="action.refresh" cls="me-2" />Re-flow times</button>
          <button v-if="live" type="button" class="dropdown-item" @click="reflowRemaining"><Icon name="status.time" cls="me-2" />Re-flow remaining (delay)</button>
          <button type="button" class="dropdown-item" :disabled="live" @click="openHistory"><Icon name="action.history" cls="me-2" />History…</button>
          <button v-if="boot.other_slams.length" type="button" class="dropdown-item" :disabled="readOnly || live" @click="copyStructure"><Icon name="action.copy" cls="me-2" />Copy structure from slam…</button>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item" :href="`/admin/slams/${slamId}/schedule/export.pdf`"><Icon name="action.print" cls="me-2" />Run-sheet PDF</a>
          <a class="dropdown-item" :href="`/admin/slams/${slamId}/schedule/export.csv`"><Icon name="action.export" cls="me-2" />Run-sheet CSV</a>
          <a class="dropdown-item" :href="boot.public_url" target="_blank" rel="noopener"><Icon name="action.open" cls="me-2" />Public schedule {{ boot.is_public ? '' : '(not published)' }}</a>
        </div>
      </div>
      <button type="button" class="btn btn-ghost-secondary" :disabled="!undoStack.length" @click="undo" aria-label="Undo"><Icon name="action.undo" /></button>
      <button type="button" class="btn btn-ghost-secondary" :disabled="!redoStack.length" @click="redo" aria-label="Redo"><Icon name="action.redo" /></button>
      <label class="form-check form-switch ms-auto align-self-center m-0"><input class="form-check-input" type="checkbox" v-model="scaled"><span class="form-check-label">Time-scaled</span></label>
    </div>

    <!-- Bulk -->
    <div v-if="selected.size" class="card card-sm mb-3 border-primary">
      <div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <strong>{{ selected.size }} selected</strong>
        <input type="number" min="1" max="240" class="form-control" style="max-width:8rem" placeholder="minutes" v-model.number="bulkMinutes" aria-label="Duration for selected">
        <button type="button" class="btn btn-sm" :disabled="!bulkMinutes" @click="bulk({ duration_minutes: bulkMinutes })">Set duration</button>
        <button type="button" class="btn btn-sm" @click="bulk({ is_pinned: true })">Pin</button>
        <button type="button" class="btn btn-sm" @click="bulk({ is_pinned: false })">Unpin</button>
        <button type="button" class="btn btn-sm" @click="bulk({ status: 'skipped' })">Skip</button>
        <button type="button" class="btn btn-sm btn-outline-danger" @click="bulkRemove">Remove</button>
        <button type="button" class="btn btn-sm btn-link ms-auto" @click="selected.clear()">Clear</button>
      </div>
    </div>

    <div v-if="unscheduled.length && !readOnly" class="alert alert-info d-flex flex-wrap align-items-center gap-2">
      <Icon name="status.info" />
      <div class="me-auto"><strong>{{ unscheduled.length }} presenter{{ unscheduled.length === 1 ? '' : 's' }} in the pool {{ unscheduled.length === 1 ? 'is' : 'are' }} not in the schedule yet.</strong> Add them one by one from the pool, drag them in, or let Generate place them all.</div>
      <button type="button" class="btn btn-sm" @click="addAllUnscheduled">Add all to the end</button>
      <button type="button" class="btn btn-sm btn-primary" @click="openGenerate"><Icon name="action.shuffle" />Generate schedule…</button>
    </div>

    <div class="row g-3">
      <div class="col-12" :class="{ 'col-lg-8': poolVisible }">
        <!-- Timeline -->
        <div v-if="!draft.length" class="empty border rounded">
          <div class="empty-icon"><Icon name="nav.schedule" cls="icon-xl" /></div>
          <p class="empty-title">No slots yet</p>
          <p class="empty-subtitle text-secondary">Add presenters and breaks, or use Generate to build the whole running order.</p>
        </div>
        <SlotCard v-for="(it, i) in draft" :key="it._k" :it="it" :perf="perfById[it.performance_id]" :index="i" :count="draft.length"
          :editing="editing === it._k" :selected="selected.has(it._k)" :scaled="scaled" :messages="messagesFor(i)" :projected="projectedFor(it)"
          :draggable="!readOnly && !isLocked(it) && wide" :drop-target="dropIndex === i" :dragging="dragIndex === i"
          @edit="editing = editing === it._k ? null : it._k" @patch="(p) => patch(i, p)" @move="(d) => move(i, i + d)" @move-to="(n) => move(i, n)"
          @remove="remove(i)" @select="(on) => (on ? selected.add(it._k) : selected.delete(it._k))"
          @dragstart="dragIndex = i" @dragover="dropIndex = i" @drop="move(dragIndex, i); dragIndex = dropIndex = null" @dragend="dragIndex = dropIndex = null" />
      </div>
      <div v-if="poolVisible" class="col-12 col-lg-4 d-none d-lg-block">
        <div class="card position-sticky" style="top:1rem">
          <div class="card-header"><h3 class="card-title">Presenter pool</h3></div>
          <div class="card-body">
            <input v-model="poolQ" class="form-control mb-2" placeholder="Search name or topic" @input="loadPool">
            <div class="list-group list-group-flush" style="max-height:60vh;overflow:auto">
              <div v-for="p in unscheduled" :key="`u${p.id}`" class="list-group-item d-flex align-items-center gap-2 px-0">
                <div class="flex-fill small"><div class="fw-bold ml-text">{{ p.presenter_name }}</div><div class="text-secondary ml-text">{{ p.topic }}</div></div>
                <button type="button" class="btn btn-sm" @click="addSlot(p.id)">Add</button>
              </div>
              <div v-for="p in pool" :key="`p${p.id}`" class="list-group-item d-flex align-items-center gap-2 px-0">
                <div class="flex-fill small"><div class="fw-bold ml-text">{{ p.name }}</div><div class="text-secondary ml-text">{{ p.last_topic || p.designation_or_institution }}</div></div>
                <button type="button" class="btn btn-sm" @click="addFromPool(p)">Add</button>
              </div>
              <div v-if="!unscheduled.length && !pool.length" class="text-secondary small">No other presenters.</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Validation -->
    <div v-if="check.errors.length || check.warnings.length" class="card mt-3">
      <div class="card-header"><h3 class="card-title">Checks</h3></div>
      <div class="list-group list-group-flush">
        <a v-for="(e, k) in check.errors" :key="`e${k}`" href="#" class="list-group-item list-group-item-action text-danger" @click.prevent="jump(e.index)"><Icon name="status.error" cls="me-1" />Slot {{ e.index + 1 }}: {{ e.message }}</a>
        <a v-for="(w, k) in check.warnings" :key="`w${k}`" href="#" class="list-group-item list-group-item-action text-warning" @click.prevent="jump(w.index)"><Icon name="status.warning" cls="me-1" />Slot {{ w.index + 1 }}: {{ w.message }}</a>
      </div>
    </div>

    <!-- Sticky save bar -->
    <div class="sticky-actions d-flex gap-2 align-items-center mt-3">
      <span class="small text-secondary me-auto">Saved version {{ baseVersion }}<span v-if="saveState"> · {{ saveState }}</span></span>
      <button type="button" class="btn d-lg-none" :disabled="readOnly" @click="addPresenter"><Icon name="action.add" />Add</button>
      <button type="button" class="btn btn-primary" :disabled="!dirty || saving || check.errors.length > 0 || readOnly" @click="save"><Icon name="action.save" />{{ saving ? 'Saving…' : 'Save' }}</button>
    </div>

    <!-- Reorder preview -->
    <TablerModal v-if="preview" size="md" @close="preview = null">
      <div class="modal-header"><h5 class="modal-title">Re-flow preview</h5><button type="button" class="btn-close" aria-label="Close" @click="preview = null"></button></div>
      <div class="modal-body">
        <label v-if="preview.canKeepTimes" class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" v-model="preview.keepTimes" @change="recomputePreview"><span class="form-check-label">Keep slot times, swap presenters only</span></label>
        <div v-if="preview.errors.length" class="alert alert-danger">{{ preview.errors[0].message }}</div>
        <table class="table table-sm">
          <thead><tr><th>#</th><th>Slot</th><th>Old</th><th>New</th></tr></thead>
          <tbody>
            <tr v-for="(c, k) in preview.changes" :key="k" :class="{ 'table-warning': c.old !== c.new }">
              <td>{{ c.pos }}</td><td class="ml-text">{{ c.label }}</td><td>{{ time(c.old) }}</td><td class="fw-bold">{{ time(c.new) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-link me-auto" @click="preview = null">Cancel</button><button type="button" class="btn btn-primary" :disabled="preview.errors.length > 0" @click="applyPreview">Apply</button></div>
    </TablerModal>

    <!-- Generate wizard -->
    <TablerModal v-if="gen" size="md" @close="gen = null">
      <div class="modal-header"><h5 class="modal-title">Generate schedule</h5><button type="button" class="btn-close" aria-label="Close" @click="gen = null"></button></div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-6"><label class="form-label">First start ({{ tzLabel }})</label><input type="time" class="form-control" v-model="gen.first"></div>
          <div class="col-3"><label class="form-label">Minutes</label><input type="number" min="1" class="form-control" v-model.number="gen.duration"></div>
          <div class="col-3"><label class="form-label">Changeover</label><input type="number" min="0" class="form-control" v-model.number="gen.changeover"></div>
          <div class="col-12"><label class="form-label">Tea break</label>
            <div class="row g-2">
              <div class="col-12 col-sm-4"><select class="form-select" v-model="gen.teaMode"><option value="none">None</option><option value="every">After every N</option><option value="at">At a clock time</option></select></div>
              <div v-if="gen.teaMode === 'every'" class="col-6 col-sm-4"><input type="number" min="1" class="form-control" v-model.number="gen.teaEvery" aria-label="Every N performances"></div>
              <div v-if="gen.teaMode === 'at'" class="col-6 col-sm-4"><input type="time" class="form-control" v-model="gen.teaAt" aria-label="Tea time"></div>
              <div v-if="gen.teaMode !== 'none'" class="col-6 col-sm-4"><input type="number" min="1" class="form-control" v-model.number="gen.teaMinutes" aria-label="Tea minutes"></div>
            </div>
          </div>
          <div class="col-6"><label class="form-label">Lunch at (optional)</label><input type="time" class="form-control" v-model="gen.lunchAt"></div>
          <div class="col-6"><label class="form-label">Lunch minutes</label><input type="number" min="1" class="form-control" v-model.number="gen.lunchMinutes"></div>
          <div class="col-12 col-sm-6"><label class="form-label">Order</label><select class="form-select" v-model="gen.order"><option value="listed">As listed</option><option value="alpha">Alphabetical</option><option value="shuffle">Random (seeded)</option></select></div>
          <div v-if="gen.order === 'shuffle'" class="col-12 col-sm-6"><label class="form-label">Seed</label><input class="form-control" v-model="gen.seed" placeholder="random"></div>
          <div class="col-12"><label class="form-label">Mode</label>
            <label class="form-check"><input class="form-check-input" type="radio" value="replace" v-model="gen.mode"><span class="form-check-label">Replace all unpinned pending slots</span></label>
            <label class="form-check"><input class="form-check-input" type="radio" value="append" v-model="gen.mode"><span class="form-check-label">Append unscheduled presenters</span></label>
          </div>
        </div>
        <div v-if="gen.error" class="alert alert-danger mt-2">{{ gen.error }}</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-link me-auto" @click="gen = null">Cancel</button><button type="button" class="btn btn-primary" @click="runGenerate">Preview in the timeline</button></div>
    </TablerModal>

    <!-- History -->
    <TablerModal v-if="history" size="md" @close="history = null">
      <div class="modal-header"><h5 class="modal-title">Saved versions</h5><button type="button" class="btn-close" aria-label="Close" @click="history = null"></button></div>
      <div class="list-group list-group-flush">
        <div v-for="h in history" :key="h.version" class="list-group-item d-flex align-items-center gap-2">
          <div class="flex-fill"><div class="fw-bold">v{{ h.version }} <span v-if="h.version === baseVersion" class="badge bg-green-lt">current</span></div><div class="small text-secondary">{{ dateTime(h.saved_at) }} · {{ h.by }}<span v-if="h.note"> · {{ h.note }}</span></div></div>
          <button type="button" class="btn btn-sm" :disabled="h.version === baseVersion" @click="restore(h.version)">Restore as draft</button>
        </div>
      </div>
    </TablerModal>

    <ActionModal ref="modal" />
  </template>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import TablerModal from '@vue-tabler/Modal.vue';
import TablerLoading from '@vue-tabler/Loading.vue';
import { get, put, post, api } from '../../shared/api.js';
import { time, dateTime, isoToLocal, localToIso, syncServerTime, tzLabel } from '../../shared/clock.js';
import { toast, dialog } from '../../shared/ui.js';
import Icon from '../../shared/components/Icon.vue';
import ActionModal from '../../shared/components/ActionModal.vue';
import SlotCard from './SlotCard.vue';
import { reflow, validate, summary, nextStart, isLockedSlot, BREAK_LABELS } from './logic.js';

const props = defineProps({ boot: Object });
const boot = props.boot;
const slamId = boot.slam_id;
const loaded = ref(false);
const data = reactive({});
const draft = ref([]);
const baseVersion = ref(0);
const savedJson = ref('[]');
const undoStack = ref([]);
const redoStack = ref([]);
const editing = ref(null);
const selected = reactive(new Set());
const scaled = ref(false);
const saving = ref(false);
const saveState = ref('');
const bulkMinutes = ref(null);
const preview = ref(null);
const gen = ref(null);
const history = ref(null);
const pool = ref([]);
const poolQ = ref('');
const modal = ref(null);
const dragIndex = ref(null);
const dropIndex = ref(null);
const wide = window.matchMedia('(min-width: 768px)').matches;
let keySeq = 0;
let generatedSeed = null;

const live = computed(() => !!data.live);
const readOnly = computed(() => !!data.locked);
const perfById = computed(() => Object.fromEntries((data.performances || []).map((p) => [p.id, p])));
const perfIds = computed(() => new Set((data.performances || []).map((p) => p.id)));
const unscheduled = computed(() => (data.performances || []).filter((p) => p.is_active && !draft.value.some((d) => d.performance_id === p.id)));
const check = computed(() => validate(data.slam, draft.value, perfIds.value));
const sum = computed(() => summary(data.slam, draft.value));
const dirty = computed(() => JSON.stringify(strip(draft.value)) !== savedJson.value);
const poolVisible = computed(() => wide && !readOnly.value);
const isLocked = isLockedSlot;
const storageKey = `slam-schedule-draft-${slamId}`;

const strip = (items) => items.map(({ _k, review_count, actual_open_at, actual_close_at, ...rest }) => rest);
const withKeys = (items) => items.map((x) => ({ ...x, _k: x._k || `k${++keySeq}` }));
const messagesFor = (i) => [...check.value.errors.filter((e) => e.index === i).map((e) => ({ ...e, type: 'error' })), ...check.value.warnings.filter((w) => w.index === i).map((w) => ({ ...w, type: 'warning' }))];
const projectedFor = (it) => data.projection?.items?.find((p) => p.id === it.id)?.projected_start_at || null;

function commit(next) {
  undoStack.value.push(draft.value);
  if (undoStack.value.length > 50) undoStack.value.shift();
  redoStack.value = [];
  draft.value = withKeys(next);
}
function undo() { if (!undoStack.value.length) return; redoStack.value.push(draft.value); draft.value = undoStack.value.pop(); }
function redo() { if (!redoStack.value.length) return; undoStack.value.push(draft.value); draft.value = redoStack.value.pop(); }

watch(draft, (v) => {
  try { if (dirty.value) localStorage.setItem(storageKey, JSON.stringify({ base: baseVersion.value, items: strip(v) })); else localStorage.removeItem(storageKey); } catch {}
}, { deep: true });

async function load(keepDraft = false) {
  const res = await get(`/api/slams/${slamId}/schedule`);
  syncServerTime(res.server_now);
  Object.assign(data, res);
  baseVersion.value = res.slam.schedule_version;
  savedJson.value = JSON.stringify(strip(res.items));
  if (!keepDraft) {
    draft.value = withKeys(res.items);
    undoStack.value = [];
    redoStack.value = [];
    try {
      const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
      if (saved && saved.base === baseVersion.value && JSON.stringify(saved.items) !== savedJson.value) {
        const r = await dialog({ title: 'Restore unsaved draft?', message: 'You have unsaved changes from an earlier visit.', confirmText: 'Restore draft' });
        if (r !== null) commit(saved.items); else localStorage.removeItem(storageKey);
      }
    } catch {}
  }
  loaded.value = true;
}
async function loadPool() {
  if (readOnly.value) return;
  try { pool.value = (await get(`/api/slams/${slamId}/presenter-pool?q=${encodeURIComponent(poolQ.value)}`)).data; } catch {}
}

function patch(i, p) {
  const next = draft.value.map((x) => ({ ...x }));
  Object.assign(next[i], p);
  commit(next);
}
function move(from, to) {
  if (from === null || to === null || from === to || to < 0 || to >= draft.value.length) return;
  if (isLocked(draft.value[from])) { toast('This slot already ran and cannot move.', 'error'); return; }
  const order = draft.value.map((x) => ({ ...x }));
  const [m] = order.splice(from, 1);
  order.splice(to, 0, m);
  openPreview(order);
}
function openPreview(order) {
  const canKeepTimes = order.every((it, k) => it.duration_minutes === draft.value[k].duration_minutes);
  preview.value = { order, keepTimes: false, canKeepTimes, changes: [], errors: [], items: [] };
  recomputePreview();
}
function recomputePreview() {
  const p = preview.value;
  let items;
  let errors = [];
  if (p.keepTimes) {
    items = p.order.map((it, k) => ({ ...it, planned_start_at: draft.value[k].planned_start_at }));
  } else {
    const first = draft.value[0]?.planned_start_at;
    items = p.order.map((x) => ({ ...x }));
    if (items[0] && first && items[0].status === 'pending' && !items[0].is_pinned) items[0].planned_start_at = first;
    ({ items, errors } = reflow(items, data.slam.changeover_minutes, 0));
  }
  const before = Object.fromEntries(draft.value.map((d) => [d._k, d.planned_start_at]));
  p.items = items;
  p.errors = errors;
  p.changes = items.map((it, k) => ({ pos: k + 1, label: it.item_type === 'break' ? it.label : perfById.value[it.performance_id]?.presenter_name, old: before[it._k], new: it.planned_start_at }));
}
function applyPreview() { commit(preview.value.items); preview.value = null; }
function reflowAll() { openPreview(draft.value.map((x) => ({ ...x }))); }

function remove(i) {
  const next = draft.value.filter((_, k) => k !== i);
  commit(next);
  editing.value = null;
}
function bulk(p) {
  commit(draft.value.map((x) => (selected.has(x._k) && !isLocked(x) ? { ...x, ...p } : x)));
}
function bulkRemove() {
  commit(draft.value.filter((x) => !selected.has(x._k) || isLocked(x) || x.review_count > 0));
  selected.clear();
}
function addSlot(performanceId) {
  const start = nextStart(data.slam, draft.value, 'performance');
  commit([...draft.value, { id: null, item_type: 'performance', break_type: null, performance_id: performanceId, label: null, planned_start_at: start, duration_minutes: data.slam.default_review_minutes, is_pinned: false, status: 'pending' }]);
}
function addAllUnscheduled() {
  for (const p of [...unscheduled.value]) addSlot(p.id);
  toast('All pool presenters added to the draft. Check the times, then save.');
}
function addBreak(type) {
  const start = nextStart(data.slam, draft.value, 'break');
  const minutes = type === 'lunch' ? data.slam.default_lunch_break_minutes : data.slam.default_tea_break_minutes;
  commit([...draft.value, { id: null, item_type: 'break', break_type: type, performance_id: null, label: BREAK_LABELS[type], planned_start_at: start, duration_minutes: minutes, is_pinned: false, status: 'pending' }]);
}
function addPresenter() {
  modal.value.show({
    title: 'New presenter', message: 'Creates the presenter and adds a slot to the draft. Add a photo later from the presenter page.',
    fields: [
      { name: 'name', label: 'Name', required: true }, { name: 'designation_or_institution', label: 'Designation / institution' },
      { name: 'topic', label: 'Topic', required: true }, { name: 'abstract', label: 'Abstract' }, { name: 'profile', label: 'Profile' },
    ],
    run: async (v) => {
      const res = await post(`/api/slams/${slamId}/performances`, v);
      data.performances.push({ id: res.performance_id, presenter_id: res.presenter_id, presenter_name: res.presenter_name, topic: res.topic, thumb: res.thumb, is_active: true });
      addSlot(res.performance_id);
      toast('Presenter added to the draft. Remember to save.');
    },
  });
}
function addExisting() {
  loadPool().then(() => {
    if (!pool.value.length) { toast('No presenters from other slams are available.', 'error'); return; }
    modal.value.show({
      title: 'Add existing presenter', message: pool.value.map((p, k) => `${k + 1}. ${p.name}`).join('  ·  '),
      fields: [{ name: 'n', label: 'Number from the list', type: 'number', required: true }, { name: 'topic', label: 'Topic in this slam', required: true }],
      run: async (v) => {
        const p = pool.value[Number(v.n) - 1];
        if (!p) throw new Error('Pick a number from the list.');
        await addFromPool(p, v.topic);
      },
    });
  });
}
async function addFromPool(p, topic = null) {
  if (topic === null) {
    const r = await dialog({ title: `Add ${p.name}`, message: 'Topic for this slam', input: { value: p.last_topic || '' }, confirmText: 'Add' });
    if (r === null) return;
    topic = r;
  }
  try {
    const res = await post(`/api/slams/${slamId}/performances`, { presenter_id: p.id, topic });
    data.performances.push({ id: res.performance_id, presenter_id: res.presenter_id, presenter_name: res.presenter_name, topic: res.topic, thumb: res.thumb, is_active: true });
    addSlot(res.performance_id);
    pool.value = pool.value.filter((x) => x.id !== p.id);
    toast('Added to the draft. Remember to save.');
  } catch (e) { toast(e.message, 'error'); }
}

function openGenerate() {
  const first = isoToLocal(data.slam.reviews_start_at || data.slam.checkin_closes_at || data.slam.opens_at);
  gen.value = { first: first.time, date: first.date, duration: data.slam.default_review_minutes, changeover: data.slam.changeover_minutes, teaMode: 'none', teaEvery: 4, teaAt: '', teaMinutes: data.slam.default_tea_break_minutes, lunchAt: '', lunchMinutes: data.slam.default_lunch_break_minutes, order: 'listed', seed: '', mode: 'replace', error: '' };
}
async function runGenerate() {
  const g = gen.value;
  try {
    const res = await post(`/api/slams/${slamId}/schedule/generate`, {
      first_start: localToIso(g.date, g.first), duration: g.duration, changeover: g.changeover, order: g.order, seed: g.seed, mode: g.mode,
      tea: { mode: g.teaMode, every_n: g.teaEvery, at: g.teaAt ? localToIso(g.date, g.teaAt) : null, minutes: g.teaMinutes },
      lunch: { at: g.lunchAt ? localToIso(g.date, g.lunchAt) : null, minutes: g.lunchMinutes },
    });
    generatedSeed = res.seed;
    const keep = Object.fromEntries(draft.value.filter((d) => d.id).map((d) => [d.performance_id ?? `b${d.id}`, d.id]));
    commit(res.items.map((it) => ({ ...it, id: it.id ?? (it.performance_id ? keep[it.performance_id] ?? null : null) })));
    gen.value = null;
    toast(res.seed ? `Generated (seed ${res.seed}). Review, then save.` : 'Generated. Review, then save.');
  } catch (e) { g.error = e.message; }
}
async function openHistory() {
  try { history.value = (await get(`/api/slams/${slamId}/schedule/snapshots`)).data; } catch (e) { toast(e.message, 'error'); }
}
async function restore(v) {
  try {
    const res = await post(`/api/slams/${slamId}/schedule/snapshots/${v}/restore`);
    commit(res.items);
    history.value = null;
    toast(`Version ${v} loaded as a draft. Save to make it current.`);
  } catch (e) { toast(e.message, 'error'); }
}
function copyStructure() {
  modal.value.show({
    title: 'Copy structure from another slam', message: boot.other_slams.map((s, k) => `${k + 1}. ${s.name}`).join('  ·  '),
    fields: [{ name: 'n', label: 'Number from the list', type: 'number', required: true }],
    run: async (v) => {
      const s = boot.other_slams[Number(v.n) - 1];
      if (!s) throw new Error('Pick a number from the list.');
      const res = await post(`/api/slams/${slamId}/schedule/copy-structure/${s.id}`);
      commit(res.items);
      toast('Structure copied into the draft.');
    },
  });
}
async function reflowRemaining() {
  try {
    const p = await post(`/api/slams/${slamId}/schedule/reflow-remaining`, { preview: true });
    if (!p.changes.length) { toast('Not running late; nothing to re-flow.'); return; }
    const r = await dialog({ title: `Shift ${p.changes.length} pending slot(s) by ${p.delay_minutes} min?`, message: p.errors.length ? p.errors[0].message : 'Pinned slots stay where they are.', confirmText: 'Re-flow' });
    if (r === null) return;
    await post(`/api/slams/${slamId}/schedule/reflow-remaining`, { preview: false });
    await load();
    toast('Remaining slots re-flowed.');
  } catch (e) { toast(e.message, 'error'); }
}

async function save() {
  const note = await dialog({ title: 'Save schedule', message: 'Optional note for the history', input: { value: '' }, required: false, confirmText: 'Save' });
  if (note === null) return;
  saving.value = true;
  saveState.value = 'saving…';
  try {
    const res = await api(`/api/slams/${slamId}/schedule`, { method: 'PUT', body: { items: strip(draft.value), base_version: baseVersion.value, note, generated_seed: generatedSeed } });
    try { localStorage.removeItem(storageKey); } catch {}
    generatedSeed = null;
    await load();
    saveState.value = `saved v${res.version}`;
    toast(res.warnings?.length ? `Saved with ${res.warnings.length} warning(s).` : 'Schedule saved.');
  } catch (e) {
    saveState.value = 'not saved';
    if (e.status === 409 && e.reason === 'version_conflict') {
      const r = await dialog({ title: 'Someone else saved first', message: `The schedule is now at version ${e.details?.current_version}. Reapply your changes on top of it, or discard them and reload.`, confirmText: 'Reapply my changes' });
      if (r === null) { discard(); } else { const mine = strip(draft.value); await load(true); commit(mine); toast('Your changes are reapplied on the latest version. Check and save again.'); }
    } else {
      toast(e.message + (e.details?.errors?.length ? ` — slot ${e.details.errors[0].index + 1}: ${e.details.errors[0].message}` : ''), 'error');
    }
  } finally {
    saving.value = false;
  }
}
function discard() { try { localStorage.removeItem(storageKey); } catch {} load(); }
function jump(i) { const el = document.getElementById(`slot-${i}`); el?.scrollIntoView({ behavior: 'smooth', block: 'center' }); editing.value = draft.value[i]?._k; }

window.addEventListener('beforeunload', (e) => { if (dirty.value) { e.preventDefault(); e.returnValue = ''; } });
onMounted(async () => { await load(); loadPool(); });
</script>
