// Admin & volunteer server-rendered pages: Tabler JS (dropdowns, modals, collapse),
// server-side DataTables, theme toggle and small form helpers.
import '../theme/index.js';
import '@tabler/core/dist/js/tabler.esm.js';
import { initAllTables } from '../shared/datatable.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { initLiveBar } from '../shared/live-bar.js';
import { initForms } from '../shared/forms.js';
import { initActivityLog } from '../shared/activity-log.js';
import { initServerClock } from '../shared/server-clock.js';

initThemeToggle();
initLiveBar();
initForms();
const tables = initAllTables();
window.slamTables = tables;
initActivityLog(tables['activity-log']);
initServerClock(document.querySelector('[data-server-clock]'));
