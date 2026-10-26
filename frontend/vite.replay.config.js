import { defineConfig } from 'vite'
import { fileURLToPath } from 'node:url'

// Builds the landing-side session recorder (replay/recorder.js) into one
// self-contained, minified IIFE at the repo root: /replay.js. It is served
// to visitors' browsers by nginx as a static file, next to /popup.js.
//   npm run build:replay
export default defineConfig({
  publicDir: false,
  build: {
    outDir: fileURLToPath(new URL('..', import.meta.url)),
    emptyOutDir: false,
    copyPublicDir: false,
    minify: 'esbuild',
    target: 'es2018',
    sourcemap: false,
    lib: {
      entry: fileURLToPath(new URL('./replay/recorder.js', import.meta.url)),
      formats: ['iife'],
      name: 'OrbitraReplay',
      fileName: () => 'replay.js',
    },
  },
})
