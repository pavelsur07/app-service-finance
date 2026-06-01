/***** Yearly Summary (ОПиУ / P&L): помесячный свод за выбранный год *****/
/**
 * ВАЖНО:
 * — НЕ трогает действующий отчёт и другие функции.
 * — Ожидается, что в проекте уже есть:
 *     CFG, findTokenForCompany_, buildUrl_, fetchJson_, clearDataArea_,
 *     highlightRowsByLevel_, pad2_.
 * — Новый лист: "Свод (ОПиУ)".
 * — Источник: /api/public/reports/pl.json (grouping=month) — помесячно за год.
 */

var SHEET_REPORT_SUMMARY_PL = 'Свод (ОПиУ)';
var SHEET_REPORT_ANALYSIS_PL = 'ОПиУ-АНАЛИЗ';
var SHEET_REPORT_PLAN_FACT_PL = 'ОПиУ План-Факт';

// Формат Google Sheets, соответствующий формату меню "Формат → Число → Финансы":
// положительные: 1 234.00, отрицательные: (1 234.00), ноль: -
var PL_FINANCE_NUMBER_FORMAT = '#,##0.00;(#,##0.00);-';
var PL_PERCENT_NUMBER_FORMAT = '0.0%';
var PL_LEVEL1_BG = '#d0dcf0';
var PL_APP_NAME = 'Ваш Финдир - ОПиУ';
var PL_MONTH_COLUMN_WIDTH = 95;
var PL_TEXT_FONT_COLOR = '#1e2f5a';
var PL_TABLE_HEADER_BG = '#1e2f5a';
var PL_TABLE_HEADER_FONT_COLOR = '#ffffff';
var PL_TABLE_HEADER_HEIGHT = 25;

/**
 * Пункт меню: "Обновить Свод (ОПиУ)"
 */
function renderPlYearlyReport() {
  var cfg = readConfigYear_(); // { baseUrl, company, year, from, to }
  var token = findTokenForCompany_(cfg.company);
  if (!token) {
    throw new Error('Нет токена для компании "' + cfg.company + '" (именованный диапазон TOKENS: Company | Token).');
  }

  // 1) Запрашиваем P&L с группировкой по месяцам
  var url = buildUrl_(cfg.baseUrl, '/api/public/reports/pl.json', {
    token: token,
    from: cfg.from,
    to:   cfg.to,
    grouping: 'month'
  });

  logPlRequest_(url, cfg);

  var data = fetchJson_(url);

  // 2) ЛОГИРОВАНИЕ КОНТРАКТА (важно для диагностики)
  logPlResponseShape_(data);

  // 3) Строим табличные строки (Янв..Дек, Итог, Level)
  var rows = buildPlMonthGrid_(data, cfg.year);

  // 4) Рендерим в "Свод (ОПиУ)": шапка (1–3) + данные с 4-й строки
  paintYearSummaryPlSheet_(rows, cfg, data);

  // 5) Рендерим новый лист "ОПиУ-АНАЛИЗ": Common-size P&L / % от выручки
  var analysisRows = buildPlCommonSizeGrid_(rows);
  paintYearAnalysisPlSheet_(analysisRows, cfg, data);

  // 6) Рендерим новый лист "ОПиУ План-Факт"
  paintYearPlanFactPlSheet_(rows, cfg, data);

  Logger.log('[P&L] Done. Rendered rows=%s analysisRows=%s', rows.length, analysisRows.length);
}

/**
 * Читаем настройки из листа Config:
 *   CFG.SHEET_CFG — имя листа конфигурации
 *   CFG.BASE_URL_CELL — ячейка BASE_URL (например B1)
 *   CFG.COMPANY_CELL — ячейка Company (например B2)
 *   CFG.YEAR_CELL — ячейка Year (например B3)
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
 * ✅ ИСПРАВЛЕННАЯ СБОРКА МЕСЯЦЕВ:
 * В P&L API period.label = "YYYY-MM" (для grouping=month),
 * а rawValues ключуются по period.id = "YYYYMMDD_YYYYMMDD".
 * Поэтому mapping строим: label("YYYY-MM") -> id.
 */
function buildPlMonthGrid_(data, year) {
  data = data || {};
  var periods = Array.isArray(data.periods) ? data.periods : [];
  var rowsIn = Array.isArray(data.rows) ? data.rows : [];
  var raw = data.rawValues || {};

  var monthLabels = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
  var monthCount = 12;

  // mapping "YYYY-MM" -> period.id
  var periodIdByYm = {};
  for (var i = 0; i < periods.length; i++) {
    var pid = String(periods[i].id || '');
    var lbl = String(periods[i].label || '').trim();
    var from = String(periods[i].from || '');

    if (/^\d{4}-\d{2}$/.test(lbl)) {
      periodIdByYm[lbl] = pid;
      continue;
    }

    if (/^\d{4}-\d{2}-\d{2}$/.test(from)) {
      periodIdByYm[from.slice(0, 7)] = pid;
    }
  }

  // порядок месяцев года -> period.id
  var monthPeriodIds = [];
  for (var m = 1; m <= 12; m++) {
    var ymKey = year + '-' + pad2_(m);
    monthPeriodIds.push(periodIdByYm[ymKey] || null);
  }

  // Заголовок
  var header = ['Строка'].concat(monthLabels).concat(['Итого', 'Level']);
  var out = [header];

  // Строки P&L
  for (var r = 0; r < rowsIn.length; r++) {
    var row = rowsIn[r] || {};
    var rowId = String(row.id || '');
    var lvl = clampLevel_(row.level);
    var nm  = row.name || rowId || '';
    var name = (lvl > 0 ? new Array(lvl + 1).join('  ') : '') + nm;

    var line = [name];
    var sum = 0;

    for (var mi = 0; mi < monthCount; mi++) {
      var pid2 = monthPeriodIds[mi];
      var v = 0;

      if (pid2 && raw[rowId] && typeof raw[rowId][pid2] === 'number') {
        v = raw[rowId][pid2];
      }
      line.push(v);
      sum += v;
    }

    line.push(sum);
    line.push(lvl);
    out.push(line);
  }

  return out;
}

/**
 * Common-size P&L:
 * каждая денежная строка считается как % от строки "Выручка"
 * по каждому месяцу и по колонке "Итого".
 *
 * Строки, которые уже содержат "%" в названии, не делим повторно,
 * чтобы не получить "процент от процента".
 */
function buildPlCommonSizeGrid_(sourceRows) {
  if (!sourceRows || sourceRows.length <= 1) return sourceRows || [];

  var header = sourceRows[0].slice();
  var out = [header];

  var revenueRow = findPlRevenueRow_(sourceRows);
  if (!revenueRow) {
    throw new Error('Не найдена строка "Выручка" для формирования листа "' + SHEET_REPORT_ANALYSIS_PL + '"');
  }

  var levelIndex = header.length - 1;
  var firstNumericIndex = 1; // Янв
  var lastNumericIndex = levelIndex - 1; // Итого

  for (var r = 1; r < sourceRows.length; r++) {
    var src = sourceRows[r];
    var dst = [];

    var rowName = String(src[0] || '');
    var isPercentRow = rowName.indexOf('%') !== -1;

    dst[0] = src[0];

    for (var c = firstNumericIndex; c <= lastNumericIndex; c++) {
      var value = toPlNumber_(src[c]);

      if (isPercentRow) {
        dst[c] = value;
      } else {
        var revenue = toPlNumber_(revenueRow[c]);
        dst[c] = revenue !== 0 ? value / revenue : 0;
      }
    }

    dst[levelIndex] = src[levelIndex];
    out.push(dst);
  }

  return out;
}

/**
 * Ищем строку "Выручка" без учета пробелов вложенности.
 */
function findPlRevenueRow_(rows) {
  for (var i = 1; i < rows.length; i++) {
    var name = normalizePlRowName_(rows[i][0]);

    if (name === 'Выручка') {
      return rows[i];
    }
  }

  return null;
}

/**
 * Нормализует название строки:
 * убирает лишние пробелы, но не меняет исходные данные.
 */
function normalizePlRowName_(name) {
  return String(name || '')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * Безопасное приведение к числу.
 */
function toPlNumber_(value) {
  if (typeof value === 'number') return value;

  var n = parseFloat(String(value || '0').replace(',', '.'));
  return isNaN(n) ? 0 : n;
}

/**
 * Рендер в лист "Свод (ОПиУ)":
 * — Строки 1–3: шапка
 * — Данные с 4-й строки
 */
function paintYearSummaryPlSheet_(rows, cfg, data) {
  var ss = SpreadsheetApp.getActive();
  var sh = ss.getSheetByName(SHEET_REPORT_SUMMARY_PL) || ss.insertSheet(SHEET_REPORT_SUMMARY_PL);

  // 1) Шапка (1–3)
  drawYearHeaderPl_(sh, cfg, data);

  // 2) Очищаем область данных
  clearDataArea_(sh);

  // 3) Вставляем данные
  var startRow = CFG.DATA_START_ROW;
  var startCol = CFG.DATA_START_COL;

  var cols = rows[0].length;
  var visibleCols = cols - 1; // Level скрываем
  var levelCol = startCol + visibleCols;

  sh.getRange(startRow, startCol, rows.length, cols).setValues(rows);

  // 4) Шапка таблицы
  sh.getRange(startRow, startCol, 1, visibleCols)
    .setFontWeight('bold')
    .setBackground(PL_TABLE_HEADER_BG)
    .setFontColor(PL_TABLE_HEADER_FONT_COLOR)
    .setVerticalAlignment('middle')
    .setNumberFormat('@');

  try {
    sh.setRowHeight(startRow, PL_TABLE_HEADER_HEIGHT);
  } catch(e) {}

  // 5) Формат "Финансы" для всех числовых данных ОПиУ:
  // Янв..Дек + Итого
  var periodsCount = visibleCols - 2; // 12 месяцев: без "Строка" и без "Итого"
  if (rows.length > 1 && periodsCount > 0) {
    var numericRange = sh.getRange(
      startRow + 1,
      startCol + 1,
      rows.length - 1,
      periodsCount + 1
    );

    // Убираем старые условные правила, которые могли красить отрицательные значения
    removePlConditionalFormattingForRange_(sh, numericRange);

    numericRange
      .setNumberFormat(PL_FINANCE_NUMBER_FORMAT)
      .setHorizontalAlignment('right');

    // Строки, где в названии статьи есть "%", форматируем как проценты с 1 знаком
    formatPlPercentRows_(sh, startRow, startCol, rows, periodsCount + 1);
  }

  // 6) Выровнять колонку “Строка”
  sh.getRange(startRow, startCol, rows.length, 1)
    .setHorizontalAlignment('left');

  // 7) Зебра
  try {
    sh.getRange(startRow, startCol, rows.length, visibleCols)
      .applyRowBanding(SpreadsheetApp.BandingTheme.LIGHT_GREY);
  } catch (e) {}

  // 8) Формат строк по Level:
  // level=1 — жирный,
  // level>1 — обычный, не жирный.
  formatPlRowsByLevel_(sh, startRow, startCol, rows, visibleCols);

  // 9) Цвет шрифта всей таблицы
  sh.getRange(startRow, startCol, rows.length, visibleCols)
    .setFontColor(PL_TEXT_FONT_COLOR);

  // 10) Повторно применяем стиль шапки таблицы после общего цвета текста
  sh.getRange(startRow, startCol, 1, visibleCols)
    .setFontWeight('bold')
    .setBackground(PL_TABLE_HEADER_BG)
    .setFontColor(PL_TABLE_HEADER_FONT_COLOR)
    .setVerticalAlignment('middle')
    .setNumberFormat('@');

  try {
    sh.setRowHeight(startRow, PL_TABLE_HEADER_HEIGHT);
  } catch(e) {}

  // 11) Скрыть колонку Level
  try {
    sh.hideColumns(levelCol);
  } catch(e) {}

  // 12) Автоширина
  for (var c = 0; c < visibleCols; c++) {
    try {
      sh.autoResizeColumn(startCol + c);
    } catch(e) {}
  }

  // 13) Фиксированная ширина столбцов месяцев Янв..Дек
  for (var mc = 0; mc < 12; mc++) {
    try {
      sh.setColumnWidth(startCol + 1 + mc, PL_MONTH_COLUMN_WIDTH);
    } catch(e) {}
  }
}

/**
 * Рендер листа "ОПиУ-АНАЛИЗ":
 * Common-size P&L / вертикальный анализ ОПиУ.
 */
function paintYearAnalysisPlSheet_(rows, cfg, data) {
  var ss = SpreadsheetApp.getActive();
  var sh = ss.getSheetByName(SHEET_REPORT_ANALYSIS_PL) || ss.insertSheet(SHEET_REPORT_ANALYSIS_PL);

  // 1) Шапка (1–3), стиль такой же как у основного листа
  drawYearHeaderPl_(sh, cfg, data);

  // 2) Очищаем область данных
  clearDataArea_(sh);

  // 3) Вставляем данные
  var startRow = CFG.DATA_START_ROW;
  var startCol = CFG.DATA_START_COL;

  var cols = rows[0].length;
  var visibleCols = cols - 1; // Level скрываем
  var levelCol = startCol + visibleCols;

  sh.getRange(startRow, startCol, rows.length, cols).setValues(rows);

  // 4) Шапка таблицы — стиль такой же как у основного листа
  sh.getRange(startRow, startCol, 1, visibleCols)
    .setFontWeight('bold')
    .setBackground(PL_TABLE_HEADER_BG)
    .setFontColor(PL_TABLE_HEADER_FONT_COLOR)
    .setVerticalAlignment('middle')
    .setNumberFormat('@');

  try {
    sh.setRowHeight(startRow, PL_TABLE_HEADER_HEIGHT);
  } catch(e) {}

  // 5) Все числовые данные — проценты с 1 знаком после запятой
  var periodsCount = visibleCols - 2; // 12 месяцев: без "Строка" и без "Итого"
  if (rows.length > 1 && periodsCount > 0) {
    var numericRange = sh.getRange(
      startRow + 1,
      startCol + 1,
      rows.length - 1,
      periodsCount + 1
    );

    removePlConditionalFormattingForRange_(sh, numericRange);

    numericRange
      .setNumberFormat(PL_PERCENT_NUMBER_FORMAT)
      .setHorizontalAlignment('right');
  }

  // 6) Выровнять колонку “Строка”
  sh.getRange(startRow, startCol, rows.length, 1)
    .setHorizontalAlignment('left');

  // 7) Зебра
  try {
    sh.getRange(startRow, startCol, rows.length, visibleCols)
      .applyRowBanding(SpreadsheetApp.BandingTheme.LIGHT_GREY);
  } catch (e) {}

  // 8) Формат строк по Level
  formatPlRowsByLevel_(sh, startRow, startCol, rows, visibleCols);

  // 9) Цвет шрифта всей таблицы
  sh.getRange(startRow, startCol, rows.length, visibleCols)
    .setFontColor(PL_TEXT_FONT_COLOR);

  // 10) Повторно применяем стиль шапки таблицы после общего цвета текста
  sh.getRange(startRow, startCol, 1, visibleCols)
    .setFontWeight('bold')
    .setBackground(PL_TABLE_HEADER_BG)
    .setFontColor(PL_TABLE_HEADER_FONT_COLOR)
    .setVerticalAlignment('middle')
    .setNumberFormat('@');

  try {
    sh.setRowHeight(startRow, PL_TABLE_HEADER_HEIGHT);
  } catch(e) {}

  // 11) Скрыть колонку Level
  try {
    sh.hideColumns(levelCol);
  } catch(e) {}

  // 12) Автоширина
  for (var c = 0; c < visibleCols; c++) {
    try {
      sh.autoResizeColumn(startCol + c);
    } catch(e) {}
  }

  // 13) Фиксированная ширина столбцов месяцев Янв..Дек
  for (var mc = 0; mc < 12; mc++) {
    try {
      sh.setColumnWidth(startCol + 1 + mc, PL_MONTH_COLUMN_WIDTH);
    } catch(e) {}
  }
}

/**
 * Рендер листа "ОПиУ План-Факт":
 * по каждому месяцу:
 * План | Факт | Отклонение р. | Откл. %
 *
 * Плановые ячейки пользователь заполняет вручную.
 * При обновлении отчета план сохраняется, факт обновляется автоматически,
 * отклонения пересчитываются формулами.
 */
function paintYearPlanFactPlSheet_(sourceRows, cfg, data) {
  var ss = SpreadsheetApp.getActive();
  var sh = ss.getSheetByName(SHEET_REPORT_PLAN_FACT_PL) || ss.insertSheet(SHEET_REPORT_PLAN_FACT_PL);

  var startRow = CFG.DATA_START_ROW;
  var startCol = CFG.DATA_START_COL;

  // Сохраняем уже введенные планы до очистки листа
  var existingPlans = readPlPlanFactPlans_(sh, startRow, startCol);

  var rows = buildPlPlanFactGrid_(sourceRows, existingPlans);

  // 1) Шапка отчета — стиль как у ОПиУ
  drawYearHeaderPl_(sh, cfg, data);

  // 2) Очищаем область данных
  clearDataArea_(sh);

  // 3) Гарантируем достаточное количество колонок
  ensurePlSheetColumns_(sh, startCol + rows[0].length - 1);

  // 4) Вставляем данные
  var cols = rows[0].length;
  var visibleCols = cols - 1; // Level скрываем
  var levelCol = startCol + visibleCols;

  sh.getRange(startRow, startCol, rows.length, cols).setValues(rows);

  // 5) Формулы отклонений
  applyPlPlanFactFormulas_(sh, startRow, startCol, rows.length);

  // 6) Шапка таблицы — стиль как у ОПиУ
  sh.getRange(startRow, startCol, 1, visibleCols)
    .setFontWeight('bold')
    .setBackground(PL_TABLE_HEADER_BG)
    .setFontColor(PL_TABLE_HEADER_FONT_COLOR)
    .setVerticalAlignment('middle')
    .setNumberFormat('@');

  try {
    sh.setRowHeight(startRow, PL_TABLE_HEADER_HEIGHT);
  } catch(e) {}

  // 7) Форматы чисел
  formatPlPlanFactNumbers_(sh, startRow, startCol, rows, visibleCols);

  // 8) Выровнять колонку “Строка”
  sh.getRange(startRow, startCol, rows.length, 1)
    .setHorizontalAlignment('left');

  // 9) Зебра
  try {
    sh.getRange(startRow, startCol, rows.length, visibleCols)
      .applyRowBanding(SpreadsheetApp.BandingTheme.LIGHT_GREY);
  } catch(e) {}

  // 10) Формат строк по Level
  formatPlRowsByLevel_(sh, startRow, startCol, rows, visibleCols);

  // 11) Цвет шрифта всей таблицы
  sh.getRange(startRow, startCol, rows.length, visibleCols)
    .setFontColor(PL_TEXT_FONT_COLOR);

  // 12) Повторно применяем стиль шапки после общего цвета текста
  sh.getRange(startRow, startCol, 1, visibleCols)
    .setFontWeight('bold')
    .setBackground(PL_TABLE_HEADER_BG)
    .setFontColor(PL_TABLE_HEADER_FONT_COLOR)
    .setVerticalAlignment('middle')
    .setNumberFormat('@');

  try {
    sh.setRowHeight(startRow, PL_TABLE_HEADER_HEIGHT);
  } catch(e) {}

  // 13) Скрыть Level
  try {
    sh.hideColumns(levelCol);
  } catch(e) {}

  // 14) Автоширина первой колонки
  try {
    sh.autoResizeColumn(startCol);
  } catch(e) {}

  // 15) Ширина всех колонок План/Факт/Отклонения
  for (var c = startCol + 1; c < levelCol; c++) {
    try {
      sh.setColumnWidth(c, PL_MONTH_COLUMN_WIDTH);
    } catch(e) {}
  }
}

/**
 * Строит сетку План-Факт:
 * Строка |
 * Янв План | Янв Факт | Янв Отклонение р. | Янв Откл. % |
 * ...
 * Дек План | Дек Факт | Дек Отклонение р. | Дек Откл. % |
 * Итого План | Итого Факт | Итого Отклонение р. | Итого Откл. % |
 * Level
 */
function buildPlPlanFactGrid_(sourceRows, existingPlans) {
  if (!sourceRows || sourceRows.length <= 1) return sourceRows || [];

  var monthLabels = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
  var periods = monthLabels.concat(['Итого']);

  var header = ['Строка'];
  for (var p = 0; p < periods.length; p++) {
    header.push(periods[p] + ' План');
    header.push(periods[p] + ' Факт');
    header.push(periods[p] + ' Отклонение р.');
    header.push(periods[p] + ' Откл. %');
  }
  header.push('Level');

  var out = [header];

  var levelIndex = sourceRows[0].length - 1;
  var usedPlans = {};

  for (var r = 1; r < sourceRows.length; r++) {
    var src = sourceRows[r];
    var rowName = src[0];
    var level = src[levelIndex];

    var key = makePlPlanFactKey_(rowName, level);
    var planValues = takePlPlanValuesByKey_(existingPlans, usedPlans, key);

    var line = [rowName];

    // Янв..Дек
    for (var m = 0; m < 12; m++) {
      var plan = planValues && typeof planValues[m] !== 'undefined' ? planValues[m] : '';
      var fact = src[m + 1];

      line.push(plan);
      line.push(fact);
      line.push('');
      line.push('');
    }

    // Итого
    var totalFact = src[13];

    line.push('');
    line.push(totalFact);
    line.push('');
    line.push('');

    line.push(level);

    out.push(line);
  }

  return out;
}

/**
 * Читает уже введенные пользователем планы из листа "ОПиУ План-Факт".
 * Сохраняет только месячные планы Янв..Дек.
 */
function readPlPlanFactPlans_(sh, startRow, startCol) {
  var result = {};

  if (!sh || sh.getLastRow() <= startRow) {
    return result;
  }

  var planFactCols = 1 + 13 * 4 + 1; // Строка + 13 периодов*4 + Level
  var lastRequiredCol = startCol + planFactCols - 1;

  if (sh.getMaxColumns() < lastRequiredCol) {
    return result;
  }

  var lastRow = sh.getLastRow();
  var numRows = lastRow - startRow;

  if (numRows <= 0) {
    return result;
  }

  var range = sh.getRange(startRow + 1, startCol, numRows, planFactCols);
  var values = range.getValues();
  var formulas = range.getFormulas();

  var levelOffset = 1 + 13 * 4; // zero-based index Level внутри строки

  for (var r = 0; r < values.length; r++) {
    var rowName = values[r][0];
    var level = values[r][levelOffset];

    if (!rowName) continue;

    var key = makePlPlanFactKey_(rowName, level);
    var plans = [];

    for (var m = 0; m < 12; m++) {
      var planOffset = 1 + m * 4;
      plans.push(formulas[r][planOffset] || values[r][planOffset] || '');
    }

    if (!result[key]) {
      result[key] = [];
    }

    result[key].push(plans);
  }

  return result;
}

/**
 * Забирает очередной набор планов по ключу строки.
 * Нужно на случай одинаковых названий строк в разных разделах.
 */
function takePlPlanValuesByKey_(existingPlans, usedPlans, key) {
  if (!existingPlans || !existingPlans[key] || existingPlans[key].length === 0) {
    return null;
  }

  var used = usedPlans[key] || 0;
  usedPlans[key] = used + 1;

  return existingPlans[key][used] || null;
}

/**
 * Ключ строки для сохранения планов.
 */
function makePlPlanFactKey_(rowName, level) {
  return normalizePlRowName_(rowName) + '||' + String(level || '');
}

/**
 * Проставляет формулы:
 * Отклонение р. = Факт - План
 * Откл. % = Факт / План - 1
 * Итого План = сумма планов Янв..Дек
 */
function applyPlPlanFactFormulas_(sh, startRow, startCol, rowsCount) {
  if (rowsCount <= 1) return;

  var dataRowsCount = rowsCount - 1;
  var firstDataRow = startRow + 1;

  // 12 месяцев + Итого
  for (var p = 0; p < 13; p++) {
    var planCol = startCol + 1 + p * 4;
    var devRubCol = planCol + 2;
    var devPctCol = planCol + 3;

    if (p < 12) {
      sh.getRange(firstDataRow, devRubCol, dataRowsCount, 1)
        .setFormulaR1C1('=RC[-1]-RC[-2]');
    } else {
      sh.getRange(firstDataRow, planCol, dataRowsCount, 1)
        .setFormulaR1C1('=SUM(RC[-48],RC[-44],RC[-40],RC[-36],RC[-32],RC[-28],RC[-24],RC[-20],RC[-16],RC[-12],RC[-8],RC[-4])');

      sh.getRange(firstDataRow, devRubCol, dataRowsCount, 1)
        .setFormulaR1C1('=RC[-1]-RC[-2]');
    }

    sh.getRange(firstDataRow, devPctCol, dataRowsCount, 1)
      .setFormulaR1C1('=IF(RC[-3]<>0,RC[-2]/RC[-3]-1,"")');
  }
}

/**
 * Форматирует числовые колонки План-Факт.
 */
function formatPlPlanFactNumbers_(sh, startRow, startCol, rows, visibleCols) {
  if (!rows || rows.length <= 1) return;

  var dataRowsCount = rows.length - 1;
  var firstDataRow = startRow + 1;

  var numericRange = sh.getRange(firstDataRow, startCol + 1, dataRowsCount, visibleCols - 1);
  removePlConditionalFormattingForRange_(sh, numericRange);

  // План, Факт, Отклонение р. — финансовый формат
  // Откл. % — процентный формат
  for (var p = 0; p < 13; p++) {
    var planCol = startCol + 1 + p * 4;
    var factCol = planCol + 1;
    var devRubCol = planCol + 2;
    var devPctCol = planCol + 3;

    sh.getRange(firstDataRow, planCol, dataRowsCount, 1)
      .setNumberFormat(PL_FINANCE_NUMBER_FORMAT)
      .setHorizontalAlignment('right');

    sh.getRange(firstDataRow, factCol, dataRowsCount, 1)
      .setNumberFormat(PL_FINANCE_NUMBER_FORMAT)
      .setHorizontalAlignment('right');

    sh.getRange(firstDataRow, devRubCol, dataRowsCount, 1)
      .setNumberFormat(PL_FINANCE_NUMBER_FORMAT)
      .setHorizontalAlignment('right');

    sh.getRange(firstDataRow, devPctCol, dataRowsCount, 1)
      .setNumberFormat(PL_PERCENT_NUMBER_FORMAT)
      .setHorizontalAlignment('right');
  }

  // Строки, где в названии есть "%", форматируем как проценты
  formatPlPlanFactPercentRows_(sh, startRow, startCol, rows);
}

/**
 * Если строка сама является процентным показателем,
 * План / Факт / Отклонение р. тоже показываем как проценты.
 */
function formatPlPlanFactPercentRows_(sh, startRow, startCol, rows) {
  if (!rows || rows.length <= 1) return;

  for (var i = 1; i < rows.length; i++) {
    var rowName = String(rows[i][0] || '');

    if (rowName.indexOf('%') === -1) continue;

    var rowNumber = startRow + i;

    for (var p = 0; p < 13; p++) {
      var planCol = startCol + 1 + p * 4;
      var factCol = planCol + 1;
      var devRubCol = planCol + 2;
      var devPctCol = planCol + 3;

      sh.getRange(rowNumber, planCol, 1, 1).setNumberFormat(PL_PERCENT_NUMBER_FORMAT);
      sh.getRange(rowNumber, factCol, 1, 1).setNumberFormat(PL_PERCENT_NUMBER_FORMAT);
      sh.getRange(rowNumber, devRubCol, 1, 1).setNumberFormat(PL_PERCENT_NUMBER_FORMAT);
      sh.getRange(rowNumber, devPctCol, 1, 1).setNumberFormat(PL_PERCENT_NUMBER_FORMAT);
    }
  }
}

/**
 * Добавляет колонки, если для широкого отчета План-Факт их не хватает.
 */
function ensurePlSheetColumns_(sh, minColumns) {
  var current = sh.getMaxColumns();

  if (current < minColumns) {
    sh.insertColumnsAfter(current, minColumns - current);
  }
}

/**
 * Форматирует строки таблицы строго по уровню:
 * — строка заголовка таблицы остаётся жирной;
 * — data rows сначала сбрасываются в обычный шрифт;
 * — только строки с level=1 становятся жирными.
 */
function formatPlRowsByLevel_(sh, startRow, startCol, rows, visibleCols) {
  if (!rows || rows.length <= 1) return;

  var dataRowsCount = rows.length - 1;

  // Сначала сбрасываем жирность со всех строк данных,
  // чтобы после предыдущих запусков level>1 не оставался жирным.
  sh.getRange(startRow + 1, startCol, dataRowsCount, visibleCols)
    .setFontWeight('normal');

  for (var i = 1; i < rows.length; i++) {
    var level = parseInt(rows[i][rows[i].length - 1] || '0', 10);
    var rowNumber = startRow + i;

    if (level === 1) {
      sh.getRange(rowNumber, startCol, 1, visibleCols)
        .setFontWeight('bold')
        .setBackground(PL_LEVEL1_BG);
    }
  }
}

/**
 * Строки, где в названии статьи есть "%",
 * форматируем как проценты с 1 знаком после запятой.
 */
function formatPlPercentRows_(sh, startRow, startCol, rows, numericColsCount) {
  if (!rows || rows.length <= 1) return;

  for (var i = 1; i < rows.length; i++) {
    var rowName = String(rows[i][0] || '');

    if (rowName.indexOf('%') !== -1) {
      sh.getRange(startRow + i, startCol + 1, 1, numericColsCount)
        .setNumberFormat(PL_PERCENT_NUMBER_FORMAT)
        .setHorizontalAlignment('right');
    }
  }
}

/**
 * Удаляет условное форматирование из указанного диапазона.
 * Нужно, чтобы старые правила не красили отрицательные значения.
 */
function removePlConditionalFormattingForRange_(sh, targetRange) {
  var rules = sh.getConditionalFormatRules() || [];

  var targetRow1 = targetRange.getRow();
  var targetCol1 = targetRange.getColumn();
  var targetRow2 = targetRow1 + targetRange.getNumRows() - 1;
  var targetCol2 = targetCol1 + targetRange.getNumColumns() - 1;

  var filteredRules = rules.filter(function(rule) {
    var ranges = rule.getRanges() || [];

    return !ranges.some(function(r) {
      if (r.getSheet().getSheetId() !== sh.getSheetId()) return false;

      var row1 = r.getRow();
      var col1 = r.getColumn();
      var row2 = row1 + r.getNumRows() - 1;
      var col2 = col1 + r.getNumColumns() - 1;

      return row1 <= targetRow2 &&
        row2 >= targetRow1 &&
        col1 <= targetCol2 &&
        col2 >= targetCol1;
    });
  });

  sh.setConditionalFormatRules(filteredRules);
}

/**
 * Шапка (1–3) для "Свод (ОПиУ)", "ОПиУ-АНАЛИЗ" и "ОПиУ План-Факт"
 */
function drawYearHeaderPl_(sh, cfg, data) {
  var title = PL_APP_NAME + ' — ' + cfg.company;
  var period = formatIso_(cfg.from) + ' — ' + formatIso_(cfg.to);
  var grouping = 'По месяцам';

  // валюта: если meta.currency есть — используем, иначе CFG
  var currency = (data && data.meta && data.meta.currency)
    ? String(data.meta.currency)
    : (CFG.CURRENCY || 'RUB');

  var source = cfg.baseUrl;
  var tz = Session.getScriptTimeZone() || 'UTC';
  var updated = Utilities.formatDate(new Date(), tz, 'yyyy-MM-dd HH:mm:ss');

  if (sh.getMaxColumns() < 16) {
    sh.insertColumnsAfter(sh.getMaxColumns(), 16 - sh.getMaxColumns());
  }

  sh.getRange(1, 1, 3, 16).clear({contentsOnly: true});

  sh.getRange(1, 1).setValue(title)
    .setFontWeight('bold')
    .setFontSize(12)
    .setHorizontalAlignment('left');

  try {
    sh.getRange('A1:H1').merge();
  } catch(e) {}

  var row2 = [
    'Компания:', cfg.company,
    '', 'Период:', period,
    '', 'Группировка:', grouping,
    '', 'Валюта:', currency
  ];
  sh.getRange(2, 1, 1, row2.length)
    .setValues([row2])
    .setBackground(CFG.COLOR_HEADER_BG);

  var row3 = [
    'Источник:', source,
    '', 'Обновлено:', updated
  ];
  sh.getRange(3, 1, 1, row3.length)
    .setValues([row3])
    .setBackground(CFG.COLOR_HEADER_BG);

  sh.getRange(2, 1).setFontWeight('bold');
  sh.getRange(2, 4).setFontWeight('bold');
  sh.getRange(2, 8).setFontWeight('bold');
  sh.getRange(2, 10).setFontWeight('bold');
  sh.getRange(3, 1).setFontWeight('bold');
  sh.getRange(3, 4).setFontWeight('bold');

  sh.getRange(1, 1, 3, 16)
    .setFontColor(PL_TEXT_FONT_COLOR);

  try {
    sh.setRowHeights(1, 1, 24);
    sh.setRowHeights(2, 2, 20);
  } catch(e) {}
}

/** clamp уровня */
function clampLevel_(lvl) {
  lvl = (typeof lvl === 'number') ? lvl : parseInt(lvl || '0', 10);
  if (!lvl || lvl < 0) return 0;
  if (lvl > 20) return 20;
  return lvl;
}

/** ISO yyyy-mm-dd → dd.mm.yyyy */
function formatIso_(iso) {
  if (!iso || typeof iso !== 'string' || iso.length < 10) return String(iso || '');
  return iso.slice(8,10) + '.' + iso.slice(5,7) + '.' + iso.slice(0,4);
}

/* =======================
   ЛОГИРОВАНИЕ (DEBUG)
   ======================= */

/**
 * Логирует URL запроса (обрезаем токен).
 */
function logPlRequest_(url, cfg) {
  var safeUrl = String(url || '').replace(/token=[^&]+/i, 'token=***');
  Logger.log('[P&L] Request URL=%s', safeUrl);
  Logger.log('[P&L] Year=%s Company="%s" From=%s To=%s', cfg.year, cfg.company, cfg.from, cfg.to);
}

/**
 * Логирует структуру ответа:
 * - пример periods[0]
 * - ключи rawValues для первой строки
 * - количество строк/периодов
 */
function logPlResponseShape_(data) {
  try {
    data = data || {};
    var periods = Array.isArray(data.periods) ? data.periods : [];
    var rows = Array.isArray(data.rows) ? data.rows : [];
    var raw = data.rawValues || {};

    Logger.log('[P&L] Response keys=%s', Object.keys(data).join(','));
    Logger.log('[P&L] periods=%s rows=%s rawRows=%s', periods.length, rows.length, Object.keys(raw).length);

    if (periods.length > 0) {
      Logger.log('[P&L] periods[0]=%s', JSON.stringify(periods[0]));
    }

    var firstRowId = rows.length > 0 ? String(rows[0].id || '') : '';
    if (firstRowId && raw[firstRowId]) {
      Logger.log('[P&L] first row id=%s name=%s', firstRowId, rows[0].name);
      Logger.log('[P&L] rawValues keys for first row=%s', JSON.stringify(Object.keys(raw[firstRowId]).slice(0, 20)));
    }

    // Суперважно: показать mapping label->id для первых 3 периодов
    for (var i = 0; i < Math.min(periods.length, 3); i++) {
      Logger.log('[P&L] period[%s] label=%s id=%s from=%s to=%s',
        i, periods[i].label, periods[i].id, periods[i].from, periods[i].to
      );
    }
  } catch (e) {
    Logger.log('[P&L] logPlResponseShape_ failed: %s', e && e.message ? e.message : e);
  }
}