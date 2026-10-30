<template>
  <div class="talk-now d-flex gap-3 align-items-start">
    <img v-if="talk.photo || talk.thumb" class="talk-photo" :src="talk.photo || talk.thumb" :alt="talk.photo_alt || talk.presenter" width="96" height="96" loading="lazy">
    <span v-else class="avatar avatar-xl flex-none"><Icon name="person.user" cls="icon-lg" /></span>
    <div class="min-w-0 flex-fill">
      <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
        <span class="badge bg-red-lt"><Icon name="status.live" cls="icon-inline me-1" />{{ kicker }}</span>
        <span v-if="talk.started_at" class="small text-secondary">Started {{ time(talk.started_at) }}<template v-if="mins !== null"> · {{ mins }} min ago</template></span>
      </div>
      <div class="h2 mb-0 ml-text">{{ talk.title }}</div>
      <div class="ml-text"><strong>{{ talk.presenter }}</strong><span v-if="talk.designation" class="text-secondary"> · {{ talk.designation }}</span></div>
      <p v-if="talk.summary && !compact" class="mb-0 mt-1 text-secondary ml-text">{{ talk.summary }}</p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';
import { now, time } from '../clock.js';

const props = defineProps({ talk: { type: Object, required: true }, compact: Boolean });
const kicker = computed(() => (props.talk.on_stage ? (props.talk.review_open ? 'On stage · review open' : 'On stage now') : 'Talk finished · review open'));
const mins = computed(() => (props.talk.started_at ? Math.max(0, Math.floor((now.value - Date.parse(props.talk.started_at)) / 60000)) : null));
</script>
