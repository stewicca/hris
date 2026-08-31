import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig(async ({ command }) => {
    const plugins = [react(), tailwindcss()];

    // getUserMedia only works in a secure context, so the dev server needs
    // HTTPS to be testable on the tablet it will actually run on. Kept out of
    // the production build, where TLS is terminated upstream.
    if (command === 'serve') {
        const { default: basicSsl } = await import('@vitejs/plugin-basic-ssl');
        plugins.push(basicSsl());
    }

    return {
        plugins,
        server: {
            port: 5175,
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
