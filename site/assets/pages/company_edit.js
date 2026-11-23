export function initCompanyEditTabs() {
  const root = document.querySelector('[data-company-edit-tabs]');
  const panels = root?.querySelector('[data-company-edit-panels]');
  const Tab = (window.bootstrap || window.tabler?.bootstrap)?.Tab;
  if (!panels) return;

  const tabs = Array.from(root.querySelectorAll('a[role="tab"]'));
  if (tabs.length === 0) return;
  if (!Tab) {
    root.querySelector('[role="tablist"]')?.removeAttribute('role');
    tabs.forEach((tab) => {
      tab.removeAttribute('role');
      tab.removeAttribute('aria-selected');
      tab.classList.remove('active');
    });
    panels.querySelectorAll('[role="tabpanel"]').forEach((panel) => panel.removeAttribute('role'));
    return;
  }

  const tabFromHash = () => tabs.find((tab) => tab.hash === window.location.hash) ?? tabs[0];
  const showTabFromHash = (restoreFocus = false) => {
    const tab = tabFromHash();
    const focusTab = restoreFocus && !tab.classList.contains('active') && root.contains(document.activeElement);
    Tab.getOrCreateInstance(tab).show();
    if (focusTab) tab.focus({ preventScroll: true });
  };

  showTabFromHash();
  panels.classList.add('tab-content');

  root.addEventListener('shown.bs.tab', ({ target }) => {
    if (tabs.includes(target) && target !== tabFromHash()) {
      window.history.pushState(window.history.state, '', target.hash);
    }
  });
  window.addEventListener('hashchange', () => showTabFromHash(true));
  window.addEventListener('pageshow', ({ persisted }) => {
    if (persisted) showTabFromHash(true);
  });
}
