<template>
  <div class="vote-sheet-backdrop" @click.self="!sending && $emit('cancel')" @keydown.esc="!sending && $emit('cancel')">
    <div ref="sheet" class="vote-sheet" role="dialog" aria-modal="true" aria-labelledby="vote-sheet-title" tabindex="-1">
      <div class="vote-sheet-grip" aria-hidden="true"></div>
      <div class="d-flex gap-3 align-items-center mb-3">
        <img v-if="item.photo || item.thumb" class="talk-photo" style="width:4rem;height:4rem" :src="item.thumb || item.photo" :alt="item.photo_alt || item.presenter_name" width="64" height="64">
        <span v-else class="avatar avatar-lg flex-none"><Icon name="person.user" /></span>
        <div class="min-w-0">
          <div id="vote-sheet-title" class="h2 mb-0">Check your review</div>
          <div class="fw-bold ml-text text-truncate">{{ item.presenter_name }}</div>
          <div class="small text-secondary ml-text text-truncate">{{ item.topic }}</div>
        </div>
      </div>

      <div class="vote-lines">
        <div v-for="(q, i) in questions" :key="q.id" class="vote-line" :style="{ '--d': `${i * 90}ms` }">
          <div class="min-w-0"><span class="text-secondary me-1">{{ i + 1 }}.</span><span class="ml-text">{{ q.text }}</span></div>
          <div class="vote-chip"><span class="vote-chip-n">{{ answers[q.id] }}</span><span class="vote-chip-of">/ {{ q.max_score }}</span></div>
        </div>
      </div>
      <div class="vote-total" :style="{ '--d': `${questions.length * 90}ms` }">
        <span>Total</span><span><strong class="fs-2">{{ total }}</strong><span class="text-secondary"> / {{ max }}</span></span>
      </div>
      <div v-if="comment" class="small text-secondary ml-text mt-2 vote-comment">“{{ comment }}”</div>

      <div v-if="error" class="alert alert-danger mt-3 mb-0" role="alert">{{ error }}</div>
      <p v-else class="small text-secondary mt-3 mb-0">You can change your marks later, in the edit window at the end of the slam.</p>

      <div class="vote-actions">
        <button type="button" class="btn btn-lg" :disabled="sending" @click="$emit('cancel')"><Icon name="action.edit" />Change</button>
        <button type="button" class="btn btn-slam btn-lg flex-fill vote-confirm-btn" :class="{ sending }" :disabled="sending" @click="$emit('confirm')">
          <span v-if="sending" class="vote-spinner" aria-hidden="true"></span>
          <Icon v-else name="status.ok" />
          {{ sending ? (retrying ? 'Retrying…' : 'Sending your marks…') : error ? 'Try again' : 'Confirm and submit' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue';
import Icon from '../../shared/components/Icon.vue';

const props = defineProps({ item: Object, questions: Array, answers: Object, comment: String, sending: Boolean, retrying: Boolean, error: String });
defineEmits(['confirm', 'cancel']);
const sheet = ref(null);
const total = computed(() => Math.round(props.questions.reduce((a, q) => a + Number(props.answers[q.id] || 0), 0) * 10) / 10);
const max = computed(() => props.questions.reduce((a, q) => a + Number(q.max_score || 0), 0));
onMounted(() => sheet.value?.focus());
</script>
