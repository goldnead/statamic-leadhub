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
        // laravel-vite-plugin manages the output location (public/build) and
        // emits the manifest flat at public/build/manifest.json — which is
        // where Statamic's AddonServiceProvider publishes from and where the
        // Laravel/Statamic Vite tag looks for it. Setting `manifest: true`
        // explicitly would force Vite's own `.vite/manifest.json` location and
        // break Statamic's lookup, so we deliberately leave it to the plugin.
        emptyOutDir: false,
        rollupOptions: {
            external: ['@statamic/cms', '@statamic/cms/inertia', '@statamic/cms/ui'],
        },
    },
});
