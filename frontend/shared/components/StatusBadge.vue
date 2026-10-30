<template>
  <span :class="['badge', `bg-${s.color}-lt`, extra]">
    <Icon :name="s.icon" cls="icon-inline me-1" />{{ text || s.label }}
  </span>
</template>

<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';
import { sem } from '../semantic.js';

const props = defineProps({ group: { type: String, required: true }, value: { type: String, default: '' }, text: { type: String, default: '' }, extra: { type: String, default: '' } });
const s = computed(() => sem(props.group, props.value));
</script>
