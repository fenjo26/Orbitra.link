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
    minify: false,
    rollupOptions: {
      output: {
        entryFileNames: `assets/[name].js`,
        chunkFileNames: `assets/[name].js`,
        assetFileNames: `assets/[name].[ext]`
      }
    }
  },
})
