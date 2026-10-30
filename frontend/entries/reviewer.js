// Reviewer phone screen. Deliberately small: Vue + our components + theme CSS only
// (no Tabler JS, no DataTables) to keep the bundle light on weak venue networks.
import '../theme/index.js';
import { createApp } from 'vue';
import ReviewerApp from '../modules/reviewer/ReviewerApp.vue';
import { bootData } from '../shared/boot.js';

createApp(ReviewerApp, { boot: bootData() }).mount('#reviewer-app');
