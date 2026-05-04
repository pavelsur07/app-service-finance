/***** Yearly Summary (ДДС): помесячный свод за выбранный год *****/
/**
 * ВАЖНО:
 * — Этот файл не переопределяет существующие функции и не трогает действующий отчёт.
 * — Ожидается, что в проекте уже есть:
 *     CFG, findTokenForCompany_, buildUrl_, fetchJson_, clearDataArea_,
 *     highlightRowsByTitle_, highlightRowsByLevel_, pad2_.
 * — Новый лист для свода: "Свод (ДДС)".
 */

var SHEET_REPORT_SUMMARY_DDS = 'Свод (ДДС)';

/**
 * Пункт меню: "Обновить Свод ДДС"
 * Формирует помесячный отчёт (Янв–Дек) за год из API /api/public/reports/cashflow.json (group=month).
 * Настройки берём из того же листа Config: B1 (BASE_URL), B2 (Компания), B3 (Год).
 */
function renderCashflowYearlyReport() {
  var cfg = readConfigYear_(); // { baseUrl, company, year, from, to }
  var token = findTokenForCompany_(cfg.company);
  if (!token) {
    throw new Error('Нет токена для компании "' + cfg.company + '" (именованный диапазон TOKENS: Company | Token).');
  }

  // 1) Запрашиваем свод с группировкой по месяцам
  var url = buildUrl_(cfg.baseUrl, '/api/public/reports/cashflow.json', {
    token: token,
    from: cfg.from,          // YYYY-01-01
    to:   cfg.to,            // YYYY-12-31
    group: 'month'           // КЛЮЧЕВАЯ разница от месячного отчёта
  });
  var data = fetchJson_(url);

  // 2) Строим табличные строки (Янв..Дек, Итог, Level)
  var rows = buildMonthGrid_(data, CFG.CURRENCY, cfg.year);

  // 3) Рендерим в "Свод (ДДС)": шапка (1–3) + данные с 4-й строки
  paintYearSummarySheet_(rows, cfg);
}

/**
 * Читаем настройки из листа Config:
 *   B1 — BASE_URL
 *   B2 — Название компании
 *   B3 — Год (YYYY)
 */
function readConfigYear_() {
  var sh = SpreadsheetApp.getActive().getSheetByName(CFG.SHEET_CFG);
  if (!sh) throw new Error('Нет листа "' + CFG.SHEET_CFG + '"');

  var baseUrl = String(sh.getRange(CFG.BASE_URL_CELL).getValue()).trim();
  var company = String(sh.getRange(CFG.COMPANY_CELL).getValue()).trim();
  var yearStr = String(sh.getRange(CFG.YEAR_CELL).getValue()).trim();

  if (!baseUrl) throw new Error('Config!B1 (BASE_URL) пустой');
  if (!company) throw new Error('Config!B2 (Название компании) пустое');
  if (!yearStr) throw new Error('Config!B3 (Год) пустой');

  var year = parseInt(yearStr, 10);
  if (!year || year < 1900 || year > 3000) {
    throw new Error('Некорректный год в B3: ' + yearStr);
  }

  return {
    baseUrl: baseUrl,
    company: company,
    year: year,
    from: year + '-01-01',
    to:   year + '-12-31'
  };
}

/**
 * Строим «сетку» помесячно:
 * Заголовок: Категория | Янв..Дек | Итого | Level
 * Служебные массивы: data.openings[currency][i], data.closings[currency][i], node.totals[currency][i]
 */
function buildMonthGrid_(data, currency, year) {
  data = data || {};
  var tree = Array.isArray(data.tree) ? data.tree : [];

  var monthLabels = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
  var monthCount = 12;

  var header = ['Категория'].concat(monthLabels).concat(['Итого', 'Level']);
  var rows = [header];

  var openings = (data.openings && data.openings[currency] && Array.isArray(data.openings[currency]))
    ? data.openings[currency] : [];
  var closings = (data.closings && data.closings[currency] && Array.isArray(data.closings[currency]))
    ? data.closings[currency] : [];

  // Сальдо нач.
  var openRow = ['Сальдо нач.'];
  for (var i = 0; i < monthCount; i++) {
    openRow.push(typeof openings[i] === 'number' ? openings[i] : 0);
  }
  openRow.push(''); // Итог для строки сальдо — пустой
  openRow.push(''); // Level
  rows.push(openRow);

  // Категории (рекурсивно)
  function pushNode(node) {
    node = node || {};
    var lvl = clampLevel_(node.level);
    var nm  = node.name || node.id || '';
    var name = (lvl > 0 ? new Array(lvl + 1).join('  ') : '') + nm;

    var totals = (node.totals && node.totals[currency] && Array.isArray(node.totals[currency]))
      ? node.totals[currency] : [];

    var line = [name];
    var sum = 0;
    for (var i = 0; i < monthCount; i++) {
      var val = (typeof totals[i] === 'number') ? totals[i] : 0;
      line.push(val);
      sum += val;
    }
    line.push(sum);
    line.push(lvl);
    rows.push(line);

    var kids = Array.isArray(node.children) ? node.children : [];
    for (var k = 0; k < kids.length; k++) pushNode(kids[k]);
  }

  for (var r = 0; r < tree.length; r++) pushNode(tree[r]);

  // ИТОГО (нетто) = Сальдо конец - Сальдо начало (по каждому месяцу)
  var totalRow = ['ИТОГО (нетто)'];
  var grand = 0;
  for (var i = 0; i < monthCount; i++) {
    var op = (typeof openings[i] === 'number') ? openings[i] : 0;
    var cl = (typeof closings[i] === 'number') ? closings[i] : 0;
    var net = cl - op;
    totalRow.push(net);
    grand += net;
  }
  totalRow.push(grand);
  totalRow.push('');
  rows.push(totalRow);

  // Сальдо кон.
  var closeRow = ['Сальдо кон.'];
  for (var i = 0; i < monthCount; i++) {
    closeRow.push(typeof closings[i] === 'number' ? closings[i] : 0);
  }
  closeRow.push('');
  closeRow.push('');
  rows.push(closeRow);

  return rows;
}

/**
 * Рендер в лист "Свод (ДДС)":
 * — Строки 1–3: информативная шапка (как в месячном отчёте).
 * — Данные с 4-й строки. Форматирование аналогично месячному отчёту.
 */
function paintYearSummarySheet_(rows, cfg) {
  var ss = SpreadsheetApp.getActive();
  var sh = ss.getSheetByName(SHEET_REPORT_SUMMARY_DDS) || ss.insertSheet(SHEET_REPORT_SUMMARY_DDS);

  // 1) Шапка (1–3 строки)
  drawYearHeader_(sh, cfg);

  // 2) Очищаем ТОЛЬКО область данных (с 4-й строки)
  clearDataArea_(sh);

  // 3) Вставляем данные
  var startRow = CFG.DATA_START_ROW;
  var startCol = CFG.DATA_START_COL;
  var cols = rows[0].length;
  var visibleCols = cols - 1; // последняя — служебный Level (скрываем)
  sh.getRange(startRow, startCol, rows.length, cols).setValues(rows);

  // 4) Оформление шапки таблицы
  sh.getRange(startRow, startCol, 1, visibleCols)
    .setFontWeight('bold')
    .setBackground(CFG.COLOR_HEADER_BG)
    .setNumberFormat('@'); // названия месяцев — текст

  // 5) Формат чисел (все месяцы + Итог)
  var periodsCount = visibleCols - 2; // Категория + Итог
  if (periodsCount > 0) {
    var numericRange = sh.getRange(startRow + 1, startCol + 1, rows.length - 1, periodsCount + 1);
    numericRange.setNumberFormat('#,##0.00').setHorizontalAlignment('right');

    // Удалим старые правила, заданные на эту область, и добавим правило для отрицательных
    var rules = sh.getConditionalFormatRules() || [];
    rules = rules.filter(function(rule) {
      var ranges = rule.getRanges() || [];
      return !ranges.some(function(r) { return r.getRow() === startRow; });
    });
    var negRule = SpreadsheetApp.newConditionalFormatRule()
      .whenNumberLessThan(0)
      .setBackground('#FFEFEF')
      .setFontColor('#B00020')
      .setRanges([numericRange])
      .build();
    rules.push(negRule);
    sh.setConditionalFormatRules(rules);
  }

  // 6) Выровнять «Категория» влево
  sh.getRange(startRow, startCol, rows.length, 1).setHorizontalAlignment('left');

  // 7) Зебра по области данных
  try {
    sh.getRange(startRow, startCol, rows.length, visibleCols)
      .applyRowBanding(SpreadsheetApp.BandingTheme.LIGHT_GREY);
  } catch (e) {}

  // 8) Скрыть служебную колонку Level
  try { sh.hideColumns(visibleCols + 1); } catch(e) {}

  // 9) Автоширина видимых колонок
  for (var c = 1; c <= visibleCols; c++) {
    try { sh.autoResizeColumn(c); } catch(e) {}
  }

  // 10) Подсветка «Сальдо нач.» и «Сальдо кон.» + Level=1
  highlightRowsByTitle_(sh, startRow, rows, ['Сальдо нач.', 'Сальдо кон.'], CFG.COLOR_SALDO_BG, true);
  highlightRowsByLevel_(sh, startRow, rows, 1, CFG.COLOR_LEVEL1_BG, true);
}

/**
 * Шапка (строки 1–3) для листа "Свод (ДДС)" — как в месячном отчёте.
 * 1: Заголовок
 * 2: Компания | Период | Группировка | Валюта
 * 3: Источник | Обновлено
 */
function drawYearHeader_(sh, cfg) {
  var title = 'Свод ДДС за год — ' + cfg.company;
  var period = formatIso_(cfg.from) + ' — ' + formatIso_(cfg.to);
  var grouping = 'По месяцам';
  var currency = CFG.CURRENCY || 'RUB';
  var source = cfg.baseUrl;
  var tz = Session.getScriptTimeZone() || 'UTC';
  var updated = Utilities.formatDate(new Date(), tz, 'yyyy-MM-dd HH:mm:ss');

  // Гарантируем минимум 16 колонок (для аккуратного layout шапки)
  if (sh.getMaxColumns() < 16) {
    sh.insertColumnsAfter(sh.getMaxColumns(), 16 - sh.getMaxColumns());
  }

  // Очистим содержимое строк 1–3
  sh.getRange(1, 1, 3, 16).clear({contentsOnly: true});

  // Строка 1 — Заголовок
  sh.getRange(1, 1).setValue(title)
    .setFontWeight('bold')
    .setFontSize(12)
    .setHorizontalAlignment('left');
  // merge A1:H1 (idempotent)
  try { sh.getRange('A1:H1').merge(); } catch(e) {}

  // Строка 2 — Компания | Период | Группировка | Валюта
  var row2 = [
    'Компания:', cfg.company,
    '', 'Период:', period,
    '', 'Группировка:', grouping,
    '', 'Валюта:', currency
  ];
  sh.getRange(2, 1, 1, row2.length).setValues([row2]).setBackground(CFG.COLOR_HEADER_BG);

  // Строка 3 — Источник | Обновлено
  var row3 = [
    'Источник:', source,
    '', 'Обновлено:', updated
  ];
  sh.getRange(3, 1, 1, row3.length).setValues([row3]).setBackground(CFG.COLOR_HEADER_BG);

  // Небольшие акценты (полужирный для подписей)
  sh.getRange(2, 1).setFontWeight('bold'); // "Компания:"
  sh.getRange(2, 4).setFontWeight('bold'); // "Период:"
  sh.getRange(2, 8).setFontWeight('bold'); // "Группировка:"
  sh.getRange(2, 10).setFontWeight('bold'); // "Валюта:"
  sh.getRange(3, 1).setFontWeight('bold'); // "Источник:"
  sh.getRange(3, 4).setFontWeight('bold'); // "Обновлено:"

  // Высота строк шапки
  try {
    sh.setRowHeights(1, 1, 24);
    sh.setRowHeights(2, 2, 20);
  } catch(e) {}
}

/** Утилита: формат ISO yyyy-mm-dd → dd.mm.yyyy */
function formatIso_(iso) {
  // ожидаем YYYY-MM-DD
  if (!iso || typeof iso !== 'string' || iso.length < 10) return String(iso || '');
  return iso.slice(8,10) + '.' + iso.slice(5,7) + '.' + iso.slice(0,4);
}
