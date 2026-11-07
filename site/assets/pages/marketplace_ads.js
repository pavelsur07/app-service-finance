/**
 * «Реклама маркетплейсов» (templates/marketplace_ads/index.html.twig): история загрузок и сырые
 * документы грузятся из API (адреса — на корне [data-marketplace-ads]; адреса с id — шаблоны с
 * плейсхолдером-UUID), модалы «Загрузить за период» и «Пометить FAILED», поллер прогресса
 * активной загрузки. Данные API — только textContent; «Обработать» — через _confirm_modal.
 */
import { apiFetch } from '../api/client';
import { bootstrapModal } from '../bootstrap_modal.js';
import { el } from '../dom.js';

const ID_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';
const JOB_STATUS = {
  pending: ['bg-secondary', 'В очереди'],
  running: ['bg-info', 'Выполняется'],
  completed: ['bg-success', 'Выполнено'],
  failed: ['bg-danger', 'Ошибка'],
  partial_success: ['bg-warning', 'Частично'],
};
const DOC_STATUS = {
  draft: ['bg-secondary', 'Черновик'],
  processed: ['bg-success', 'Обработан'],
  failed: ['bg-danger', 'Ошибка'],
};
// Терминальные статусы: поллер останавливается (partial_success ставит cron-финализатор).
const TERMINAL_JOB_STATUSES = ['completed', 'failed', 'partial_success'];

const withId = (template, id) => template.replace(ID_PLACEHOLDER, encodeURIComponent(id));
const badge = (map, status) => {
  const [css, label] = map[status] || ['bg-secondary', String(status ?? '')];
  return el('span', `badge ${css}`, [label]);
};
const messageRow = (colspan, text, css) => {
  const td = el('td', `text-center ${css}`, [text]);
  td.colSpan = colspan;
  return el('tr', '', [td]);
};
const busy = (button, label) => {
  const original = [...button.childNodes];
  button.disabled = true;
  button.replaceChildren(el('span', 'spinner-border spinner-border-sm me-1'), label);
  return () => {
    button.disabled = false;
    button.replaceChildren(...original);
  };
};

function jobProgress(job) {
  const stats = job.batchStats || {};
  if (!stats.hasBatches) return [job.chunksTotal > 0 ? `Чанки: ${job.chunksTotal}` : '—'];
  const parts = [el('span', 'badge bg-success', [`${stats.ok}/${stats.total} OK`])];
  if (stats.pending > 0) parts.push(el('span', 'badge bg-info ms-1', [`${stats.pending} в работе`]));
  if (stats.failed > 0) parts.push(el('span', 'badge bg-danger ms-1', [`${stats.failed} ошибок`]));
  return parts;
}

function jobActions(root, job, isCompanyOwner) {
  const actions = [];
  if (job.extractToken) {
    const extract = el('button', 'btn btn-sm btn-primary me-1 mb-1', ['Обработать']);
    extract.type = 'button';
    Object.assign(extract.dataset, {
      bsToggle: 'modal',
      bsTarget: '#ads-confirm',
      confirmAction: withId(root.dataset.extractUrl, job.id),
      confirmToken: job.extractToken,
      confirmTitle: 'Запустить обработку batch-файлов этого задания?',
      confirmSubmit: 'Обработать',
    });
    actions.push(extract);
  }
  if (isCompanyOwner && (job.status === 'pending' || job.status === 'running')) {
    const markFailed = el('button', 'btn btn-sm btn-outline-danger mb-1', ['Пометить FAILED']);
    markFailed.type = 'button';
    Object.assign(markFailed.dataset, { bsToggle: 'modal', bsTarget: '#modal-mark-ad-load-job-failed', jobId: job.id });
    actions.push(markFailed);
  }
  return actions.length ? actions : [el('span', 'text-muted', ['—'])];
}

async function loadJobs(root) {
  const table = root.querySelector('[data-ad-jobs]');
  const tbody = table?.querySelector('tbody');
  if (!tbody) return;
  const isCompanyOwner = table.dataset.isCompanyOwner === '1';
  try {
    const data = await apiFetch(root.dataset.jobsUrl);
    const items = Array.isArray(data.items) ? data.items : [];
    if (items.length === 0) {
      tbody.replaceChildren(messageRow(7, 'Нет данных', 'text-muted'));
      return;
    }
    tbody.replaceChildren(...items.map((job) => el('tr', '', [
      el('td', '', [el('small', '', [`${job.dateFrom ?? ''} — ${job.dateTo ?? ''}`])]),
      el('td', '', [badge(JOB_STATUS, job.status)]),
      el('td', '', [el('small', '', jobProgress(job))]),
      el('td', '', [el('small', '', [String(job.createdAt ?? '')])]),
      el('td', '', [el('small', '', [String(job.finishedAt || '—')])]),
      el('td', '', [el('small', 'text-danger', [String(job.lastError || '')])]),
      el('td', '', jobActions(root, job, isCompanyOwner)),
    ])));
  } catch {
    tbody.replaceChildren(messageRow(7, 'Ошибка загрузки', 'text-danger'));
  }
}

function docRow(root, doc) {
  const actions = [];
  if (doc.hasFile) {
    const open = el('a', 'btn btn-sm btn-outline-primary me-1', ['Открыть']);
    open.href = withId(root.dataset.downloadUrl, doc.id);
    actions.push(open);
  }
  const reprocess = el('button', 'btn btn-sm btn-outline-secondary', ['Переобработать']);
  reprocess.type = 'button';
  reprocess.dataset.reprocessDoc = doc.id;
  actions.push(reprocess);
  return el('tr', '', [
    el('td', '', [String(doc.reportDate ?? '')]),
    el('td', '', [badge(DOC_STATUS, doc.status)]),
    el('td', '', [el('small', '', [String(doc.loadedAt ?? '')])]),
    el('td', '', [el('small', 'text-danger', [String(doc.processingError || '')])]),
    el('td', '', actions),
  ]);
}

async function loadRawDocuments(root) {
  const tbody = root.querySelector('[data-ad-raw-docs] tbody');
  if (!tbody) return;
  tbody.replaceChildren(messageRow(5, 'Загрузка…', 'text-muted'));
  const dateFrom = root.querySelector('[data-raw-date-from]')?.value ?? '';
  const dateTo = root.querySelector('[data-raw-date-to]')?.value ?? '';
  const params = dateFrom && dateTo ? `?${new URLSearchParams({ dateFrom, dateTo })}` : '';
  try {
    const data = await apiFetch(`${root.dataset.rawDocsUrl}${params}`);
    const items = Array.isArray(data.items) ? data.items : [];
    tbody.replaceChildren(...(items.length ? items.map((doc) => docRow(root, doc)) : [messageRow(5, 'Нет данных', 'text-muted')]));
  } catch (error) {
    tbody.replaceChildren(messageRow(5, error.status ? error.message : 'Ошибка загрузки', 'text-danger'));
  }
}

function initRawDocuments(root) {
  root.querySelector('[data-raw-documents-filter]')?.addEventListener('submit', (event) => {
    event.preventDefault();
    loadRawDocuments(root);
  });
  // Делегирование: кнопки «Переобработать» появляются при каждой перерисовке таблицы.
  root.querySelector('[data-ad-raw-docs] tbody')?.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-reprocess-doc]') : null;
    if (!(button instanceof HTMLButtonElement) || !button.dataset.reprocessDoc) return;
    const restore = busy(button, 'Запуск…');
    try {
      await apiFetch(withId(root.dataset.reprocessUrl, button.dataset.reprocessDoc), { method: 'POST' });
      // Перечитываем список: статус DRAFT и пустое поле «Ошибка».
      loadRawDocuments(root);
    } catch (error) {
      window.alert(error.status ? error.message : 'Сетевая ошибка при постановке документа на переобработку.');
      restore();
    }
  });
}

function initLoadRange(root) {
  const modal = root.querySelector('[data-ozon-ad-load-range]');
  const button = modal?.querySelector('[data-range-submit]');
  if (!modal || !button) return;
  button.addEventListener('click', async () => {
    const dateFrom = modal.querySelector('[data-range-from]')?.value ?? '';
    const dateTo = modal.querySelector('[data-range-to]')?.value ?? '';
    if (!dateFrom || !dateTo) {
      window.alert('Укажите обе даты');
      return;
    }
    const restore = busy(button, 'Запуск...');
    try {
      await apiFetch(root.dataset.loadRangeUrl, { method: 'POST', body: { dateFrom, dateTo } });
      window.location.reload();
    } catch (error) {
      if (error.status) bootstrapModal()?.getOrCreateInstance(modal).hide();
      window.alert(error.status ? `Ошибка: ${error.message}` : `Ошибка сети: ${error.message}`);
      restore();
    }
  });
}

function initMarkFailed(root) {
  const modal = root.querySelector('[data-mark-failed-modal]');
  const button = modal?.querySelector('[data-mark-failed-submit]');
  const reasonEl = modal?.querySelector('[data-mark-failed-reason]');
  if (!modal || !button || !reasonEl) return;
  let jobId = null;
  modal.addEventListener('show.bs.modal', (event) => {
    jobId = event.relatedTarget instanceof HTMLElement ? event.relatedTarget.dataset.jobId ?? null : null;
    reasonEl.value = '';
  });
  button.addEventListener('click', async () => {
    if (!jobId) return;
    const reason = reasonEl.value.trim();
    if (!reason) {
      window.alert('Укажите причину');
      return;
    }
    const restore = busy(button, 'Выполняется...');
    try {
      await apiFetch(withId(root.dataset.markFailedUrl, jobId), { method: 'POST', body: { reason } });
      window.location.reload();
    } catch (error) {
      if (error.status) bootstrapModal()?.getOrCreateInstance(modal).hide();
      window.alert(error.status ? `Ошибка: ${error.message}` : `Ошибка сети: ${error.message}`);
      restore();
    }
  });
}

function initProgressPoller(root) {
  root.querySelectorAll('[data-ad-job-id][data-status-url]').forEach((widget) => {
    if (TERMINAL_JOB_STATUSES.includes(widget.dataset.adJobStatus)) return;
    const bar = widget.querySelector('[data-ad-progress-bar]');
    const counters = widget.querySelector('[data-ad-counters]');
    const interval = Number(widget.dataset.pollInterval) || 15000;
    const timer = setInterval(async () => {
      let data;
      try {
        data = await apiFetch(widget.dataset.statusUrl);
      } catch {
        return; // сеть — продолжаем опрос
      }
      if (bar) bar.style.width = `${Number(data.progress) || 0}%`;
      if (counters) {
        counters.textContent = `Чанков: ${data.completedChunks}/${data.chunksTotal}, Документов: ${data.totalDocs} (${data.processedDocs} обработано, ${data.failedDocs} ошибок)`;
      }
      if (TERMINAL_JOB_STATUSES.includes(data.status)) {
        clearInterval(timer);
        window.location.reload();
      }
    }, interval);
  });
}

export function initMarketplaceAds() {
  const root = document.querySelector('[data-marketplace-ads]');
  if (!(root instanceof HTMLElement)) return;
  loadJobs(root);
  loadRawDocuments(root);
  initRawDocuments(root);
  initLoadRange(root);
  initMarkFailed(root);
  initProgressPoller(root);
}
