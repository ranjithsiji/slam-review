<template>
  <fieldset class="mb-4">
    <legend class="fw-bold mb-1 fs-3">
      <span class="text-secondary me-1">{{ index + 1 }}.</span>{{ question.text }}
      <span class="badge bg-secondary-lt ms-1 align-middle">{{ max }} {{ max === 1 ? 'mark' : 'marks' }}</span>
    </legend>
    <div v-if="question.text_ml" class="ml-text text-secondary mb-1" lang="ml">{{ question.text_ml }}</div>
    <div v-if="question.help_text" class="small text-secondary mb-2">{{ question.help_text }}</div>
    <div class="score-grid" :style="{ '--cols': quick.length === 6 ? 6 : Math.min(5, quick.length) }" role="radiogroup" :aria-label="`Score for: ${question.text} (out of ${max})`">
      <button
        v-for="n in quick" :key="n" type="button" class="btn btn-outline-primary" role="radio"
        :aria-checked="same(modelValue, n) ? 'true' : 'false'" :aria-pressed="same(modelValue, n) ? 'true' : 'false'"
        :disabled="disabled" @click="$emit('update:modelValue', n)"
      >{{ label(n) }}</button>
    </div>
    <div v-if="decimal" class="d-flex align-items-center gap-2 mt-2">
      <label class="form-label mb-0 small text-secondary" :for="`exact-${question.id}`">Exact mark</label>
      <div class="input-group" style="max-width: 12rem">
        <button type="button" class="btn" :disabled="disabled || (modelValue ?? 0) <= 0.1" aria-label="Lower by 0.1" @click="nudge(-1)">−</button>
        <input
          :id="`exact-${question.id}`" type="text" inputmode="decimal" class="form-control text-center" :disabled="disabled"
          :value="modelValue ?? ''" :aria-invalid="bad ? 'true' : 'false'" placeholder="e.g. 2.4" @change="typed($event.target.value)"
        >
        <button type="button" class="btn" :disabled="disabled || (modelValue ?? 0) >= max" aria-label="Raise by 0.1" @click="nudge(1)">+</button>
      </div>
      <span class="small text-secondary">/ {{ max }}</span>
    </div>
    <div v-if="bad" class="small text-danger mt-1">Enter a mark from 0.1 to {{ max }} with at most one decimal.</div>
    <div v-else class="d-flex justify-content-between small text-secondary mt-1"><span>{{ decimal ? '0.1' : '1' }} low</span><span>{{ max }} high</span></div>
  </fieldset>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({ question: Object, index: Number, modelValue: { type: Number, default: null }, disabled: Boolean });
const emit = defineEmits(['update:modelValue']);
const max = computed(() => props.question.max_score || 10);
// Judges (step 0.1) may use one decimal place; the audience taps whole marks.
const decimal = computed(() => (props.question.step || 1) < 1);
// Quick buttons: halves for small criteria (0.5 … 3), whole marks otherwise.
const quick = computed(() => {
  if (!decimal.value || max.value > 3) return Array.from({ length: max.value }, (_, i) => i + 1);
  return Array.from({ length: max.value * 2 }, (_, i) => (i + 1) / 2);
});
const label = (n) => (Number.isInteger(n) ? String(n) : n.toFixed(1));
const same = (a, b) => a !== null && a !== undefined && Math.abs(a - b) < 1e-9;
const bad = ref(false);
const round1 = (v) => Math.round(v * 10) / 10;

function typed(raw) {
  const v = Number(String(raw).replace(',', '.').trim());
  if (!/^\d{1,2}([.,]\d)?$/.test(String(raw).trim()) || v < 0.1 || v > max.value) { bad.value = true; return; }
  bad.value = false;
  emit('update:modelValue', round1(v));
}
function nudge(dir) {
  const v = round1(Math.min(max.value, Math.max(0.1, (props.modelValue ?? (dir > 0 ? 0 : max.value + 0.1)) + dir * 0.1)));
  bad.value = false;
  emit('update:modelValue', v);
}
</script>
