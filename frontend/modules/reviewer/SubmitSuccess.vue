<template>
  <div class="submit-success text-center">
    <div class="confetti" aria-hidden="true" v-if="celebrate">
      <i v-for="n in 28" :key="n" :style="piece(n)"></i>
    </div>
    <svg class="success-check" viewBox="0 0 80 80" aria-hidden="true">
      <circle class="success-ring" cx="40" cy="40" r="34" fill="none" />
      <path class="success-tick" d="M24 42 l11 11 l22 -24" fill="none" />
    </svg>
    <div class="h1 mb-1 success-title">Review submitted</div>
    <div class="text-secondary mb-3 success-sub">Thank you! Your voice counts.</div>
    <div class="d-flex flex-wrap justify-content-center gap-2 success-chips">
      <span v-for="(a, i) in answers" :key="a.question_id" class="badge bg-primary-lt fs-3 vote-pop" :style="{ '--d': `${400 + i * 120}ms` }">{{ qIndex(a.question_id) }}: {{ a.score }}<span class="text-secondary">/{{ qMax(a.question_id) }}</span></span>
    </div>
    <slot />
  </div>
</template>

<script setup>
defineProps({ answers: { type: Array, default: () => [] }, qIndex: Function, qMax: Function, celebrate: Boolean });
const colours = ['#ff9a00', '#2fb344', '#066fd1', '#ae3ec9', '#d63939', '#f1c40f'];
const piece = (n) => ({
  '--x': `${(n * 37) % 100}%`, '--r': `${(n * 53) % 360}deg`, '--c': colours[n % colours.length],
  '--t': `${1.4 + ((n * 7) % 10) / 10}s`, '--dx': `${((n * 29) % 60) - 30}px`, '--w': `${6 + (n % 4) * 2}px`,
  animationDelay: `${(n % 7) * 60}ms`,
});
</script>
