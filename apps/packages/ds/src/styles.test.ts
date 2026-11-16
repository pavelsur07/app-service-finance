import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const read = (path: string): string =>
  readFileSync(new URL(path, import.meta.url), 'utf8');

describe('DS v3.4 Tailwind preset', () => {
  it('includes preflight, light tokens and only explicit component sources', () => {
    const css = read('./styles.css');
    expect(css).toMatch(/@import ['"]tailwindcss['"] source\(none\)/);
    expect(css).toContain("@import './tokens.css'");
    expect(css).toContain("@source './components'");
    expect(css).toContain("@source not './components/**/*.test.ts'");
    expect(css).toContain("@source not './components/**/*.test.tsx'");
    expect(css).not.toMatch(
      /data-theme|prefers-color-scheme|site\/|account\/|admin\//,
    );
  });

  it('compiles DS utilities while rejecting the built-in palette and type scale', async () => {
    const { compile } = await import('tailwindcss');
    const defaults = read('../node_modules/tailwindcss/theme.css');
    const css = read('./styles.css').replace(/^@(?:import|source) .*$/gm, '');
    const compiler = await compile(`${defaults}\n${css}\n@tailwind utilities;`);
    const output = compiler.build([
      'bg-primary',
      'text-md',
      'rounded-md',
      'shadow-menu',
      'p-4',
      'bg-blue-500',
      'text-base',
      'rounded-3xl',
      'shadow-2xl',
      '2xl:flex',
    ]);
    expect(output).toContain('.bg-primary');
    expect(output).toContain('var(--ds-color-primary)');
    expect(output).toContain('var(--ds-fs-md)');
    expect(output).toContain('var(--ds-r-md)');
    expect(output).toContain('var(--ds-shadow-menu)');
    for (const forbidden of [
      '.bg-blue-500',
      '.text-base',
      '.rounded-3xl',
      '.shadow-2xl',
      '.2xl\\:flex',
    ]) {
      expect(output).not.toContain(forbidden);
    }
    expect(output).toContain('padding: calc(4px * 4)');
  });

  it('uses the self-hosted variable Manrope with a retained license', () => {
    const css = read('./styles.css');
    expect(css).toContain("url('./fonts/Manrope-variable.ttf')");
    expect(css).toMatch(/font-weight:\s*200 800/);
    expect(css).toMatch(/font-display:\s*swap/);
    expect(read('../public/fonts/Manrope-OFL.txt')).toContain(
      'SIL OPEN FONT LICENSE',
    );
    expect(read('./fonts/Manrope-variable.ttf').length).toBeGreaterThan(1000);
    expect(css).not.toMatch(/https?:\/\//);
  });

  for (const app of ['account', 'admin']) {
    it(`${app} imports DS and restricts Tailwind discovery to its src`, () => {
      const main = read(`../../../${app}/src/main.tsx`);
      expect(main).not.toContain('@vashfindir/ui/styles.css');
      expect(main).toContain("import './styles.css'");
      const entry = read(`../../../${app}/src/styles.css`);
      expect(entry).toContain("@import '@vashfindir/ds/styles.css'");
      expect(entry).toContain("@source './'");
      expect(entry).toContain("@source not './**/*.test.ts'");
      expect(entry).toContain("@source not './**/*.test.tsx'");
      const config = read(`../../../${app}/vite.config.ts`);
      expect(config).toContain('@tailwindcss/vite');
      expect(config).toContain('tailwindcss()');
    });
  }
});
