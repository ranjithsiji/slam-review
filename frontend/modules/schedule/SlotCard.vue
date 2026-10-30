<template>
  <div class="card slot-card mb-2" :class="[cls, { 'slot-invalid': hasError, 'drop-target': dropTarget, dragging }]"
       :draggable="draggable" @dragstart="$emit('dragstart', $event)" @dragover.prevent="$emit('dragover', $event)" @drop.prevent="$emit('drop', $event)" @dragend="$emit('dragend')"
       :style="scaled ? { minHeight: `${Math.max(56, it.duration_minutes * 6)}px` } : null" :id="`slot-${index}`">
    <div class="card-body p-2 p-sm-3">
      <div class="d-flex align-items-center gap-2">
        <label class="form-check m-0" :title="'Select'"><input class="form-check-input" type="checkbox" :checked="selected" :disabled="locked" @change="$emit('select', $event.target.checked)" :aria-label="`Select slot ${index + 1}`"></label>
        <span v-if="draggable" class="drag-handle text-secondary d-none d-md-inline" aria-hidden="true"><Icon name="action.drag" /></span>
        <div class="text-center" style="min-width:4.2rem">
          <div class="fw-bold">{{ time(it.planned_start_at) }}</div>
          <div class="small text-secondary">{{ time(endIso) }}</div>
        </div>
        <Icon v-if="it.item_type === 'break'" :name="`break.${it.break_type || 'other'}`" cls="icon-lg text-orange" />
        <template v-else>
          <img v-if="perf?.thumb" :src="perf.thumb" alt="" class="avatar avatar-photo d-none d-sm-inline-block" loading="lazy">
          <span v-else class="avatar d-none d-sm-inline-flex"><Icon name="person.user" /></span>
        </template>
        <button type="button" class="btn btn-link d-block text-reset text-start p-0 flex-fill min-w-0" style="min-height:auto" :aria-expanded="editing" @click="$emit('edit')">
          <div class="fw-bold ml-text text-truncate slot-title">{{ it.item_type === 'break' ? it.label : perf?.presenter_name || 'Unknown presenter' }}</div>
          <div class="small text-secondary ml-text text-truncate">{{ it.item_type === 'break' ? `${it.duration_minutes} min` : `${perf?.topic || ''} · ${it.duration_minutes} min` }}</div>
          <div v-if="projected && projected !== it.planned_start_at && it.status === 'pending'" class="small text-warning">expected {{ time(projected) }}</div>
          <div v-if="it.actual_open_at" class="small text-secondary">ran {{ time(it.actual_open_at) }}–{{ time(it.actual_close_at) }}</div>
        </button>
        <Icon v-if="it.is_pinned" name="action.pin" cls="text-secondary" label="Pinned time" />
        <StatusBadge v-if="it.status !== 'pending'" group="slot" :value="it.status" extra="d-none d-sm-inline-flex" />
        <div class="btn-group-vertical btn-group-sm" v-if="!locked">
          <button type="button" class="btn btn-sm btn-ghost-secondary py-0" :disabled="first" @click="$emit('move', -1)" :aria-label="`Move slot ${index + 1} up`"><Icon name="action.up" /></button>
          <button type="button" class="btn btn-sm btn-ghost-secondary py-0" :disabled="last" @click="$emit('move', 1)" :aria-label="`Move slot ${index + 1} down`"><Icon name="action.down" /></button>
        </div>
      </div>
      <div v-for="m in messages" :key="m.message" class="small mt-1" :class="m.type === 'error' ? 'text-danger' : 'text-warning'"><Icon :name="m.type === 'error' ? 'status.error' : 'status.warning'" cls="icon-inline me-1" />{{ m.message }}</div>

      <div v-if="editing" class="border-top mt-2 pt-2">
        <div class="row g-2">
          <div class="col-6 col-md-3"><label class="form-label small mb-0">Start (IST)</label><input type="time" class="form-control" :value="ist.time" :disabled="locked" @change="setTime($event.target.value)"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-0">Minutes</label><input type="number" min="1" max="240" class="form-control" :value="it.duration_minutes" :disabled="locked" @change="$emit('patch', { duration_minutes: Number($event.target.value) })"></div>
          <div v-if="it.item_type === 'break'" class="col-12 col-md-6"><label class="form-label small mb-0">Label</label><input class="form-control" maxlength="150" :value="it.label" :disabled="locked" @change="$emit('patch', { label: $event.target.value })"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-0">Move to position</label><input type="number" min="1" :max="count" class="form-control" :value="index + 1" :disabled="locked" @change="$emit('moveTo', Number($event.target.value) - 1)"></div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <label class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" :checked="it.is_pinned" :disabled="locked" @change="$emit('patch', { is_pinned: $event.target.checked })"><span class="form-check-label">Pin this time</span></label>
          <label class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" :checked="it.status === 'skipped'" :disabled="locked || (it.status !== 'pending' && it.status !== 'skipped')" @change="$emit('patch', { status: $event.target.checked ? 'skipped' : 'pending' })"><span class="form-check-label">Skip</span></label>
          <a v-if="it.item_type === 'performance' && perf" class="btn btn-sm ms-auto" :href="`/admin/presenters/${perf.presenter_id}/edit`" target="_blank" rel="noopener">Edit presenter</a>
          <button v-if="!locked && !(it.review_count > 0)" type="button" class="btn btn-sm btn-outline-danger" :class="{ 'ms-auto': it.item_type === 'break' }" @click="$emit('remove')"><Icon name="action.delete" />Remove</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import Icon from '../../shared/components/Icon.vue';
import StatusBadge from '../../shared/components/StatusBadge.vue';
import { time, isoToIst, istToIso } from '../../shared/clock.js';
import { endOf, isLockedSlot } from './logic.js';

const props = defineProps({
  it: Object, perf: Object, index: Number, count: Number, editing: Boolean, selected: Boolean, scaled: Boolean,
  messages: Array, projected: String, draggable: Boolean, dropTarget: Boolean, dragging: Boolean,
});
const emit = defineEmits(['edit', 'patch', 'move', 'moveTo', 'remove', 'select', 'dragstart', 'dragover', 'drop', 'dragend']);
const endIso = computed(() => endOf(props.it));
const locked = computed(() => isLockedSlot(props.it));
const first = computed(() => props.index === 0);
const last = computed(() => props.index === props.count - 1);
const hasError = computed(() => (props.messages || []).some((m) => m.type === 'error'));
const ist = computed(() => isoToIst(props.it.planned_start_at));
const cls = computed(() => (props.it.item_type === 'break' ? 'slot-break' : `slot-${props.it.status}`));
function setTime(v) { if (v) emit('patch', { planned_start_at: istToIso(ist.value.date, v) }); }
</script>
