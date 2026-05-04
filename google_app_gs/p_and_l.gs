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

  Logger.log('[P&L] Done. Rendered rows=%s', rows.length);
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
 * Рендер в лист "Свод (ОПиУ)":
 * — Строки 1–3: шапка
 * — Данные с 4-й строки
 */
function paintYearSummaryPlSheet_(rows, cfg, data) {
  var ss = SpreadsheetApp.getActive();
  var sh = ss.getSheetByName(SHEET_REPORT_SUMMARY_PL) || ss.insertSheet(SHEET_REPORT_SUMMARY_PL);

  // 1) Шапка (1–3)
  drawYearHeaderPl_(sh, cfg, data);

  // 2) Очищаем область данных (как в вашем ДДС-скрипте)
  clearDataArea_(sh);

  // 3) Вставляем данные
  var startRow = CFG.DATA_START_ROW;
  var startCol = CFG.DATA_START_COL;

  var cols = rows[0].length;
  var visibleCols = cols - 1; // Level скрываем

  sh.getRange(startRow, startCol, rows.length, cols).setValues(rows);

  // 4) Шапка таблицы
  sh.getRange(startRow, startCol, 1, visibleCols)
    .setFontWeight('bold')
    .setBackground(CFG.COLOR_HEADER_BG)
    .setNumberFormat('@');

  // 5) Формат чисел (Янв..Дек + Итог)
  var periodsCount = visibleCols - 2; // Строка + Итог
  if (periodsCount > 0) {
    var numericRange = sh.getRange(startRow + 1, startCol + 1, rows.length - 1, periodsCount + 1);
    numericRange.setNumberFormat('#,##0.00').setHorizontalAlignment('right');

    // Условное форматирование: отрицательные
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

  // 6) Выровнять “Строка”
  sh.getRange(startRow, startCol, rows.length, 1).setHorizontalAlignment('left');

  // 7) Зебра
  try {
    sh.getRange(startRow, startCol, rows.length, visibleCols)
      .applyRowBanding(SpreadsheetApp.BandingTheme.LIGHT_GREY);
  } catch (e) {}

  // 8) Скрыть Level
  try { sh.hideColumns(visibleCols + 1); } catch(e) {}

  // 9) Автоширина
  for (var c = 1; c <= visibleCols; c++) {
    try { sh.autoResizeColumn(c); } catch(e) {}
  }

  // 10) Подсветка Level=1
  highlightRowsByLevel_(sh, startRow, rows, 1, CFG.COLOR_LEVEL1_BG, true);
}

/**
 * Шапка (1–3) для "Свод (ОПиУ)"
 */
function drawYearHeaderPl_(sh, cfg, data) {
  var title = 'Свод ОПиУ за год — ' + cfg.company;
  var period = formatIso_(cfg.from) + ' — ' + formatIso_(cfg.to);
  var grouping = 'По месяцам';

  // валюта: если meta.currency есть — используем, иначе CFG
  var currency = (data && data.meta && data.meta.currency) ? String(data.meta.currency) : (CFG.CURRENCY || 'RUB');

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
  try { sh.getRange('A1:H1').merge(); } catch(e) {}

  var row2 = [
    'Компания:', cfg.company,
    '', 'Период:', period,
    '', 'Группировка:', grouping,
    '', 'Валюта:', currency
  ];
  sh.getRange(2, 1, 1, row2.length).setValues([row2]).setBackground(CFG.COLOR_HEADER_BG);

  var row3 = [
    'Источник:', source,
    '', 'Обновлено:', updated
  ];
  sh.getRange(3, 1, 1, row3.length).setValues([row3]).setBackground(CFG.COLOR_HEADER_BG);

  sh.getRange(2, 1).setFontWeight('bold');
  sh.getRange(2, 4).setFontWeight('bold');
  sh.getRange(2, 8).setFontWeight('bold');
  sh.getRange(2, 10).setFontWeight('bold');
  sh.getRange(3, 1).setFontWeight('bold');
  sh.getRange(3, 4).setFontWeight('bold');

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
