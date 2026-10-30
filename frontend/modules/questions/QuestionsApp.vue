<template>
  <div class="card">
    <div class="card-header">
      <ul class="nav nav-tabs card-header-tabs" role="tablist">
        <li v-for="a in audiences" :key="a.key" class="nav-item" role="presentation">
          <button type="button" class="nav-link" :class="{ active: aud === a.key }" role="tab" :aria-selected="aud === a.key" @click="aud = a.key">
            <Icon :name="a.icon" />{{ a.label }} <span class="badge bg-secondary-lt ms-1">{{ activeCount(a.key) }}</span>
            <Icon v-if="locked[a.key]" name="status.lock" cls="ms-1 text-secondary" label="Locked" />
          </button>
        </li>
      </ul>
    </div>
    <div class="card-body">
      <div v-if="locked[aud]" class="alert alert-info"><Icon name="status.lock" cls="me-1" />Reviews have been submitted by {{ aud === 'user' ? 'judges' : 'experts' }}. Only wording fixes are allowed now (logged with old and new text).</div>
      <p v-else class="text-secondary small">Each question is scored from 1 to its maximum (audience questions default to 5 marks, so two questions make 10). Each review's marks are added up (times the weights, normally 1) and scaled to 10. {{ isTemplate ? 'These templates are copied into new slams.' : 'Questions lock for an audience at its first submitted review.' }}</p>

      <div v-for="(q, i) in list" :key="q.id" class="card mb-2" :class="{ 'opacity-50': !q.is_active }">
        <div class="card-body p-2 p-sm-3">
          <div class="d-flex gap-2 align-items-start">
            <div class="btn-group-vertical btn-group-sm" v-if="!locked[aud]">
              <button type="button" class="btn btn-sm btn-ghost-secondary py-0" :disabled="i === 0" @click="move(i, -1)" :aria-label="`Move question ${i + 1} up`"><Icon name="action.up" /></button>
              <button type="button" class="btn btn-sm btn-ghost-secondary py-0" :disabled="i === list.length - 1" @click="move(i, 1)" :aria-label="`Move question ${i + 1} down`"><Icon name="action.down" /></button>
            </div>
            <div class="flex-fill">
              <template v-if="editing === q.id">
                <div class="mb-2"><label class="form-label small">English</label><input v-model="form.text" class="form-control" maxlength="500"></div>
                <div class="mb-2"><label class="form-label small">Malayalam (optional)</label><input v-model="form.text_ml" class="form-control ml-text" lang="ml" maxlength="500"></div>
                <div class="mb-2"><label class="form-label small">Help text (optional)</label><input v-model="form.help_text" class="form-control" maxlength="500"></div>
                <div class="row g-2 mb-2" v-if="!locked[aud]">
                  <div class="col-4"><label class="form-label small">Max marks</label><input v-model.number="form.max_score" type="number" step="1" min="2" max="10" class="form-control"></div>
                  <div class="col-4"><label class="form-label small">Weight</label><input v-model.number="form.weight" type="number" step="0.25" min="0.25" class="form-control"></div>
                  <div class="col-4 d-flex align-items-end"><label class="form-check form-switch"><input class="form-check-input" type="checkbox" v-model="form.is_active"><span class="form-check-label">Active</span></label></div>
                </div>
                <div class="btn-list"><button type="button" class="btn btn-primary btn-sm" @click="saveEdit(q)">Save</button><button type="button" class="btn btn-sm" @click="editing = null">Cancel</button></div>
              </template>
              <template v-else>
                <div class="fw-bold">{{ i + 1 }}. {{ q.text }}</div>
                <div v-if="q.text_ml" class="ml-text text-secondary" lang="ml">{{ q.text_ml }}</div>
                <div v-if="q.help_text" class="small text-secondary">{{ q.help_text }}</div>
                <div class="small mt-1"><span class="badge bg-blue-lt">{{ q.max_score }} marks</span> <span class="badge bg-secondary-lt">weight {{ q.weight }}</span> <span v-if="!q.is_active" class="badge bg-orange-lt">inactive</span></div>
              </template>
            </div>
            <div v-if="editing !== q.id" class="btn-list flex-nowrap">
              <button type="button" class="btn btn-sm" @click="startEdit(q)"><Icon name="action.edit" /><span class="d-none d-sm-inline">Edit</span></button>
              <button v-if="!locked[aud]" type="button" class="btn btn-sm btn-outline-danger btn-icon" aria-label="Delete question" @click="remove(q)"><Icon name="action.delete" /></button>
            </div>
          </div>
        </div>
      </div>

      <form v-if="!locked[aud]" class="card card-sm mt-3" @submit.prevent="add">
        <div class="card-body">
          <div class="row g-2">
            <div class="col-12 col-md-5"><input v-model="newQ.text" class="form-control" placeholder="New question (English)" maxlength="500" required></div>
            <div class="col-8 col-md-3"><input v-model="newQ.text_ml" class="form-control ml-text" lang="ml" placeholder="Malayalam (optional)" maxlength="500"></div>
            <div class="col-4 col-md-2"><input v-model.number="newQ.max_score" type="number" min="2" max="10" step="1" class="form-control" aria-label="Max marks" title="Max marks" required></div>
            <div class="col-12 col-md-2"><button class="btn btn-primary w-100" type="submit"><Icon name="action.add" />Add</button></div>
          </div>
        </div>
      </form>

      <div v-if="!isTemplate && otherSlams.length" class="mt-3 d-flex flex-wrap gap-2 align-items-center">
        <select v-model="copyFrom" class="form-select" style="max-width:280px" aria-label="Copy questions from slam"><option value="">Copy questions from another slam…</option><option v-for="s in otherSlams" :key="s.id" :value="s.id">{{ s.name }}</option></select>
        <button type="button" class="btn" :disabled="!copyFrom" @click="copy">Copy (replaces unlocked sets)</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { post, put, del } from '../../shared/api.js';
import { toast, dialog } from '../../shared/ui.js';
import Icon from '../../shared/components/Icon.vue';

const props = defineProps({ boot: Object });
const slamId = props.boot.slam_id;
const isTemplate = slamId === null;
const locked = reactive(props.boot.locked);
const questions = reactive(props.boot.questions);
const otherSlams = props.boot.other_slams || [];
const audiences = [{ key: 'user', label: 'Judges', icon: 'person.user' }, { key: 'judge', label: 'Experts', icon: 'person.judge' }];
const aud = ref('user');
const editing = ref(null);
const form = reactive({});
const defaultMax = (a) => (a === 'user' ? 5 : 10);
const newQ = reactive({ text: '', text_ml: '', max_score: 5 });
const copyFrom = ref('');
const list = computed(() => questions[aud.value]);
watch(aud, (a) => { newQ.max_score = defaultMax(a); });
const activeCount = (a) => questions[a].filter((q) => q.is_active).length;

function startEdit(q) { editing.value = q.id; Object.assign(form, { text: q.text, text_ml: q.text_ml || '', help_text: q.help_text || '', weight: q.weight, max_score: q.max_score, is_active: q.is_active }); }
async function saveEdit(q) {
  try { await put(`/api/questions/${q.id}`, { ...form }); Object.assign(q, { ...form }); editing.value = null; toast('Saved.'); } catch (e) { toast(e.message, 'error'); }
}
async function add() {
  try {
    const res = await post('/api/questions', { slam_id: isTemplate ? 'template' : slamId, audience: aud.value, text: newQ.text, text_ml: newQ.text_ml, weight: 1, max_score: newQ.max_score });
    questions[aud.value].push({ id: res.id, text: newQ.text, text_ml: newQ.text_ml || null, help_text: null, weight: 1, max_score: newQ.max_score, is_active: true });
    newQ.text = ''; newQ.text_ml = '';
    toast('Question added.');
  } catch (e) { toast(e.message, 'error'); }
}
async function remove(q) {
  if (await dialog({ title: 'Delete this question?', message: q.text, danger: true, confirmText: 'Delete' }) === null) return;
  try { await del(`/api/questions/${q.id}`); questions[aud.value] = questions[aud.value].filter((x) => x.id !== q.id); toast('Deleted.'); } catch (e) { toast(e.message, 'error'); }
}
async function move(i, d) {
  const arr = [...questions[aud.value]];
  [arr[i], arr[i + d]] = [arr[i + d], arr[i]];
  try { await post('/api/questions/reorder', { slam_id: isTemplate ? 'template' : slamId, audience: aud.value, ids: arr.map((q) => q.id) }); questions[aud.value] = arr; } catch (e) { toast(e.message, 'error'); }
}
async function copy() {
  if (await dialog({ title: 'Copy questions?', message: 'Unlocked question sets of this slam are replaced.', confirmText: 'Copy' }) === null) return;
  try { const r = await post(`/api/questions/copy-from/${copyFrom.value}`, { slam_id: slamId }); toast(`${r.copied} question(s) copied.`); setTimeout(() => window.location.reload(), 600); } catch (e) { toast(e.message, 'error'); }
}
</script>
