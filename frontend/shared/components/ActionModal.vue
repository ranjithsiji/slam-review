<template>
  <TablerModal v-if="open" size="sm" @close="close">
    <div class="modal-status" :class="danger ? 'bg-danger' : 'bg-primary'"></div>
    <div class="modal-header"><h5 class="modal-title">{{ title }}</h5><button type="button" class="btn-close" aria-label="Close" @click="close"></button></div>
    <div class="modal-body">
      <p v-if="message" class="text-secondary">{{ message }}</p>
      <div v-for="f in fields" :key="f.name" class="mb-3">
        <label v-if="f.type === 'checkbox'" class="form-check mb-0"><input v-model="values[f.name]" type="checkbox" class="form-check-input"><span class="form-check-label">{{ f.label }}</span><span v-if="f.hint" class="form-check-description">{{ f.hint }}</span></label>
        <TablerInput v-else v-model="values[f.name]" :label="f.label" :type="f.type || 'text'" :description="f.hint || ''" :required="f.required" :placeholder="f.placeholder || ''" :error="errors[f.name] || ''" />
      </div>
      <div v-if="error" class="alert alert-danger mb-0">{{ error }}</div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-link link-secondary me-auto" @click="close">Cancel</button>
      <button type="button" class="btn" :class="danger ? 'btn-danger' : 'btn-primary'" :disabled="busy" @click="confirm">
        <span v-if="busy" class="spinner-border spinner-border-sm me-2"></span>{{ confirmText }}
      </button>
    </div>
  </TablerModal>
</template>

<script setup>
// A small form-in-a-modal built on vue-tabler's TablerModal + TablerInput.
import { ref, reactive } from 'vue';
import TablerModal from '@vue-tabler/Modal.vue';
import TablerInput from '@vue-tabler/input/Input.vue';

const open = ref(false);
const title = ref('');
const message = ref('');
const confirmText = ref('Confirm');
const danger = ref(false);
const fields = ref([]);
const values = reactive({});
const errors = reactive({});
const error = ref('');
const busy = ref(false);
let action = null;

/** fields: [{name, label, type, required, value, hint}], run: async (values) => void */
function show(opts) {
  title.value = opts.title;
  message.value = opts.message || '';
  confirmText.value = opts.confirmText || 'Confirm';
  danger.value = !!opts.danger;
  fields.value = opts.fields || [];
  Object.keys(values).forEach((k) => delete values[k]);
  Object.keys(errors).forEach((k) => delete errors[k]);
  fields.value.forEach((f) => { values[f.name] = f.value ?? ''; });
  error.value = '';
  action = opts.run;
  open.value = true;
}
function close() { if (!busy.value) open.value = false; }
async function confirm() {
  Object.keys(errors).forEach((k) => delete errors[k]);
  for (const f of fields.value) {
    if (f.type !== 'checkbox' && f.required && String(values[f.name] ?? '').trim() === '') { errors[f.name] = 'Required.'; return; }
  }
  busy.value = true;
  error.value = '';
  try {
    await action({ ...values });
    open.value = false;
  } catch (e) {
    error.value = e.message || 'Failed.';
  } finally {
    busy.value = false;
  }
}
defineExpose({ show });
</script>
