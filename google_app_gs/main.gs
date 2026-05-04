/***** CONFIG *****/
var CFG = {
  SHEET_CFG: 'Config',
  BASE_URL_CELL: 'B1',  // https://app.2bstock.ru
  COMPANY_CELL:  'B2',  // Название компании
  YEAR_CELL:     'B3',  // Год (например 2025)
  MONTH_CELL:    'B4',  // Месяц по-русски (январь, февраль, ...)

  TOKENS_RANGE_NAME: 'TOKENS',   // именованный диапазон: Company | Token
  SHEET_REPORT: 'Отчёт (ДДС)',

  DATA_START_ROW: 4,   // шапку (1-3 строки) не трогаем
  DATA_START_COL: 1,

  CURRENCY: 'RUB',     // жёстко

  // Оформление
  COLOR_SALDO_BG: '#FFF3CD',     // мягкий жёлтый для Сальдо
  COLOR_LEVEL1_BG: '#F0F7FF',    // мягкий голубой для Level=1
  COLOR_HEADER_BG: '#F7F7F7'
};

/***** Меню *****/
function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('SaaS')
    .addItem('Обновить Свод ДДС', 'renderCashflowYearlyReport') // ← добавить эту строку
    .addItem('Обновить Свод (ОПиУ)', 'renderPlYearlyReport') // ← добавить эту строку
    .addToUi();
}

function pad2_(n) { return ('0' + n).slice(-2); }

/***** Поиск токена по названию компании *****/
function findTokenForCompany_(companyName) {
  var range = SpreadsheetApp.getActive().getRangeByName(CFG.TOKENS_RANGE_NAME);
  if (!range) throw new Error('Именованный диапазон "' + CFG.TOKENS_RANGE_NAME + '" не найден');
  var values = range.getValues(); // [[Company, Token], ...]
  for (var i = 0; i < values.length; i++) {
    var name = String(values[i][0] || '').trim();
    var token = String(values[i][1] || '').trim();
    if (name === companyName) return token;
  }
  return '';
}

/***** Сервис очистки только области данных *****/
function clearDataArea_(sh) {
  var startRow = CFG.DATA_START_ROW, startCol = CFG.DATA_START_COL;
  var maxRows = sh.getMaxRows();
  var maxCols = sh.getMaxColumns();

  if (maxRows >= startRow) {
    sh.getRange(startRow, 1, maxRows - startRow + 1, maxCols).clear({contentsOnly: true});
    // убрать бэндинги только в зоне данных
    try {
      var bandings = sh.getBandings();
      for (var i = 0; i < bandings.length; i++) {
        var rng = bandings[i].getRange();
        if (rng.getRow() >= startRow) bandings[i].remove();
      }
    } catch(e) {}

    // НЕ трогаем фильтры/мерджи в шапке; на всякий — уберём мерджи только в зоне данных
    try {
      sh.getRange(startRow, 1, maxRows - startRow + 1, Math.min(100, maxCols)).breakApart();
    } catch(e) {}
  }
}

/***** Подсветка строк по названию в первой ячейке *****/
function highlightRowsByTitle_(sh, startRow, rows, titles, bgColor, bold) {
  var titleSet = {};
  titles.forEach(function(t){ titleSet[t] = true; });

  for (var i = 0; i < rows.length; i++) {
    var title = String(rows[i][0] || '');
    if (titleSet[title]) {
      var r = sh.getRange(startRow + i, CFG.DATA_START_COL, 1, rows[0].length - 1); // без Level
      r.setBackground(bgColor);
      if (bold) r.setFontWeight('bold');
    }
  }
}

/***** Подсветка строк по Level *****/
function highlightRowsByLevel_(sh, startRow, rows, levelValue, bgColor, bold) {
  var levelColIndex = rows[0].length; // в листе это последняя колонка (скрытая)
  for (var i = 0; i < rows.length; i++) {
    var lvl = rows[i][rows[0].length - 1]; // в массиве — последняя ячейка
    if (Number(lvl) === Number(levelValue)) {
      var r = sh.getRange(startRow + i, CFG.DATA_START_COL, 1, rows[0].length - 1); // без Level
      r.setBackground(bgColor);
      if (bold) r.setFontWeight('bold');
    }
  }
}

/***** HTTP и утилиты *****/
function buildUrl_(base, path, params) {
  var q = [];
  for (var k in params) {
    if (params.hasOwnProperty(k)) {
      var v = params[k];
      if (v !== undefined && v !== null && v !== '') {
        q.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
      }
    }
  }
  var query = q.length ? ('?' + q.join('&')) : '';
  return base.replace(/\/$/, '') + path + query;
}

function fetchJson_(url) {
  var res = UrlFetchApp.fetch(url, { muteHttpExceptions: true });
  var code = res.getResponseCode();
  var body = res.getContentText();
  if (code < 200 || code >= 300) {
    throw new Error('API error ' + code + ': ' + body);
  }
  try {
    return JSON.parse(body);
  } catch (e) {
    throw new Error('Ошибка парсинга JSON: ' + e.message + '; body=' + body);
  }
}

function clampLevel_(lvl) {
  var n = Number(lvl || 0);
  if (isNaN(n)) n = 0;
  if (n < 0) n = 0;
  if (n > 5) n = 5;
  return n;
}
