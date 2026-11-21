<template>
  <div class="d-flex align-items-start" :class="compact ? 'gap-2' : 'gap-3'">
    <img v-if="item.photo" :src="item.thumb || item.photo" :srcset="item.thumb && item.photo ? `${item.thumb} 200w, ${item.photo} 800w` : undefined" :sizes="compact ? '56px' : '96px'" :alt="item.photo_alt || item.presenter_name" class="presenter-photo flex-shrink-0" :class="{ 'presenter-photo-sm': compact }" :width="compact ? 56 : 96" :height="compact ? 56 : 96">
    <span v-else class="avatar flex-shrink-0" :class="compact ? 'avatar-md' : 'avatar-xl'"><Icon name="person.user" cls="icon-lg" /></span>
    <div class="flex-fill min-w-0">
      <div class="mb-0 ml-text" :class="compact ? 'h3' : 'h2'">{{ item.presenter_name }}</div>
      <div v-if="item.designation && !compact" class="small text-secondary ml-text">{{ item.designation }}</div>
      <div class="ml-text" :class="compact ? 'small text-secondary mt-0 presenter-topic-sm' : 'fs-3 mt-1'">{{ item.topic }}</div>
    </div>
  </div>
  <details v-if="item.abstract || item.profile" :class="compact ? 'mt-1 small' : 'mt-2'">
    <summary class="text-primary">About the talk and presenter</summary>
    <div v-if="compact && item.designation" class="small text-secondary mt-2 ml-text">{{ item.designation }}</div>
    <p v-if="item.abstract" class="mt-2 mb-1 ml-text" style="white-space:pre-line">{{ item.abstract }}</p>
    <p v-if="item.profile" class="small text-secondary mb-0 ml-text" style="white-space:pre-line">{{ item.profile }}</p>
  </details>
</template>

<script setup>
import Icon from '../../shared/components/Icon.vue';
// compact: the small card shown while the review is open, so the questions come first.
defineProps({ item: { type: Object, required: true }, compact: Boolean });
</script>
