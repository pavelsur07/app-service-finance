/**
 * Ваш Финдир · Tailwind config — РЕФЕРЕНС для нового слоя (ДС v3.4, docs/plan/ds-v3.md §1.2)
 *
 * Только новый React-слой. Легаси (Tabler) и CSS кита (assets/styles/kit) Tailwind не трогает:
 *   - preflight выключен — иначе сброс стилей сломает Tabler и кит;
 *   - content — только файлы нового слоя;
 *   - компоненты (.btn, .card, .sb, .t-table …) остаются классами кита; Tailwind — для раскладки
 *     и мелких утилит поверх тех же CSS-переменных.
 *
 * Принцип: Tailwind не хранит цвета — он ссылается на CSS-переменные кита (assets/styles/kit/tokens).
 * Тема (светлая / тёмная / системная) переключается только переменными,
 * поэтому классы вида `dark:bg-…` в компонентах не нужны.
 *
 * Отступы — стандартная шкала Tailwind (1 = 4px); она совпадает с сеткой кита:
 *   --s-0_5=0.5  --s-1=1  --s-1_5=1.5  --s-2=2  --s-2_5=2.5  --s-3=3  --s-3_5=3.5
 *   --s-4=4  --s-5=5  --s-6=6  --s-7(32px)=8  --s-8(40px)=10  --s-9(48px)=12  --s-10(64px)=16
 */
const v = (name) => `var(--${name})`;

/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './templates/_layout/app.html.twig',
    './templates/app/**/*.twig',
    './assets/react/**/*.{ts,tsx}',
  ],
  corePlugins: { preflight: false },
  // Тёмная тема отложена (docs/plan/ds-v3.md §1.3); селектор зарезервирован.
  darkMode: ['selector', '[data-theme="dark"]'],
  theme: {
    // Заменяем, а не расширяем: в проекте доступны только цвета системы.
    colors: {
      transparent: 'transparent',
      current: 'currentColor',
      white: '#FFFFFF',
      surface: {
        page: v('bg-page'),
        card: v('bg-card'),
        subtle: v('bg-subtle'),
        'subtle-hover': v('bg-subtle-hover'),
        'row-hover': v('bg-row-hover'),
        'row-selected': v('bg-row-selected'),
        dark: v('bg-dark'),
      },
      fg: {
        strong: v('text-strong'),
        secondary: v('text-secondary'),
        tertiary: v('text-tertiary'),
        muted: v('text-muted'),
        disabled: v('text-disabled'),
        faint: v('text-faint'),
        inverse: v('text-inverse'),
      },
      line: {
        DEFAULT: v('border'),
        soft: v('border-soft'),
        hover: v('border-hover'),
        strong: v('border-strong'),
      },
      primary: {
        DEFAULT: v('color-primary'),
        hover: v('color-primary-hover'),
        active: v('color-primary-active'),
        tint: v('color-primary-tint'),
        border: v('color-primary-border'),
        selection: v('color-primary-selection'),
      },
      // Только бренд-моменты (логотип, промо). Не для кнопок — Decision 44.
      accent: {
        DEFAULT: v('color-accent'),
        hover: v('color-accent-hover'),
        tint: v('color-accent-tint'),
      },
      btn: {
        primary: v('button-primary-bg'),
        'primary-hover': v('button-primary-bg-hover'),
        'primary-active': v('button-primary-bg-active'),
        'primary-fg': v('button-primary-text'),
      },
      success: { bg: v('success-bg'), border: v('success-border'), text: v('success-text'), dot: v('success-dot') },
      danger: { bg: v('danger-bg'), border: v('danger-border'), text: v('danger-text'), dot: v('danger-dot') },
      warning: { bg: v('warning-bg'), border: v('warning-border'), text: v('warning-text'), dot: v('warning-dot') },
      info: { bg: v('info-bg'), border: v('info-border'), text: v('info-text'), dot: v('info-dot') },
    },
    fontFamily: {
      sans: ['Manrope', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
      mono: ['ui-monospace', 'SFMono-Regular', 'SF Mono', 'Menlo', 'monospace'],
    },
    // [размер, интерлиньяж] — 10 ступеней кита (Decision 34)
    fontSize: {
      '2xs': ['10px', '1.35'],
      xs: ['11px', '1.35'],
      sm: ['12px', '1.35'],
      md: ['13px', '1.5'],   // body
      lg: ['14px', '1.5'],
      xl: ['16px', '1.2'],
      '2xl': ['18px', '1.2'],
      '3xl': ['22px', '1.2'],
      '4xl': ['28px', '1.2'],
      '5xl': ['32px', '1.2'],
    },
    borderRadius: {
      none: '0',
      xs: v('r-xs'),
      sm: v('r-sm'),
      md: v('r-md'),
      lg: v('r-lg'),
      xl: v('r-xl'),
      full: v('r-pill'),
    },
    boxShadow: {
      none: 'none',
      sm: v('shadow-sm'),
      'card-hover': v('shadow-card-hover'),
      primary: v('shadow-primary'),
      menu: v('shadow-menu'),
      modal: v('shadow-modal'),
      drawer: v('shadow-drawer'),
      'ring-focus': v('ring-focus'),
      'ring-error': v('ring-error'),
    },
    zIndex: {
      auto: 'auto',
      raised: v('z-raised'),
      sticky: v('z-sticky'),
      header: v('z-header'),
      dropdown: v('z-dropdown'),
      overlay: v('z-overlay'),
      toast: v('z-toast'),
    },
    extend: {
      spacing: {
        sidebar: v('app-sidebar-width'),        // 264px
        'sidebar-rail': '72px',                // свёрнутый сайдбар
        header: v('app-header-height'),        // 60px
        control: v('control-h'),               // 38px
        'control-sm': v('control-h-sm'),       // 32px
      },
      letterSpacing: { tight: v('ls-tight'), caps: v('ls-caps') },
      transitionDuration: { fast: '120ms', base: '160ms', slow: '240ms' },
      transitionTimingFunction: { standard: v('ease-standard'), exit: v('ease-exit') },
      outlineColor: { focus: v('focus-ring-color') },
    },
  },
  plugins: [],
};
