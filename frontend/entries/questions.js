import '../theme/index.js';
import '@tabler/core/dist/js/tabler.esm.js';
import { createApp } from 'vue';
import QuestionsApp from '../modules/questions/QuestionsApp.vue';
import { bootData } from '../shared/boot.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { initLiveBar } from '../shared/live-bar.js';

initThemeToggle();
initLiveBar();
createApp(QuestionsApp, { boot: bootData() }).mount('#questions-app');
