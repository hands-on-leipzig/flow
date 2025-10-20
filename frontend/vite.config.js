import {fileURLToPath, URL} from 'node:url'

import {defineConfig, loadEnv} from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

// https://vite.dev/config/
export default defineConfig(({mode}) => {
    const env = loadEnv(mode, process.cwd(), '');
    const baseURL = env.BASE_URL || 'http://localhost:8000';

    return {
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
                    target: baseURL,
                    changeOrigin: true,
                },
                // API-Endpunkte
                '^/api/.*': {
                    target: baseURL,
                    changeOrigin: true,
                },
                // Slug handler for event routing
                '^/slug-handler.php': {
                    target: baseURL,
                    changeOrigin: true,
                },
                // Output directory (zeitplan.cgi)
                '^/output/.*': {
                    target: baseURL,
                    changeOrigin: true,
                },
                // Event slugs are now handled by Vue Router, not proxied to backend
                // The backend slug-handler.php is no longer needed for frontend routing
            }
        }
    };
})



