import {fileURLToPath, URL} from 'node:url'

import {defineConfig, loadEnv} from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import {VitePWA} from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig(({mode}) => {
    const env = loadEnv(mode, process.cwd(), '');
    const serverURL = env.VITE_FILES_BASE_URL || 'http://localhost:8000';

    return {
        plugins: [
            vue(),
            VitePWA({
                registerType: 'autoUpdate',
                includeAssets: ['favicon.ico', 'apple-touch-icon.png', 'pwa-192x192.png', 'pwa-512x512.png'],
                manifest: {
                    name: 'FLOW - Flexibles OrganisationsWerkzeug',
                    short_name: 'FLOW',
                    description: 'FLOW event planning and organization tool',
                    theme_color: '#ffffff',
                    background_color: '#ffffff',
                    display: 'standalone',
                    orientation: 'portrait',
                    scope: '/',
                    start_url: '/plan/event',
                    icons: [
                        {
                            src: '/pwa-192x192.png',
                            sizes: '192x192',
                            type: 'image/png'
                        },
                        {
                            src: '/pwa-512x512.png',
                            sizes: '512x512',
                            type: 'image/png'
                        },
                        {
                            src: '/pwa-512x512.png',
                            sizes: '512x512',
                            type: 'image/png',
                            purpose: 'maskable'
                        }
                    ]
                },
                workbox: {
                    globPatterns: ['**/*.{js,css,html,ico,png,svg,woff,woff2,ttf}'],
                    navigateFallback: 'index.html',
                    // Do not rewrite backend/public endpoints to SPA shell.
                    // This keeps /output/zeitplan.cgi and similar routes working.
                    navigateFallbackDenylist: [
                        /^\/output\//,
                        /^\/api\//,
                        /^\/schedule\//,
                        /^\/slug-handler\.php/,
                    ],
                    cleanupOutdatedCaches: true,
                    clientsClaim: true,
                    skipWaiting: true
                },
                devOptions: {
                    enabled: false
                }
            }),
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
                    target: serverURL,
                    changeOrigin: true,
                },
                // API-Endpunkte
                '^/api/.*': {
                    target: serverURL,
                    changeOrigin: true,
                },
                // Slug handler for event routing
                '^/slug-handler.php': {
                    target: serverURL,
                    changeOrigin: true,
                },
                // Output directory (zeitplan.cgi)
                '^/output/.*': {
                    target: serverURL,
                    changeOrigin: true,
                },
                // Public images
                '^/flow/.*': {
                    target: serverURL,
                    changeOrigin: true,
                }
                // Event slugs are now handled by Vue Router, not proxied to backend
                // The backend slug-handler.php is no longer needed for frontend routing
            }
        }
    };
})



