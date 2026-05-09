import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import path from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            hotFile: 'public/hot',
            publicDirectory: 'public',
            input: [
                'resources/js/cp.js',
                'resources/css/cp.css',
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],

    resolve: {
        alias: {
            '@statamic/cms': path.resolve(__dirname, '../../statamic/cms/resources/js'),
        },
    },

    build: {
        outDir: 'public',
        emptyOutDir: false,
        manifest: true,
        rollupOptions: {
            external: ['@statamic/cms', '@statamic/cms/inertia', '@statamic/cms/ui'],
        },
    },
});
