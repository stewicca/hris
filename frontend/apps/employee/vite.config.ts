import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig(async ({ command }) => {
    const plugins = [
        react(),
        tailwindcss(),
    ];

    // HTTPS is only needed for the dev server (e.g. testing geolocation on a
    // LAN device). Keep it out of the production build so the plugin is never
    // required at build time.
    if (command === 'serve') {
        const { default: basicSsl } = await import('@vitejs/plugin-basic-ssl');
        plugins.push(basicSsl());
    }

    return {
        plugins,
        server: {
            port: 5174,
            host: '0.0.0.0',
            proxy: {
                '/api': {
                    target: 'http://localhost:8080',
                    changeOrigin: true,
                },
            },
        },
        resolve: {
            alias: {
                '@': '/src',
            },
        },
    };
});
