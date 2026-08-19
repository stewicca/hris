import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { execSync } from 'node:child_process';
import { defineConfig } from 'vite';

// Wayfinder needs a PHP runtime. Prefer one on PATH; otherwise reach into the
// running app container, whichever engine started it.
const getWayfinderCommand = () => {
    const candidates = [
        ['php -v', 'php artisan wayfinder:generate'],
        ['docker exec hris_app php -v', 'docker exec hris_app php artisan wayfinder:generate'],
        ['podman exec hris_app php -v', 'podman exec hris_app php artisan wayfinder:generate'],
    ];

    for (const [probe, command] of candidates) {
        try {
            execSync(probe, { stdio: 'ignore' });
            return command;
        } catch {
            // Try the next runtime.
        }
    }

    // Nothing found. Return the plain command rather than throwing: failing
    // here aborts config loading and takes the whole build down, even for
    // targets that never need Wayfinder. Let the plugin report it instead.
    return 'php artisan wayfinder:generate';
};

export default defineConfig({
    server: {
        host: '0.0.0.0',
        port: 5173,
        watch: {
            usePolling: true,
        },
        hmr: {
            host: 'localhost',
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
        }),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
            command: getWayfinderCommand(),
        }),
    ],
    esbuild: {
        jsx: 'automatic',
    },
});
