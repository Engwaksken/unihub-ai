import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// Fonts are self-hosted from @fontsource/poppins (imported in resources/css/app.css);
// nothing is loaded from a third-party CDN at runtime.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
