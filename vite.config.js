import { svelte } from '@sveltejs/vite-plugin-svelte';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [svelte()],
  build: {
    outDir: 'configuracao-assets',
    emptyOutDir: true,
    rollupOptions: {
      input: 'config-editor/index.html',
      output: {
        entryFileNames: 'config-editor.js',
        chunkFileNames: 'config-editor-[name].js',
        assetFileNames: 'config-editor.[ext]'
      }
    }
  }
});
