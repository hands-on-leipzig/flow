import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    // vueDevTools(), // Temporarily disabled due to Vue compatibility issues
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    },
  },

  // Proxy configuration to forward requests to the backend server
  server: {
    port: 5173,
    proxy: {
      // Blade-Views (unsere Tabelle)
      '^/schedule/.*': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      // API-Endpunkte
      '^/api/.*': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      // Slug handler for event routing
      '^/slug-handler.php': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      // Output directory (zeitplan.cgi)
      '^/output/.*': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      // Event slugs - proxy to backend slug handler
      // Only proxy known event slugs to avoid conflicts with Vue routes
      '^/(test-region-a-explore|test-challenge-event-a|test-region-b)$': {
        target: 'http://localhost:8000',
        changeOrigin: true,
        rewrite: (path) => `/slug-handler.php?slug=${path.substring(1)}`
      },
    }
  }
})



