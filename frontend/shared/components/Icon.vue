<template>
  <svg :class="['icon', cls]" :aria-hidden="label ? undefined : 'true'" :role="label ? 'img' : undefined" :aria-label="label || undefined">
    <use :href="`${sprite}#${id}`" />
  </svg>
</template>

<script setup>
import { computed } from 'vue';
import { iconId, spriteUrl } from '../semantic.js';

const props = defineProps({ name: { type: String, required: true }, cls: { type: String, default: '' }, label: { type: String, default: '' } });
const sprite = spriteUrl();
const id = computed(() => iconId(props.name));
</script>
