import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import source from '../tokens/ds-v3.tokens.json';
import reference from '../tests/fixtures/light-css-values.json';
import { tokens } from './index';

const read = (path: string): string =>
  readFileSync(new URL(path, import.meta.url), 'utf8');
const normalize = (value: string): string =>
  value.replace(/\s+/g, '').replace(/#[\da-f]+/gi, (hex) => hex.toLowerCase());

function cssDeclarations(css: string): Record<string, string> {
  return Object.fromEntries(
    [...css.matchAll(/--ds-([\w-]+):\s*([^;]+);/g)].map((match) => [
      match[1],
      match[2]?.trim(),
    ]),
  );
}

const groups = {
  primitive: source.primitive,
  semantic: source.semantic,
  component: source.component,
  deprecated: source.deprecated,
};

describe('light token contract from UI Kit v3.4', () => {
  for (const [group, definitions] of Object.entries(groups)) {
    it(`exports every ${group} token with its reference CSS value`, () => {
      const css = cssDeclarations(read('./tokens.css'));
      for (const [name, token] of Object.entries(definitions)) {
        expect(css[name], name).toBeDefined();
        const expected = reference[name as keyof typeof reference];
        expect(expected, name).toBeDefined();
        expect(normalize(css[name] ?? ''), name).toBe(
          normalize(expected.replace(/var\(--/g, 'var(--ds-')),
        );
        expect(normalize(css[name] ?? ''), name).toBe(
          normalize(token.$value.replace(/\{([^}]+)\}/g, 'var(--ds-$1)')),
        );
      }
    });
  }

  it('has no missing, extra, duplicate or unresolved CSS tokens', () => {
    const css = read('./tokens.css');
    const names = [...css.matchAll(/--ds-([\w-]+):/g)].map((match) => match[1]);
    expect(names.length).toBe(new Set(names).size);
    expect([...names].sort()).toEqual(Object.keys(reference).sort());
    const declared = new Set(names);
    for (const match of css.matchAll(/var\(--ds-([\w-]+)\)/g)) {
      expect(declared.has(match[1]), match[1]).toBe(true);
    }
    expect(css).not.toMatch(/\{[\w-]+\}/);
    expect(css).not.toMatch(/--ds-(primitive|semantic|component|deprecated)-/);
  });

  it('keeps dark theme and component styles out of the token entry', () => {
    const css = read('./tokens.css');
    expect(css.match(/:root\s*\{/g)).toHaveLength(1);
    expect(css).not.toMatch(/data-theme|prefers-color-scheme|color-scheme/);
    expect(css.match(/\{/g)).toHaveLength(1);
    expect(css.match(/\}/g)).toHaveLength(1);
  });

  it('exports resolved, typed TS values matching the complete CSS graph', () => {
    expect(Object.keys(tokens).sort()).toEqual(Object.keys(reference).sort());
    const resolve = (name: string): string => {
      const value = reference[name as keyof typeof reference];
      return value.replace(/var\(--([\w-]+)\)/g, (_, ref: string) =>
        resolve(ref),
      );
    };
    for (const name of Object.keys(reference)) {
      expect(normalize(tokens[name as keyof typeof tokens]), name).toBe(
        normalize(resolve(name)),
      );
    }
  });

  it('exposes all package entry points as existing files', () => {
    const manifest = JSON.parse(read('../package.json')) as {
      exports: Record<string, string>;
    };
    expect(Object.keys(manifest.exports).sort()).toEqual([
      '.',
      './styles.css',
      './tokens.css',
    ]);
    for (const path of Object.values(manifest.exports)) {
      expect(read(`../${path}`).length).toBeGreaterThan(0);
    }
  });
});
