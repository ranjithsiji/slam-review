import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'node:path';

// Builds every screen's entry into public/assets with a manifest read by vite_tags() in PHP.
// Node is only needed on the developer machine / CI; deploy the built public/assets.
export default defineConfig({
  plugins: [vue()],
  resolve: {
    // vue-tabler ships .vue sources but its package exports block deep imports;
    // import single components by path through this alias (never the whole library).
    alias: { '@vue-tabler': resolve(__dirname, 'node_modules/@tak-ps/vue-tabler/components') },
  },
  base: '/assets/',
  publicDir: false,
  build: {
    outDir: 'public/assets',
    emptyOutDir: false,
    manifest: true,
    sourcemap: false,
    assetsInlineLimit: 0,
    chunkSizeWarningLimit: 900,
    rollupOptions: {
      input: {
        admin: resolve(__dirname, 'frontend/entries/admin.js'),
        public: resolve(__dirname, 'frontend/entries/public.js'),
        checkin: resolve(__dirname, 'frontend/entries/checkin.js'),
        reviewer: resolve(__dirname, 'frontend/entries/reviewer.js'),
        volunteer: resolve(__dirname, 'frontend/entries/volunteer.js'),
        control: resolve(__dirname, 'frontend/entries/control.js'),
        schedule: resolve(__dirname, 'frontend/entries/schedule.js'),
        questions: resolve(__dirname, 'frontend/entries/questions.js'),
        stats: resolve(__dirname, 'frontend/entries/stats.js'),
      },
    },
  },
});
