// Schedule maker (Vue): drafts, undo/redo, drag + up/down, validation, versions, live mode.
import '../theme/index.js';
import '@tabler/core/dist/js/tabler.esm.js';
import { createApp } from 'vue';
import ScheduleMaker from '../modules/schedule/ScheduleMaker.vue';
import { bootData } from '../shared/boot.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { initLiveBar } from '../shared/live-bar.js';

initThemeToggle();
initLiveBar();
createApp(ScheduleMaker, { boot: bootData() }).mount('#schedule-app');
