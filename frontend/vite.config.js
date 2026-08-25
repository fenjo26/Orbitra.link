import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  base: '/frontend/dist/',
  server: {
    proxy: {
      '/api.php': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      }
    }
  },
  plugins: [
    tailwindcss(),
    react(),
  ],
  build: {
    minify: 'esbuild',
    // Content-hashed filenames (Vite's default [name]-[hash] pattern): a
    // rebuild changes the URL, so browsers pick a new release up on a normal
    // reload. admin.php resolves the entry through .vite/manifest.json —
    // never a hardcoded filename.
    manifest: true,
  },
})
