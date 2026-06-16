import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

/**
 * Builds the field into a single self-contained IIFE bundle (dist/js/field.js)
 * plus its stylesheet (dist/css/field.css).
 *
 * Vue is provided by Nova at runtime as the global `Vue`, so it stays external.
 * The components rely on Nova's globally-registered `DefaultField` / `PanelItem`
 * components, which means the bundle does NOT need the private `laravel/nova`
 * package to be present at build time. The output works in both Nova 4 and 5.
 */
export default defineConfig({
  plugins: [vue()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    lib: {
      entry: 'resources/js/field.js',
      formats: ['iife'],
      name: 'NovaMultipleFileField',
    },
    rollupOptions: {
      external: ['vue'],
      output: {
        globals: { vue: 'Vue' },
        entryFileNames: 'js/field.js',
        assetFileNames: (asset) => {
          if (asset.name && asset.name.endsWith('.css')) return 'css/field.css'
          return 'assets/[name][extname]'
        },
      },
    },
  },
})
