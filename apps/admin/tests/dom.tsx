import { act, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';

Object.assign(globalThis, { IS_REACT_ACT_ENVIRONMENT: true });
let root: Root;
export async function render(node: ReactNode) {
  if (!root) {
    const host = document.createElement('div');
    document.body.append(host);
    root = createRoot(host);
  }
  await act(async () => root.render(node));
}
export async function cleanup() {
  if (root) await act(async () => root.unmount());
  root = undefined as unknown as Root;
  document.body.innerHTML = '';
}
export function input(name: string) {
  const node = document.querySelector<HTMLInputElement>(
    `input[name="${name}"]`,
  );
  if (!node) throw new Error(`Missing input ${name}`);
  return node;
}
export async function fill(name: string, value: string) {
  await act(async () => {
    Object.getOwnPropertyDescriptor(
      HTMLInputElement.prototype,
      'value',
    )?.set?.call(input(name), value);
    input(name).dispatchEvent(new Event('input', { bubbles: true }));
  });
}
export async function submit() {
  await act(async () =>
    document
      .querySelector('form')
      ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })),
  );
}
