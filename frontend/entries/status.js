// Status timeline for one slam (Vue): where it is now, with action buttons on each card.
import '../theme/index.js';
import '@tabler/core/dist/js/tabler.esm.js';
import { createApp } from 'vue';
import StatusApp from '../modules/control/StatusApp.vue';
import { bootData } from '../shared/boot.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { initLiveBar } from '../shared/live-bar.js';
import { initForms } from '../shared/forms.js';
import { initAllTables } from '../shared/datatable.js';

initThemeToggle();
initLiveBar();
initForms();
window.slamTables = initAllTables();
createApp(StatusApp, { boot: bootData() }).mount('#status-app');
