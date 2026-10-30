<template>
  <fieldset class="mb-4">
    <legend class="fw-bold mb-1 fs-3">
      <span class="text-secondary me-1">{{ index + 1 }}.</span>{{ question.text }}
    </legend>
    <div v-if="question.text_ml" class="ml-text text-secondary mb-1" lang="ml">{{ question.text_ml }}</div>
    <div v-if="question.help_text" class="small text-secondary mb-2">{{ question.help_text }}</div>
    <div class="score-grid" role="radiogroup" :aria-label="`Score for: ${question.text}`">
      <button
        v-for="n in 10" :key="n" type="button" class="btn btn-outline-primary" role="radio"
        :aria-checked="modelValue === n ? 'true' : 'false'" :aria-pressed="modelValue === n ? 'true' : 'false'"
        :disabled="disabled" @click="$emit('update:modelValue', n)"
      >{{ n }}</button>
    </div>
    <div class="d-flex justify-content-between small text-secondary mt-1"><span>1 low</span><span>10 high</span></div>
  </fieldset>
</template>

<script setup>
defineProps({ question: Object, index: Number, modelValue: { type: Number, default: null }, disabled: Boolean });
defineEmits(['update:modelValue']);
</script>
