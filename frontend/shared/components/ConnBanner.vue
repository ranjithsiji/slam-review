<template>
  <div v-if="status === 'retrying' || status === 'offline'" class="conn-banner" :class="status === 'offline' ? 'bg-danger text-white' : 'bg-warning text-dark'" role="status" aria-live="polite">
    <Icon name="status.offline" cls="icon-inline me-1" />
    {{ status === 'offline' ? 'Connection lost — reconnecting…' : 'Reconnecting…' }}
  </div>
</template>

<script setup>
import Icon from './Icon.vue';
defineProps({ status: { type: String, default: 'ok' } });
</script>
