// Volunteer desk: server-rendered shell + Vue check-in app + the recent check-ins DataTable.
import '../theme/index.js';
import '@tabler/core/dist/js/tabler.esm.js';
import { createApp } from 'vue';
import VolunteerApp from '../modules/volunteer/VolunteerApp.vue';
import { bootData } from '../shared/boot.js';
import { initAllTables } from '../shared/datatable.js';
import { initThemeToggle } from '../shared/theme-toggle.js';

initThemeToggle();
window.slamTables = initAllTables();
createApp(VolunteerApp, { boot: bootData() }).mount('#volunteer-app');
