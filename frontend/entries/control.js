// Admin phase control panel (Vue) + live tables on the same page.
import '../theme/index.js';
import '@tabler/core/dist/js/tabler.esm.js';
import { createApp } from 'vue';
import ControlApp from '../modules/control/ControlApp.vue';
import { bootData } from '../shared/boot.js';
import { initAllTables } from '../shared/datatable.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { initForms } from '../shared/forms.js';

initThemeToggle();
initForms();
window.slamTables = initAllTables();
createApp(ControlApp, { boot: bootData() }).mount('#control-app');
