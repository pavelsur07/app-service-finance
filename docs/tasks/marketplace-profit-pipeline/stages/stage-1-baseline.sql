-- Эталон легаси для сверки нового контура (этап 1). Только SELECT.
-- Параметры подставляет stage-1-run.sh: :company_id, :marketplace, :date_from, :date_to.
-- Фильтры и выражения повторяют ListingSalesAggregateQuery, ListingReturnAggregateQuery,
-- ListingCostAggregateQuery, AdSpendByListingQuery, AdDocumentQuery::sumTotalCostForPeriod —
-- добавлен только разрез по дню. Наборы разделяются строкой "-- name: <имя>".

-- name: A_sales
SELECT s.sale_date::date AS day,
       s.listing_id,
       SUM(CASE WHEN s.marketplace = 'wildberries' THEN s.price_per_unit * s.quantity ELSE s.total_revenue END) AS revenue,
       SUM(s.quantity) AS quantity,
       SUM(CASE WHEN s.cost_price IS NOT NULL THEN s.cost_price * s.quantity ELSE 0 END) AS cost_price_total,
       SUM(CASE WHEN s.cost_price IS NOT NULL THEN s.quantity ELSE 0 END) AS cost_price_quantity,
       COUNT(*) AS row_count
FROM marketplace_sales s
JOIN marketplace_listings l ON l.id = s.listing_id AND l.company_id = s.company_id
WHERE s.company_id = :company_id
  AND s.marketplace = :marketplace
  AND s.sale_date >= :date_from
  AND s.sale_date <= :date_to
GROUP BY 1, 2
ORDER BY 1, 2

-- name: B_returns
SELECT r.return_date::date AS day,
       r.listing_id,
       SUM(CASE WHEN r.marketplace = 'wildberries' THEN r.refund_amount * r.quantity ELSE r.refund_amount END) AS returns_total,
       SUM(r.quantity) AS returns_quantity,
       COUNT(*) AS row_count
FROM marketplace_returns r
WHERE r.company_id = :company_id
  AND r.marketplace = :marketplace
  AND r.return_date >= :date_from
  AND r.return_date <= :date_to
GROUP BY 1, 2
ORDER BY 1, 2

-- name: C_costs
-- Все категории; under_ad_statistics = код из AdStatisticsCostCategories::codes(),
-- который отчёты по листингам исключают (реклама там идёт из статистики, набор D).
SELECT c.cost_date::date AS day,
       c.listing_id,
       cc.code AS category_code,
       cc.name AS category_name,
       (cc.code IN ('ozon_cpc', 'wb_okazanie_uslug_wb_prodvizhenie', 'ozon_marketing_services')) AS under_ad_statistics,
       SUM(CASE WHEN c.operation_type = 'storno' THEN -ABS(c.amount) ELSE ABS(c.amount) END) AS net_amount,
       SUM(CASE WHEN c.operation_type = 'storno' THEN 0 ELSE ABS(c.amount) END) AS costs_amount,
       SUM(CASE WHEN c.operation_type = 'storno' THEN ABS(c.amount) ELSE 0 END) AS storno_amount,
       COUNT(*) AS row_count
FROM marketplace_costs c
JOIN marketplace_cost_categories cc ON cc.id = c.category_id
WHERE c.company_id = :company_id
  AND c.marketplace = :marketplace
  AND c.cost_date >= :date_from
  AND c.cost_date <= :date_to
GROUP BY 1, 2, 3, 4, 5
ORDER BY 1, 3, 2

-- name: D_ads_by_listing
-- Разнесённый расход статистики; kind — как OzonSearchPromoCampaign::campaignCondition().
SELECT ad.report_date::date AS day,
       adl.listing_id,
       CASE WHEN ad.campaign_id IN ('search-promo', 'all-sku-promo') THEN 'pay_per_order' ELSE 'clicks' END AS kind,
       SUM(adl.cost) AS ad_spend,
       SUM(adl.impressions) AS impressions,
       SUM(adl.clicks) AS clicks,
       COUNT(*) AS row_count
FROM marketplace_ad_document_lines adl
JOIN marketplace_ad_documents ad ON ad.id = adl.ad_document_id
WHERE ad.company_id = :company_id
  AND ad.marketplace = :marketplace
  AND ad.report_date BETWEEN :date_from AND :date_to
GROUP BY 1, 2, 3
ORDER BY 1, 3, 2

-- name: D_ads_documents
-- Полный расход документов статистики (включая неразнесённый) — «РР» в итогах отчёта.
SELECT ad.report_date::date AS day,
       CASE WHEN ad.campaign_id IN ('search-promo', 'all-sku-promo') THEN 'pay_per_order' ELSE 'clicks' END AS kind,
       SUM(ad.total_cost) AS total_cost,
       COUNT(*) AS document_count
FROM marketplace_ad_documents ad
WHERE ad.company_id = :company_id
  AND ad.marketplace = :marketplace
  AND ad.report_date BETWEEN :date_from AND :date_to
GROUP BY 1, 2
ORDER BY 1, 2

-- name: E_control
-- Независимые итоги периода одной строкой на источник — проверка целостности снимка.
SELECT 'sales' AS source, COUNT(*) AS row_count, COALESCE(SUM(s.total_revenue), 0) AS amount
FROM marketplace_sales s
WHERE s.company_id = :company_id AND s.marketplace = :marketplace AND s.sale_date >= :date_from AND s.sale_date <= :date_to
UNION ALL
SELECT 'returns', COUNT(*), COALESCE(SUM(r.refund_amount), 0)
FROM marketplace_returns r
WHERE r.company_id = :company_id AND r.marketplace = :marketplace AND r.return_date >= :date_from AND r.return_date <= :date_to
UNION ALL
SELECT 'costs', COUNT(*), COALESCE(SUM(CASE WHEN c.operation_type = 'storno' THEN -ABS(c.amount) ELSE ABS(c.amount) END), 0)
FROM marketplace_costs c
WHERE c.company_id = :company_id AND c.marketplace = :marketplace AND c.cost_date >= :date_from AND c.cost_date <= :date_to
UNION ALL
SELECT 'ad_lines', COUNT(*), COALESCE(SUM(adl.cost), 0)
FROM marketplace_ad_document_lines adl
JOIN marketplace_ad_documents ad ON ad.id = adl.ad_document_id
WHERE ad.company_id = :company_id AND ad.marketplace = :marketplace AND ad.report_date BETWEEN :date_from AND :date_to
UNION ALL
SELECT 'ad_documents', COUNT(*), COALESCE(SUM(ad.total_cost), 0)
FROM marketplace_ad_documents ad
WHERE ad.company_id = :company_id AND ad.marketplace = :marketplace AND ad.report_date BETWEEN :date_from AND :date_to
