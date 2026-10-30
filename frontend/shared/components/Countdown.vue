<template>
  <span class="countdown" :class="[cls, size === 'xl' ? 'countdown-xl' : '']" role="timer" :aria-label="aria">
    <Icon v-if="icon" name="status.countdown" cls="icon-inline me-1" />{{ text }}
  </span>
</template>

<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';
import { now, secondsUntil, fmtCountdown } from '../clock.js';

const props = defineProps({ until: { type: String, default: null }, size: { type: String, default: '' }, icon: { type: Boolean, default: true }, warnAt: { type: Number, default: 30 } });
const secs = computed(() => secondsUntil(props.until, now.value));
const text = computed(() => (secs.value === null ? '' : fmtCountdown(secs.value)));
const cls = computed(() => (secs.value === null || secs.value <= 0 ? 'text-secondary' : secs.value <= props.warnAt ? 'text-warning' : 'text-primary'));
const aria = computed(() => (secs.value > 0 ? `${Math.floor(secs.value / 60)} minutes ${secs.value % 60} seconds left` : 'Time is up'));
</script>
