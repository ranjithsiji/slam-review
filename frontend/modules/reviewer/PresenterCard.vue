<template>
  <div class="d-flex gap-3 align-items-start">
    <img v-if="item.photo" :src="item.thumb || item.photo" :srcset="item.thumb && item.photo ? `${item.thumb} 200w, ${item.photo} 800w` : undefined" sizes="96px" :alt="item.photo_alt || item.presenter_name" class="presenter-photo flex-shrink-0" width="96" height="96">
    <span v-else class="avatar avatar-xl flex-shrink-0"><Icon name="person.user" cls="icon-lg" /></span>
    <div class="flex-fill min-w-0">
      <div class="h2 mb-0 ml-text">{{ item.presenter_name }}</div>
      <div v-if="item.designation" class="small text-secondary ml-text">{{ item.designation }}</div>
      <div class="fs-3 mt-1 ml-text">{{ item.topic }}</div>
    </div>
  </div>
  <details v-if="item.abstract || item.profile" class="mt-2">
    <summary class="text-primary">About the talk and presenter</summary>
    <p v-if="item.abstract" class="mt-2 mb-1 ml-text" style="white-space:pre-line">{{ item.abstract }}</p>
    <p v-if="item.profile" class="small text-secondary mb-0 ml-text" style="white-space:pre-line">{{ item.profile }}</p>
  </details>
</template>

<script setup>
import Icon from '../../shared/components/Icon.vue';
defineProps({ item: { type: Object, required: true } });
</script>
