import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Development: the Vite dev server proxies API calls to Laravel so the browser
// sees a single origin (simplifying cookie/CSRF). Production: assets are built
// under `/app/` directly into Laravel's public directory so the SPA is served
// same-origin by Laravel (see routes/web.php).
export default defineConfig(({ command }) => ({
    plugins: [react()],
    base: command === 'build' ? '/app/' : '/',
    server: {
        port: 5173,
        proxy: {
            '/api': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: false,
            },
        },
    },
    build: {
        outDir: '../public/app',
        emptyOutDir: true,
    },
}));
