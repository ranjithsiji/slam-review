<template>
  <div v-if="list.length" class="ad-card card mt-3" role="complementary" aria-label="Sponsor message">
    <div class="card-body">
      <div class="small text-secondary text-uppercase fw-bold mb-2">{{ cur.sponsor || 'Sponsored' }}</div>
      <component :is="cur.link ? 'a' : 'div'" :href="cur.link || undefined" :target="cur.link ? '_blank' : undefined" :rel="cur.link ? 'noopener noreferrer sponsored' : undefined" class="ad-body text-reset text-decoration-none d-block">
        <img v-if="cur.image" :src="cur.image" :alt="cur.headline || cur.sponsor || 'Advertisement'" class="ad-image rounded w-100" loading="lazy">
        <div v-if="cur.headline" class="h2 mt-2 mb-1 ml-text">{{ cur.headline }}</div>
        <div v-if="cur.body" class="ml-text" style="white-space: pre-line">{{ cur.body }}</div>
      </component>
      <div v-if="list.length > 1" class="d-flex justify-content-center gap-1 mt-3" aria-hidden="true">
        <span v-for="(a, i) in list" :key="a.id" class="ad-dot" :class="{ on: i === idx }"></span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({ ads: { type: Array, default: () => [] }, seconds: { type: Number, default: 8 } });
const list = computed(() => props.ads || []);
const idx = ref(0);
const cur = computed(() => list.value[idx.value % Math.max(1, list.value.length)] || {});
let timer = null;
const restart = () => { clearInterval(timer); timer = list.value.length > 1 ? setInterval(() => { idx.value = (idx.value + 1) % list.value.length; }, props.seconds * 1000) : null; };
watch(() => list.value.map((a) => a.id).join(), () => { idx.value = 0; restart(); });
onMounted(restart);
onBeforeUnmount(() => clearInterval(timer));
</script>
