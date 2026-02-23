import { defineConfig } from "vite";
import symfonyPlugin from "vite-plugin-symfony";
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        react(),
        symfonyPlugin(),
    ],
    // Настройки для продакшен-сборки (внутри Docker)
    build: {
        outDir: "public/build", // Явно говорим, куда класть результат
        rollupOptions: {
            input: {
                app: "./assets/app.js",
                dashboard: "./assets/react/dashboard_started.tsx", // Точка ./ обязательна!
                marketplace_analytics_kpi: "./assets/react/marketplace_analytics_kpi.tsx",
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
