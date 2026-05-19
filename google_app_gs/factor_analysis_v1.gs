/**
 * Факторный анализ для файла "ДДС-Сухоносов А.В."
 *
 * Безопасное добавление:
 * 1) Создайте в Apps Script новый файл FactorAnalysis.gs.
 * 2) Вставьте этот код целиком.
 * 3) Если нужен пункт меню, в существующий onOpen() добавьте только строку:
 *    .addItem('Обновить факторный анализ', 'renderFactorAnalysis')
 *
 * Код читает только лист "Свод (ОПиУ)" и пишет только лист "Факторный анализ".
 */

const FACTOR_ANALYSIS_CONFIG = Object.freeze({
  sourceSheetName: 'Свод (ОПиУ)',
  targetSheetName: 'Факторный анализ',

  headerColumnTitle: 'Строка',

  clearRows: 400,
  clearCols: 16,

  monthOrder: [
    'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн',
    'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'
  ],

  moneyFormat: '#,##0;[Red]-#,##0;0',
  percentFormat: '0.0%;[Red]-0.0%;0.0%',
  ppFormat: '0.00;[Red]-0.00;0.00',

  metricAliases: {
    revenue: ['Выручка'],
    variableCosts: ['Прямые переменные расходы', 'Прямые переменные расходы (COGS)'],
    grossProfit: ['Маржинальная прибыль 1 (CM1)', 'CM1 (маржинальная прибыль 1)', 'Валовая прибыль'],
    promoCosts: ['Расходы на продвижение'],
    cm2: ['Маржинальная прибыль 2 (CM2)', 'CM2 (маржинальная прибыль 2)'],
    marketplaceOpex: [
      'Операционные расходы склад / прочие расходы МП',
      'Операц. расходы склад / прочие МП',
      'OPEX склад и пр. МП'
    ],
    cm3: ['Маржинальная прибыль 3 (CM3)', 'CM3 (маржинальная прибыль 3)'],
    overheadCosts: ['Косвенные расходы', 'Косвенные расходы (Overhead)', 'Overhead'],
    ebitda: ['EBITDA (операционная прибыль)', 'EBITDA'],
    belowEbitdaExpenses: ['Расходы ниже EBITDA'],
    belowEbitdaIncome: ['Доходы ниже EBITDA'],
    netProfit: ['Чистая прибыль']
  },

  marginFactors: [
    { group: 'Переменные расходы', title: 'Себестоимость', aliases: ['Себестоимость'] },
    { group: 'Переменные расходы', title: 'Комиссия МП', aliases: ['Комиссия МП'] },
    { group: 'Переменные расходы', title: 'Логистика до клиента', aliases: ['Логистика до клиента'] },
    { group: 'Переменные расходы', title: 'Логистика возвратов', aliases: ['Логистика возвратов'] },
    { group: 'Переменные расходы', title: 'Эквайринг', aliases: ['Эквайринг'] },
    { group: 'Продвижение', title: 'Внутренняя реклама', aliases: ['Внутренняя реклама'] },
    { group: 'Продвижение', title: 'Внешняя реклама', aliases: ['Внешняя реклама'] },
    { group: 'Продвижение', title: 'Раздачи / самовыкупы / отзывы', aliases: ['Раздачи / самовыкупы / отзывы', 'Раздачи / самовыкупы'] },
    { group: 'Операционные расходы МП', title: 'Хранение', aliases: ['Хранение'] },
    { group: 'Операционные расходы МП', title: 'Приёмка', aliases: ['Приёмка', 'Приемка'] },
    { group: 'Операционные расходы МП', title: 'Штрафы', aliases: ['Штрафы'] },
    { group: 'Операционные расходы МП', title: 'Прочие удержания МП', aliases: ['Прочие удержания МП'] },
    { group: 'Операционные расходы МП', title: 'Компенсации МП', aliases: ['Компенсации МП', 'Компенсации МП (+)'] },
    { group: 'Постоянные / косвенные расходы', title: 'Производственные', aliases: ['Производственные', 'Производственные (сертификация)'] },
    { group: 'Постоянные / косвенные расходы', title: 'Административные', aliases: ['Административные'] },
    { group: 'Постоянные / косвенные расходы', title: 'Коммерческие', aliases: ['Коммерческие'] }
  ]
});


function renderFactorAnalysis() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();

  try {
    const sourceData = getFactorAnalysisSourceData_(ss);
    const report = buildFactorAnalysisRows_(sourceData);

    writeFactorAnalysis_(ss, report);
    formatFactorAnalysis_(ss, report);

    ss.toast('Факторный анализ обновлён', 'Готово', 5);
  } catch (error) {
    writeFactorAnalysisError_(ss, error);
    ss.toast('Факторный анализ не обновлён. См. лист "Факторный анализ".', 'Ошибка', 8);
    throw error;
  }
}


function getFactorAnalysisSourceData_(ss) {
  const cfg = FACTOR_ANALYSIS_CONFIG;
  const sheet = ss.getSheetByName(cfg.sourceSheetName);

  if (!sheet) {
    throw new Error('Не найден лист источника: ' + cfg.sourceSheetName);
  }

  const range = sheet.getDataRange();
  const values = range.getValues();
  const displayValues = range.getDisplayValues();

  const headerRowIndex = findFactorAnalysisHeaderRow_(displayValues, cfg.headerColumnTitle);

  if (headerRowIndex === -1) {
    throw new Error(
      'Не найдена строка заголовков с колонкой "' +
      cfg.headerColumnTitle +
      '" на листе "' +
      cfg.sourceSheetName +
      '".'
    );
  }

  const monthColumns = findFactorAnalysisMonthColumns_(displayValues[headerRowIndex], cfg.monthOrder);

  if (monthColumns.length === 0) {
    throw new Error(
      'Не найдены месячные колонки на листе "' +
      cfg.sourceSheetName +
      '". Ожидаются месяцы: ' +
      cfg.monthOrder.join(', ')
    );
  }

  const sourceRows = buildFactorAnalysisSourceRows_(displayValues, headerRowIndex);
  const warnings = [];

  const metrics = {
    revenue: readFactorAnalysisMetric_(sourceRows, values, monthColumns, cfg.metricAliases.revenue, warnings, { required: true }),
    variableCosts: readFactorAnalysisMetric_(sourceRows, values, monthColumns, cfg.metricAliases.variableCosts, warnings, { required: false }),
    grossProfit: readFactorAnalysisMetric_(sourceRows, values, monthColumns, cfg.metricAliases.grossProfit, warnings, { required: false }),
    promoCosts: readFactorAnalysisMetric_(sourceRows, values, monthColumns, cfg.metricAliases.promoCosts, warnings, { required: false }),
    cm2: readFactorAnalysisMetric_(sourceRows, values, monthColumns, cfg.metricAliases.cm2, warnings, { required: false }),
    marketplaceOpex: readFactorAnalysisMetric_(sourceRows, values, monthColumns, cfg.metricAliases.marketplaceOpex, warnings, { required: false }),
    cm3: readFactorAnalysisMetric_(sourceRows, values, monthColumns, cfg.metricAliases.cm3, warnings, { required: false }),
    overheadCosts: readFactorAnalysisMetric_(sourceRows, values, monthColumns, cfg.metricAliases.overheadCosts, warnings, { required: false }),
    ebitda: readFactorAnalysisMetric_(sourceRows, values, monthColumns, cfg.metricAliases.ebitda, warnings, { required: true }),
    belowEbitdaExpenses: readFactorAnalysisMetric_(sourceRows, values, monthColumns, cfg.metricAliases.belowEbitdaExpenses, warnings, { required: false }),
    belowEbitdaIncome: readFactorAnalysisMetric_(sourceRows, values, monthColumns, cfg.metricAliases.belowEbitdaIncome, warnings, { required: false }),
    netProfit: readFactorAnalysisMetric_(sourceRows, values, monthColumns, cfg.metricAliases.netProfit, warnings, { required: false })
  };

  const marginFactors = cfg.marginFactors.map(function (factor) {
    const metric = readFactorAnalysisMetric_(
      sourceRows,
      values,
      monthColumns,
      factor.aliases,
      warnings,
      { required: false, warnIfMissing: true }
    );

    return {
      group: factor.group,
      title: factor.title,
      aliases: factor.aliases,
      found: metric.found,
      sourceLabel: metric.sourceLabel,
      values: metric.values
    };
  });

  const availableMonths = cfg.monthOrder.map(function (_, index) {
    return hasFactorAnalysisMonthData_([
      metrics.revenue.values[index],
      metrics.ebitda.values[index],
      metrics.netProfit.values[index]
    ]);
  });

  return {
    sourceSheetName: cfg.sourceSheetName,
    sourceRangeA1: range.getA1Notation(),
    months: cfg.monthOrder,
    monthColumns: monthColumns,
    metrics: metrics,
    marginFactors: marginFactors,
    availableMonths: availableMonths,
    warnings: warnings
  };
}


function buildFactorAnalysisRows_(sourceData) {
  const cfg = FACTOR_ANALYSIS_CONFIG;

  const rows = [];
  const meta = {
    sectionRows: [],
    headerRows: [],
    totalRows: [],
    noteRows: [],
    warningRows: [],
    numberFormats: []
  };

  const generalEffectsByComparison = {};

  function pushRow(row, type) {
    rows.push(row);

    const rowNumber = rows.length;

    if (type === 'section') meta.sectionRows.push(rowNumber);
    if (type === 'header') meta.headerRows.push(rowNumber);
    if (type === 'total') meta.totalRows.push(rowNumber);
    if (type === 'note') meta.noteRows.push(rowNumber);
    if (type === 'warning') meta.warningRows.push(rowNumber);

    return rowNumber;
  }

  function addNumberFormat(row, col, numRows, numCols, format) {
    if (numRows <= 0 || numCols <= 0) return;

    meta.numberFormats.push({
      row: row,
      col: col,
      numRows: numRows,
      numCols: numCols,
      format: format
    });
  }

  const lastDataMonth = getFactorAnalysisLastDataMonth_(sourceData);

  pushRow(['Факторный анализ EBITDA и чистой прибыли'], 'section');
  pushRow([
    'Источник: лист "' +
    sourceData.sourceSheetName +
    '", диапазон ' +
    sourceData.sourceRangeA1 +
    '. Метод: факт месяц-к-месяцу до конца года.'
  ], 'note');
  pushRow([
    'Данные обнаружены по месяцам: Янв — ' +
    lastDataMonth +
    '. Будущие месяцы с нулевыми значениями помечены как "нет данных".'
  ], 'note');
  pushRow(['']);

  buildFactorAnalysisSummarySection_(sourceData, rows, meta, pushRow, addNumberFormat);
  buildFactorAnalysisSnapshotSection_(sourceData, rows, meta, pushRow, addNumberFormat);
  buildFactorAnalysisGeneralSection_(sourceData, rows, meta, pushRow, addNumberFormat, generalEffectsByComparison);
  buildFactorAnalysisMarginFactorsSection_(sourceData, rows, meta, pushRow, addNumberFormat, generalEffectsByComparison);
  buildFactorAnalysisNetProfitSection_(sourceData, rows, meta, pushRow, addNumberFormat);

  pushRow(['Методика'], 'section');
  pushRow(['1. ΔEBITDA = Эффект выручки + Эффект маржи.'], 'note');
  pushRow(['2. Эффект выручки = ΔВыручка × EBITDA-маржа базового месяца.'], 'note');
  pushRow(['3. Эффект маржи = Выручка текущего месяца × ΔEBITDA-маржи.'], 'note');
  pushRow(['4. Вклад статьи в эффект маржи = (доля статьи 0 − доля статьи 1) × Выручка 1.'], 'note');
  pushRow(['5. Расходы в ОПиУ хранятся отрицательными значениями. Для долей расходов используется знак "-расход / выручка".'], 'note');

  if (sourceData.warnings.length > 0) {
    pushRow(['']);
    pushRow(['Предупреждения / не сопоставлено'], 'section');

    sourceData.warnings.forEach(function (warning) {
      pushRow([warning], 'warning');
    });
  }

  const paddedRows = padFactorAnalysisRows_(rows);

  return {
    rows: paddedRows,
    meta: meta,
    maxCols: paddedRows[0] ? paddedRows[0].length : 1
  };
}


function buildFactorAnalysisSummarySection_(sourceData, rows, meta, pushRow, addNumberFormat) {
  const cfg = FACTOR_ANALYSIS_CONFIG;
  const m = sourceData.metrics;
  const availableIndexes = [];

  sourceData.availableMonths.forEach(function (hasData, index) {
    if (hasData) availableIndexes.push(index);
  });

  pushRow(['Краткий итог по доступному периоду'], 'section');
  pushRow(['Показатель', 'Значение'], 'header');

  if (availableIndexes.length === 0) {
    pushRow(['Нет доступных месяцев', '']);
    pushRow(['']);
    return;
  }

  const lastIndex = availableIndexes[availableIndexes.length - 1];

  const totalRevenue = sumFactorAnalysisArray_(m.revenue.values.slice(0, lastIndex + 1));
  const totalEbitda = sumFactorAnalysisArray_(m.ebitda.values.slice(0, lastIndex + 1));
  const totalNetProfit = sumFactorAnalysisArray_(m.netProfit.values.slice(0, lastIndex + 1));

  const startRow = rows.length + 1;

  pushRow(['Выручка за доступный период', totalRevenue]);
  pushRow(['EBITDA за доступный период', totalEbitda]);
  pushRow(['EBITDA маржа за доступный период', safeFactorAnalysisDiv_(totalEbitda, totalRevenue)]);
  pushRow(['Чистая прибыль за доступный период', totalNetProfit]);
  pushRow(['Чистая маржа за доступный период', safeFactorAnalysisDiv_(totalNetProfit, totalRevenue)]);

  addNumberFormat(startRow, 2, 2, 1, cfg.moneyFormat);
  addNumberFormat(startRow + 2, 2, 1, 1, cfg.percentFormat);
  addNumberFormat(startRow + 3, 2, 1, 1, cfg.moneyFormat);
  addNumberFormat(startRow + 4, 2, 1, 1, cfg.percentFormat);

  pushRow(['']);
}


function buildFactorAnalysisSnapshotSection_(sourceData, rows, meta, pushRow, addNumberFormat) {
  const cfg = FACTOR_ANALYSIS_CONFIG;
  const m = sourceData.metrics;

  pushRow(['Исходные показатели из ОПиУ по месяцам'], 'section');

  pushRow(['Показатель'].concat(sourceData.months).concat(['Итого']), 'header');

  const snapshotItems = [
    {
      title: 'Выручка',
      values: m.revenue.values,
      total: sumFactorAnalysisArray_(m.revenue.values),
      format: cfg.moneyFormat
    },
    {
      title: 'Прямые переменные расходы',
      values: m.variableCosts.values,
      total: sumFactorAnalysisArray_(m.variableCosts.values),
      format: cfg.moneyFormat
    },
    {
      title: 'Валовая прибыль / CM1',
      values: m.grossProfit.values,
      total: sumFactorAnalysisArray_(m.grossProfit.values),
      format: cfg.moneyFormat
    },
    {
      title: 'Валовая маржинальность / CM1 %',
      values: divideFactorAnalysisArrays_(m.grossProfit.values, m.revenue.values),
      total: safeFactorAnalysisDiv_(
        sumFactorAnalysisArray_(m.grossProfit.values),
        sumFactorAnalysisArray_(m.revenue.values)
      ),
      format: cfg.percentFormat
    },
    {
      title: 'Расходы на продвижение',
      values: m.promoCosts.values,
      total: sumFactorAnalysisArray_(m.promoCosts.values),
      format: cfg.moneyFormat
    },
    {
      title: 'Операционные расходы склад / прочие МП',
      values: m.marketplaceOpex.values,
      total: sumFactorAnalysisArray_(m.marketplaceOpex.values),
      format: cfg.moneyFormat
    },
    {
      title: 'Косвенные расходы',
      values: m.overheadCosts.values,
      total: sumFactorAnalysisArray_(m.overheadCosts.values),
      format: cfg.moneyFormat
    },
    {
      title: 'EBITDA',
      values: m.ebitda.values,
      total: sumFactorAnalysisArray_(m.ebitda.values),
      format: cfg.moneyFormat
    },
    {
      title: 'EBITDA маржа, %',
      values: divideFactorAnalysisArrays_(m.ebitda.values, m.revenue.values),
      total: safeFactorAnalysisDiv_(
        sumFactorAnalysisArray_(m.ebitda.values),
        sumFactorAnalysisArray_(m.revenue.values)
      ),
      format: cfg.percentFormat
    },
    {
      title: 'Расходы ниже EBITDA',
      values: m.belowEbitdaExpenses.values,
      total: sumFactorAnalysisArray_(m.belowEbitdaExpenses.values),
      format: cfg.moneyFormat
    },
    {
      title: 'Доходы ниже EBITDA',
      values: m.belowEbitdaIncome.values,
      total: sumFactorAnalysisArray_(m.belowEbitdaIncome.values),
      format: cfg.moneyFormat
    },
    {
      title: 'Чистая прибыль',
      values: m.netProfit.values,
      total: sumFactorAnalysisArray_(m.netProfit.values),
      format: cfg.moneyFormat
    },
    {
      title: 'Чистая маржа, %',
      values: divideFactorAnalysisArrays_(m.netProfit.values, m.revenue.values),
      total: safeFactorAnalysisDiv_(
        sumFactorAnalysisArray_(m.netProfit.values),
        sumFactorAnalysisArray_(m.revenue.values)
      ),
      format: cfg.percentFormat
    }
  ];

  snapshotItems.forEach(function (item) {
    const rowNumber = pushRow(
      [item.title].concat(item.values).concat([item.total])
    );

    addNumberFormat(rowNumber, 2, 1, 13, item.format);
  });

  pushRow(['']);
}


function buildFactorAnalysisGeneralSection_(
  sourceData,
  rows,
  meta,
  pushRow,
  addNumberFormat,
  generalEffectsByComparison
) {
  const cfg = FACTOR_ANALYSIS_CONFIG;
  const revenue = sourceData.metrics.revenue.values;
  const ebitda = sourceData.metrics.ebitda.values;

  pushRow(['Общая декомпозиция ΔEBITDA = эффект выручки + эффект маржи'], 'section');

  pushRow([
    'Сравнение',
    'Выручка 0',
    'Выручка 1',
    'Δ выручки',
    'EBITDA 0',
    'EBITDA 1',
    'Δ EBITDA',
    'Маржа 0',
    'Маржа 1',
    'Δ маржи, п.п.',
    'Эффект выручки',
    'Эффект маржи',
    'Контроль',
    'Статус'
  ], 'header');

  const dataStartRow = rows.length + 1;
  let dataRowCount = 0;

  for (let i = 1; i < sourceData.months.length; i++) {
    dataRowCount++;

    const comparison = sourceData.months[i - 1] + ' → ' + sourceData.months[i];

    if (!sourceData.availableMonths[i - 1] || !sourceData.availableMonths[i]) {
      pushRow([
        comparison,
        '', '', '', '', '', '', '', '', '', '', '', '',
        'нет данных'
      ]);

      generalEffectsByComparison[comparison] = {
        hasData: false,
        marginEffect: 0,
        deltaEbitda: 0
      };

      continue;
    }

    const revenue0 = revenue[i - 1];
    const revenue1 = revenue[i];
    const deltaRevenue = revenue1 - revenue0;

    const ebitda0 = ebitda[i - 1];
    const ebitda1 = ebitda[i];
    const deltaEbitda = ebitda1 - ebitda0;

    const margin0 = safeFactorAnalysisDiv_(ebitda0, revenue0);
    const margin1 = safeFactorAnalysisDiv_(ebitda1, revenue1);
    const deltaMargin = margin1 - margin0;

    const revenueEffect = deltaRevenue * margin0;
    const marginEffect = revenue1 * deltaMargin;
    const control = revenueEffect + marginEffect;

    const status = Math.abs(control - deltaEbitda) <= 1
      ? 'OK'
      : 'Проверь контроль';

    pushRow([
      comparison,
      revenue0,
      revenue1,
      deltaRevenue,
      ebitda0,
      ebitda1,
      deltaEbitda,
      margin0,
      margin1,
      deltaMargin * 100,
      revenueEffect,
      marginEffect,
      control,
      status
    ]);

    generalEffectsByComparison[comparison] = {
      hasData: true,
      marginEffect: marginEffect,
      deltaEbitda: deltaEbitda
    };
  }

  addNumberFormat(dataStartRow, 2, dataRowCount, 6, cfg.moneyFormat);
  addNumberFormat(dataStartRow, 8, dataRowCount, 2, cfg.percentFormat);
  addNumberFormat(dataStartRow, 10, dataRowCount, 1, cfg.ppFormat);
  addNumberFormat(dataStartRow, 11, dataRowCount, 3, cfg.moneyFormat);

  pushRow(['']);
}


function buildFactorAnalysisMarginFactorsSection_(
  sourceData,
  rows,
  meta,
  pushRow,
  addNumberFormat,
  generalEffectsByComparison
) {
  const cfg = FACTOR_ANALYSIS_CONFIG;
  const revenue = sourceData.metrics.revenue.values;

  pushRow(['Разложение эффекта маржи по статьям'], 'section');

  pushRow([
    'Сравнение',
    'Статья',
    'Группа',
    'Доля 0',
    'Доля 1',
    'Δ доли, п.п.',
    'Вклад в EBITDA, ₽',
    'Контроль / статус'
  ], 'header');

  const detailStartRow = rows.length + 1;
  let detailRowCount = 0;

  for (let i = 1; i < sourceData.months.length; i++) {
    const comparison = sourceData.months[i - 1] + ' → ' + sourceData.months[i];
    const effectInfo = generalEffectsByComparison[comparison];

    if (!effectInfo || !effectInfo.hasData) {
      detailRowCount++;
      pushRow([comparison, '', '', '', '', '', '', 'нет данных']);
      continue;
    }

    const revenue0 = revenue[i - 1];
    const revenue1 = revenue[i];

    let totalContribution = 0;
    let hasMissingFactors = false;

    sourceData.marginFactors.forEach(function (factor) {
      detailRowCount++;

      if (!factor.found) {
        hasMissingFactors = true;

        pushRow([
          comparison,
          factor.title,
          factor.group,
          '', '', '', '',
          'Не сопоставлено'
        ]);

        return;
      }

      const value0 = factor.values[i - 1];
      const value1 = factor.values[i];

      const share0 = safeFactorAnalysisDiv_(-value0, revenue0);
      const share1 = safeFactorAnalysisDiv_(-value1, revenue1);

      const deltaSharePp = (share1 - share0) * 100;
      const contribution = (share0 - share1) * revenue1;

      totalContribution += contribution;

      pushRow([
        comparison,
        factor.title,
        factor.group,
        share0,
        share1,
        deltaSharePp,
        contribution,
        'OK'
      ]);
    });

    const controlDiff = totalContribution - effectInfo.marginEffect;
    const status = !hasMissingFactors && Math.abs(controlDiff) <= 1
      ? 'OK'
      : 'Проверь сопоставление';

    detailRowCount++;
    pushRow([
      comparison,
      'ИТОГО эффект маржи',
      '',
      '',
      '',
      '',
      totalContribution,
      status
    ], 'total');
  }

  addNumberFormat(detailStartRow, 4, detailRowCount, 2, cfg.percentFormat);
  addNumberFormat(detailStartRow, 6, detailRowCount, 1, cfg.ppFormat);
  addNumberFormat(detailStartRow, 7, detailRowCount, 1, cfg.moneyFormat);

  pushRow(['']);
}


function buildFactorAnalysisNetProfitSection_(sourceData, rows, meta, pushRow, addNumberFormat) {
  const cfg = FACTOR_ANALYSIS_CONFIG;

  const ebitda = sourceData.metrics.ebitda.values;
  const belowExpenses = sourceData.metrics.belowEbitdaExpenses.values;
  const belowIncome = sourceData.metrics.belowEbitdaIncome.values;
  const netProfit = sourceData.metrics.netProfit.values;

  pushRow(['Контроль чистой прибыли: EBITDA → Чистая прибыль'], 'section');

  pushRow([
    'Сравнение',
    'EBITDA 0',
    'EBITDA 1',
    'Δ EBITDA',
    'Расходы ниже EBITDA 0',
    'Расходы ниже EBITDA 1',
    'Δ расходов ниже EBITDA',
    'Доходы ниже EBITDA 0',
    'Доходы ниже EBITDA 1',
    'Δ доходов ниже EBITDA',
    'Чистая прибыль 0',
    'Чистая прибыль 1',
    'Δ чистой прибыли',
    'Контроль',
    'Статус'
  ], 'header');

  const dataStartRow = rows.length + 1;
  let dataRowCount = 0;

  for (let i = 1; i < sourceData.months.length; i++) {
    dataRowCount++;

    const comparison = sourceData.months[i - 1] + ' → ' + sourceData.months[i];

    if (!sourceData.availableMonths[i - 1] || !sourceData.availableMonths[i]) {
      pushRow([
        comparison,
        '', '', '', '', '', '', '', '', '', '', '', '', '',
        'нет данных'
      ]);

      continue;
    }

    const deltaEbitda = ebitda[i] - ebitda[i - 1];
    const deltaBelowExpenses = belowExpenses[i] - belowExpenses[i - 1];
    const deltaBelowIncome = belowIncome[i] - belowIncome[i - 1];
    const deltaNetProfit = netProfit[i] - netProfit[i - 1];

    const control = deltaEbitda + deltaBelowExpenses + deltaBelowIncome;

    const status = Math.abs(control - deltaNetProfit) <= 1
      ? 'OK'
      : 'Проверь контроль';

    pushRow([
      comparison,
      ebitda[i - 1],
      ebitda[i],
      deltaEbitda,
      belowExpenses[i - 1],
      belowExpenses[i],
      deltaBelowExpenses,
      belowIncome[i - 1],
      belowIncome[i],
      deltaBelowIncome,
      netProfit[i - 1],
      netProfit[i],
      deltaNetProfit,
      control,
      status
    ]);
  }

  addNumberFormat(dataStartRow, 2, dataRowCount, 13, cfg.moneyFormat);

  pushRow(['']);
}


function writeFactorAnalysis_(ss, report) {
  const cfg = FACTOR_ANALYSIS_CONFIG;

  let sheet = ss.getSheetByName(cfg.targetSheetName);

  if (!sheet) {
    sheet = ss.insertSheet(cfg.targetSheetName);
  }

  if (sheet.getMaxColumns() < Math.max(cfg.clearCols, report.maxCols)) {
    sheet.insertColumnsAfter(
      sheet.getMaxColumns(),
      Math.max(cfg.clearCols, report.maxCols) - sheet.getMaxColumns()
    );
  }

  if (sheet.getMaxRows() < Math.max(cfg.clearRows, report.rows.length)) {
    sheet.insertRowsAfter(
      sheet.getMaxRows(),
      Math.max(cfg.clearRows, report.rows.length) - sheet.getMaxRows()
    );
  }

  sheet
    .getRange(1, 1, cfg.clearRows, cfg.clearCols)
    .breakApart()
    .clear();

  if (report.rows.length === 0) {
    sheet.getRange(1, 1).setValue('Нет данных для факторного анализа.');
    return;
  }

  sheet
    .getRange(1, 1, report.rows.length, report.maxCols)
    .setValues(report.rows);
}


function formatFactorAnalysis_(ss, report) {
  const cfg = FACTOR_ANALYSIS_CONFIG;
  const sheet = ss.getSheetByName(cfg.targetSheetName);

  if (!sheet || report.rows.length === 0) {
    return;
  }

  const rowCount = report.rows.length;
  const colCount = report.maxCols;

  sheet.setHiddenGridlines(true);
  sheet.setFrozenRows(1);

  const fullRange = sheet.getRange(1, 1, rowCount, colCount);

  fullRange
    .setFontFamily('Arial')
    .setFontSize(10)
    .setVerticalAlignment('middle')
    .setWrap(true)
    .setBorder(true, true, true, true, true, true, '#D1D5DB', SpreadsheetApp.BorderStyle.SOLID);

  sheet.getRange(1, 1, 1, colCount)
    .setBackground('#1F2937')
    .setFontColor('#FFFFFF')
    .setFontWeight('bold')
    .setFontSize(13);

  report.meta.sectionRows.forEach(function (row) {
    sheet.getRange(row, 1, 1, colCount)
      .setBackground('#E5E7EB')
      .setFontWeight('bold')
      .setFontColor('#111827');
  });

  report.meta.headerRows.forEach(function (row) {
    sheet.getRange(row, 1, 1, colCount)
      .setBackground('#374151')
      .setFontColor('#FFFFFF')
      .setFontWeight('bold')
      .setHorizontalAlignment('center')
      .setWrap(true);
  });

  report.meta.totalRows.forEach(function (row) {
    sheet.getRange(row, 1, 1, colCount)
      .setBackground('#F3F4F6')
      .setFontWeight('bold');
  });

  report.meta.noteRows.forEach(function (row) {
    sheet.getRange(row, 1, 1, colCount)
      .setFontColor('#4B5563')
      .setWrap(true);
  });

  report.meta.warningRows.forEach(function (row) {
    sheet.getRange(row, 1, 1, colCount)
      .setBackground('#FEF3C7')
      .setFontColor('#92400E')
      .setWrap(true);
  });

  report.meta.numberFormats.forEach(function (formatConfig) {
    sheet
      .getRange(
        formatConfig.row,
        formatConfig.col,
        formatConfig.numRows,
        formatConfig.numCols
      )
      .setNumberFormat(formatConfig.format);
  });

  sheet.getRange(1, 1, rowCount, 1).setFontWeight('bold');

  sheet.setColumnWidth(1, 220);
  sheet.setColumnWidth(2, 130);
  sheet.setColumnWidth(3, 150);

  for (let col = 4; col <= colCount; col++) {
    sheet.setColumnWidth(col, 115);
  }
}


function writeFactorAnalysisError_(ss, error) {
  const cfg = FACTOR_ANALYSIS_CONFIG;

  let sheet = ss.getSheetByName(cfg.targetSheetName);

  if (!sheet) {
    sheet = ss.insertSheet(cfg.targetSheetName);
  }

  if (sheet.getMaxColumns() < cfg.clearCols) {
    sheet.insertColumnsAfter(sheet.getMaxColumns(), cfg.clearCols - sheet.getMaxColumns());
  }

  if (sheet.getMaxRows() < 20) {
    sheet.insertRowsAfter(sheet.getMaxRows(), 20 - sheet.getMaxRows());
  }

  sheet
    .getRange(1, 1, 20, cfg.clearCols)
    .breakApart()
    .clear();

  sheet.getRange(1, 1).setValue('Факторный анализ не сформирован');
  sheet.getRange(2, 1).setValue(String(error && error.message ? error.message : error));

  sheet.getRange(1, 1, 1, cfg.clearCols)
    .setBackground('#7F1D1D')
    .setFontColor('#FFFFFF')
    .setFontWeight('bold');

  sheet.getRange(2, 1, 1, cfg.clearCols)
    .setBackground('#FEE2E2')
    .setFontColor('#991B1B')
    .setWrap(true);
}


function findFactorAnalysisHeaderRow_(displayValues, headerColumnTitle) {
  const expected = normalizeFactorAnalysisLabel_(headerColumnTitle);

  for (let row = 0; row < displayValues.length; row++) {
    if (normalizeFactorAnalysisLabel_(displayValues[row][0]) === expected) {
      return row;
    }
  }

  return -1;
}


function findFactorAnalysisMonthColumns_(headerRow, monthOrder) {
  const foundByMonth = {};

  headerRow.forEach(function (cellValue, colIndex) {
    const month = normalizeFactorAnalysisMonth_(cellValue);

    if (month) {
      foundByMonth[month] = colIndex;
    }
  });

  return monthOrder
    .filter(function (month) {
      return Object.prototype.hasOwnProperty.call(foundByMonth, month);
    })
    .map(function (month) {
      return {
        month: month,
        colIndex: foundByMonth[month]
      };
    });
}


function buildFactorAnalysisSourceRows_(displayValues, headerRowIndex) {
  const rows = [];

  for (let row = headerRowIndex + 1; row < displayValues.length; row++) {
    const rawLabel = displayValues[row][0];
    const normalizedLabel = normalizeFactorAnalysisLabel_(rawLabel);

    if (!normalizedLabel) {
      continue;
    }

    rows.push({
      rowIndex: row,
      rawLabel: rawLabel,
      normalizedLabel: normalizedLabel
    });
  }

  return rows;
}


function readFactorAnalysisMetric_(sourceRows, values, monthColumns, aliases, warnings, options) {
  const opts = options || {};
  const foundRow = findFactorAnalysisSourceRowByAliases_(sourceRows, aliases);

  if (!foundRow) {
    const message = 'Не сопоставлено: ' + aliases[0];

    if (opts.required) {
      throw new Error('Обязательная строка ОПиУ не найдена. ' + message);
    }

    if (opts.warnIfMissing !== false) {
      warnings.push(message);
    }

    return {
      found: false,
      sourceLabel: '',
      values: monthColumns.map(function () {
        return 0;
      })
    };
  }

  return {
    found: true,
    sourceLabel: foundRow.rawLabel,
    values: monthColumns.map(function (monthColumn) {
      return toFactorAnalysisNumber_(values[foundRow.rowIndex][monthColumn.colIndex]);
    })
  };
}


function findFactorAnalysisSourceRowByAliases_(sourceRows, aliases) {
  const normalizedAliases = aliases.map(normalizeFactorAnalysisLabel_);

  for (let i = 0; i < normalizedAliases.length; i++) {
    const alias = normalizedAliases[i];

    const exactMatch = sourceRows.find(function (row) {
      return row.normalizedLabel === alias;
    });

    if (exactMatch) {
      return exactMatch;
    }
  }

  for (let i = 0; i < normalizedAliases.length; i++) {
    const alias = normalizedAliases[i];

    const partialMatch = sourceRows.find(function (row) {
      return row.normalizedLabel.indexOf(alias) !== -1;
    });

    if (partialMatch) {
      return partialMatch;
    }
  }

  return null;
}


function normalizeFactorAnalysisLabel_(value) {
  return String(value || '')
    .replace(/^[•\s]+/g, '')
    .replace(/\s+/g, ' ')
    .replace(/ё/g, 'е')
    .trim()
    .toLowerCase();
}


function normalizeFactorAnalysisMonth_(value) {
  const raw = String(value || '')
    .replace(/\./g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();

  if (!raw) {
    return '';
  }

  const key = raw.substring(0, 3);

  const map = {
    'янв': 'Янв',
    'фев': 'Фев',
    'мар': 'Мар',
    'апр': 'Апр',
    'май': 'Май',
    'июн': 'Июн',
    'июл': 'Июл',
    'авг': 'Авг',
    'сен': 'Сен',
    'окт': 'Окт',
    'ноя': 'Ноя',
    'дек': 'Дек'
  };

  return map[key] || '';
}


function toFactorAnalysisNumber_(value) {
  if (typeof value === 'number') {
    return isFinite(value) ? value : 0;
  }

  if (value === null || value === '') {
    return 0;
  }

  const cleaned = String(value)
    .replace(/\s/g, '')
    .replace('%', '')
    .replace(',', '.')
    .replace('−', '-')
    .replace('–', '-')
    .replace('—', '-');

  const number = Number(cleaned);

  return isFinite(number) ? number : 0;
}


function hasFactorAnalysisMonthData_(values) {
  return values.some(function (value) {
    return Math.abs(toFactorAnalysisNumber_(value)) > 0.000001;
  });
}


function safeFactorAnalysisDiv_(numerator, denominator) {
  numerator = toFactorAnalysisNumber_(numerator);
  denominator = toFactorAnalysisNumber_(denominator);

  if (Math.abs(denominator) < 0.000001) {
    return 0;
  }

  return numerator / denominator;
}


function sumFactorAnalysisArray_(values) {
  return values.reduce(function (sum, value) {
    return sum + toFactorAnalysisNumber_(value);
  }, 0);
}


function divideFactorAnalysisArrays_(numerators, denominators) {
  return numerators.map(function (numerator, index) {
    return safeFactorAnalysisDiv_(numerator, denominators[index]);
  });
}


function padFactorAnalysisRows_(rows) {
  const maxCols = rows.reduce(function (max, row) {
    return Math.max(max, row.length);
  }, 1);

  return rows.map(function (row) {
    const copy = row.slice();

    while (copy.length < maxCols) {
      copy.push('');
    }

    return copy;
  });
}


function getFactorAnalysisLastDataMonth_(sourceData) {
  let lastIndex = 0;

  sourceData.availableMonths.forEach(function (hasData, index) {
    if (hasData) {
      lastIndex = index;
    }
  });

  return sourceData.months[lastIndex] || sourceData.months[0];
}
