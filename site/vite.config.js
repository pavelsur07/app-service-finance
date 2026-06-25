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
                dashboard: "./assets/react/_legacy/dashboard_started.tsx", // Точка ./ обязательна!
                marketplace_analytics_kpi: "./assets/react/_legacy/marketplace_analytics_kpi.tsx",
                marketplace_analytics_page: "./assets/react/_legacy/marketplace-analytics-page.tsx",
                reconciliation_page: "./assets/react/_legacy/reconciliation-page.tsx",
                unit_extended_page: "./assets/react/_legacy/unit-extended-page.tsx",
                ad_efficiency_page: "./assets/react/_legacy/ad-efficiency-page.tsx",
                ingestion_verification_coverage_page: "./assets/react/_legacy/ingestion-verification-coverage-page.tsx",
                ingestion_verification_reconciliation_page: "./assets/react/_legacy/ingestion-verification-reconciliation-page.tsx",
                ingestion_verification_issues_page: "./assets/react/_legacy/ingestion-verification-issues-page.tsx",
                ingestion_verification_financial_summary_page: "./assets/react/_legacy/ingestion-verification-financial-summary-page.tsx",
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
