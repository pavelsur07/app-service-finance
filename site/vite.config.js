import { defineConfig } from "vite";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";
import symfonyPlugin from "vite-plugin-symfony";
import react from '@vitejs/plugin-react';

const __dirname = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
    plugins: [
        react(),
        symfonyPlugin(),
    ],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'assets'),
        },
    },
    // Настройки для продакшен-сборки (внутри Docker)
    build: {
        outDir: "public/build", // Явно говорим, куда класть результат
        rollupOptions: {
            input: {
                app: "./assets/app.js",
                design_tokens: "./assets/styles/design-tokens.css",
                vf_custom_classes: "./assets/styles/vf-custom-classes.css",
                dashboard: "./assets/react/dashboard_started.tsx", // Точка ./ обязательна!
                marketplace_analytics_kpi: "./assets/react/marketplace_analytics_kpi.tsx",
                marketplace_analytics_page: "./assets/react/marketplace-analytics-page.tsx",
                reconciliation_page: "./assets/react/reconciliation-page.tsx",
                unit_extended_page: "./assets/react/unit-extended-page.tsx",
                ad_efficiency_page: "./assets/react/ad-efficiency-page.tsx",
            },
        }
    },
    // Настройки для локальной разработки, чтобы ничего не сломалось
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        watch: {
            usePolling: true,
        },
        hmr: {
            host: 'localhost',
        }
    }
});
