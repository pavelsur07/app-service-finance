import { startStimulusApp, registerControllers } from 'vite-plugin-symfony/stimulus/helpers';

// startStimulusApp() регистрирует 3rd-party контроллеры из controllers.json.
const app = startStimulusApp();

// Локальные контроллеры из assets/controllers/*_controller.js.
// vite-plugin-symfony НЕ сканирует папку сам — регистрируем через glob.
// Имя файла → identifier: password_toggle_controller → data-controller="password-toggle".
registerControllers(
  app,
  import.meta.glob('./controllers/*_controller.js', { query: '?stimulus', eager: true }),
);

export { app };
