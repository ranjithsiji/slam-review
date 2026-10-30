<template>
  <div>
    <div class="card mb-3 border-purple">
      <div class="card-body d-flex align-items-center gap-3">
        <Icon name="phase.revision" cls="icon-lg text-purple" />
        <div class="flex-fill">
          <div class="fw-bold">Edit your scores</div>
          <div class="small text-secondary">Only performances you already reviewed. Changes are saved one by one.</div>
        </div>
        <Countdown :until="closesAt" size="xl" />
      </div>
    </div>
    <div v-if="loading" class="text-center py-4"><div class="spinner-border" role="status"></div></div>
    <div v-else-if="!reviews.length" class="empty"><p class="empty-title">You did not review any performance.</p><p class="empty-subtitle text-secondary">There is nothing to edit.</p></div>
    <div v-for="r in reviews" :key="r.id" class="card mb-2">
      <button class="card-header btn btn-link text-start text-reset w-100 d-flex align-items-center justify-content-start" type="button" :aria-expanded="open === r.id" @click="toggle(r)">
        <div class="flex-fill">
          <div class="fw-bold ml-text">{{ r.presenter_name }}</div>
          <div class="small text-secondary ml-text">{{ r.topic }}</div>
        </div>
        <span class="badge bg-secondary-lt me-2">{{ r.answers.map((a) => a.score).join(' · ') }}</span>
        <span v-if="saved[r.id]" class="badge bg-green-lt me-2"><Icon name="status.ok" cls="icon-inline" /> saved</span>
        <Icon :name="open === r.id ? 'chevron-up' : 'chevron-down'" />
      </button>
      <div v-if="open === r.id" class="card-body">
        <ScoreQuestion v-for="(q, i) in questions" :key="q.id" :question="q" :index="i" v-model="draft[q.id]" :disabled="!editable || busy" />
        <label class="form-label" :for="`c-${r.id}`">Comment (optional)</label>
        <textarea :id="`c-${r.id}`" v-model="draftComment" class="form-control mb-3" rows="2" maxlength="1000" :disabled="!editable || busy"></textarea>
        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <button class="btn btn-primary w-100" type="button" :disabled="!editable || busy || !complete" @click="save(r)">
          <span v-if="busy" class="spinner-border spinner-border-sm me-2"></span>{{ busy ? 'Saving…' : 'Save changes' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { get, put } from '../../shared/api.js';
import Icon from '../../shared/components/Icon.vue';
import Countdown from '../../shared/components/Countdown.vue';
import ScoreQuestion from './ScoreQuestion.vue';

const props = defineProps({ questions: Array, closesAt: String });
const reviews = ref([]);
const editable = ref(false);
const loading = ref(true);
const open = ref(null);
const draft = reactive({});
const draftComment = ref('');
const busy = ref(false);
const error = ref('');
const saved = reactive({});
const complete = computed(() => props.questions.every((q) => Number.isInteger(draft[q.id])));

async function load() {
  try {
    const res = await get('/api/my-reviews');
    reviews.value = res.data;
    editable.value = res.editable;
  } finally {
    loading.value = false;
  }
}
function toggle(r) {
  error.value = '';
  if (open.value === r.id) { open.value = null; return; }
  open.value = r.id;
  Object.keys(draft).forEach((k) => delete draft[k]);
  r.answers.forEach((a) => { draft[a.question_id] = a.score; });
  draftComment.value = r.comment || '';
}
async function save(r) {
  busy.value = true;
  error.value = '';
  try {
    const answers = props.questions.map((q) => ({ question_id: q.id, score: draft[q.id] }));
    await put(`/api/review/${r.id}`, { answers, comment: draftComment.value });
    r.answers = answers;
    r.comment = draftComment.value;
    saved[r.id] = true;
    open.value = null;
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}
onMounted(load);
defineExpose({ load });
</script>
