import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        hmr: {
            host: 'localhost',
        },
    },
    build: {
        chunkSizeWarningLimit: 600,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) {
                        return undefined;
                    }
                    if (id.includes('react-dom') || /[\\/]react[\\/]/.test(id) || id.includes('scheduler')) {
                        return 'vendor-react';
                    }
                    if (id.includes('recharts') || id.includes('d3-')) {
                        return 'vendor-charts';
                    }
                    if (id.includes('xlsx')) {
                        return 'vendor-xlsx';
                    }
                    if (id.includes('@inertiajs')) {
                        return 'vendor-inertia';
                    }
                    if (id.includes('lucide-react')) {
                        return 'vendor-icons';
                    }
                    if (id.includes('framer-motion')) {
                        return 'vendor-motion';
                    }
                    if (id.includes('@tanstack')) {
                        return 'vendor-table';
                    }
                    if (id.includes('date-fns') || id.includes('react-day-picker')) {
                        return 'vendor-date';
                    }

                    return 'vendor';
                },
            },
        },
    },
});
