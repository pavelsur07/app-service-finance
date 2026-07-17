\set ON_ERROR_STOP on

BEGIN TRANSACTION READ ONLY;
SET LOCAL statement_timeout = '30s';

WITH recognized AS (
    SELECT p.company_id, p.id
    FROM project_directions p
    WHERE LOWER(BTRIM(p.name)) IN ('общий', 'основной', 'общие операции')
),
per_company AS (
    SELECT c.id AS company_id, COUNT(r.id) AS candidate_count
    FROM companies c
    LEFT JOIN recognized r ON r.company_id = c.id
    GROUP BY c.id
)
SELECT
    COUNT(*) AS companies_total,
    COUNT(*) FILTER (WHERE candidate_count = 1) AS unambiguous,
    COUNT(*) FILTER (WHERE candidate_count = 0) AS missing,
    COUNT(*) FILTER (WHERE candidate_count > 1) AS ambiguous
FROM per_company;

WITH recognized AS (
    SELECT
        p.company_id,
        p.id,
        CASE LOWER(BTRIM(p.name))
            WHEN 'общий' THEN 'Общий'
            WHEN 'основной' THEN 'Основной'
            WHEN 'общие операции' THEN 'Общие операции'
        END AS candidate_name
    FROM project_directions p
    WHERE LOWER(BTRIM(p.name)) IN ('общий', 'основной', 'общие операции')
)
SELECT candidate_name, COUNT(*) AS project_count
FROM recognized
GROUP BY candidate_name
ORDER BY candidate_name;

WITH recognized AS (
    SELECT p.company_id, p.id
    FROM project_directions p
    WHERE LOWER(BTRIM(p.name)) IN ('общий', 'основной', 'общие операции')
),
project_counts AS (
    SELECT company_id, COUNT(*) AS project_count
    FROM project_directions
    GROUP BY company_id
),
cash_counts AS (
    SELECT company_id, COUNT(*) AS cash_count
    FROM cash_transaction
    WHERE deleted_at IS NULL
    GROUP BY company_id
),
document_counts AS (
    SELECT company_id, COUNT(*) AS document_count
    FROM documents
    GROUP BY company_id
),
pl_counts AS (
    SELECT company_id, COUNT(*) AS pl_count
    FROM pl_daily_totals
    GROUP BY company_id
),
per_company AS (
    SELECT
        c.id AS company_id,
        COUNT(DISTINCT r.id) AS candidate_count,
        COALESCE(MAX(pc.project_count), 0) AS project_count,
        COALESCE(MAX(cc.cash_count), 0) AS cash_count,
        COALESCE(MAX(dc.document_count), 0) AS document_count,
        COALESCE(MAX(plc.pl_count), 0) AS pl_count
    FROM companies c
    LEFT JOIN recognized r ON r.company_id = c.id
    LEFT JOIN project_counts pc ON pc.company_id = c.id
    LEFT JOIN cash_counts cc ON cc.company_id = c.id
    LEFT JOIN document_counts dc ON dc.company_id = c.id
    LEFT JOIN pl_counts plc ON plc.company_id = c.id
    GROUP BY c.id
)
SELECT
    COUNT(*) AS companies_total,
    COUNT(*) FILTER (
        WHERE project_count = 0
          AND cash_count = 0
          AND document_count = 0
          AND pl_count = 0
    ) AS empty_companies,
    COUNT(*) FILTER (
        WHERE project_count > 0
           OR cash_count > 0
           OR document_count > 0
           OR pl_count > 0
    ) AS initialized_companies,
    COUNT(*) FILTER (
        WHERE (project_count > 0 OR cash_count > 0 OR document_count > 0 OR pl_count > 0)
          AND candidate_count = 1
    ) AS initialized_unambiguous,
    COUNT(*) FILTER (
        WHERE (project_count > 0 OR cash_count > 0 OR document_count > 0 OR pl_count > 0)
          AND candidate_count = 0
    ) AS initialized_missing,
    COUNT(*) FILTER (
        WHERE (project_count > 0 OR cash_count > 0 OR document_count > 0 OR pl_count > 0)
          AND candidate_count > 1
    ) AS initialized_ambiguous
FROM per_company;

WITH recognized AS (
    SELECT p.company_id, p.id
    FROM project_directions p
    WHERE LOWER(BTRIM(p.name)) IN ('общий', 'основной', 'общие операции')
),
project_counts AS (
    SELECT company_id, COUNT(*) AS project_count
    FROM project_directions
    GROUP BY company_id
),
cash_counts AS (
    SELECT company_id, COUNT(*) AS cash_count
    FROM cash_transaction
    WHERE deleted_at IS NULL
    GROUP BY company_id
),
document_counts AS (
    SELECT company_id, COUNT(*) AS document_count
    FROM documents
    GROUP BY company_id
),
pl_counts AS (
    SELECT company_id, COUNT(*) AS pl_count
    FROM pl_daily_totals
    GROUP BY company_id
),
per_company AS (
    SELECT
        c.id AS company_id,
        COUNT(DISTINCT r.id) AS candidate_count,
        COALESCE(MAX(pc.project_count), 0) AS project_count,
        COALESCE(MAX(cc.cash_count), 0) AS cash_count,
        COALESCE(MAX(dc.document_count), 0) AS document_count,
        COALESCE(MAX(plc.pl_count), 0) AS pl_count
    FROM companies c
    LEFT JOIN recognized r ON r.company_id = c.id
    LEFT JOIN project_counts pc ON pc.company_id = c.id
    LEFT JOIN cash_counts cc ON cc.company_id = c.id
    LEFT JOIN document_counts dc ON dc.company_id = c.id
    LEFT JOIN pl_counts plc ON plc.company_id = c.id
    GROUP BY c.id
)
SELECT
    SUBSTRING(MD5(company_id::text), 1, 8) AS company_ref,
    candidate_count,
    project_count,
    cash_count,
    document_count,
    pl_count
FROM per_company
WHERE (project_count > 0 OR cash_count > 0 OR document_count > 0 OR pl_count > 0)
  AND candidate_count <> 1
ORDER BY company_ref;

SELECT *
FROM (
    SELECT
        'cash_transaction_active' AS dataset,
        COUNT(*) AS total_rows,
        COUNT(*) FILTER (WHERE project_direction_id IS NULL) AS missing_project,
        COUNT(DISTINCT company_id) AS companies
    FROM cash_transaction
    WHERE deleted_at IS NULL

    UNION ALL

    SELECT
        'documents',
        COUNT(*),
        COUNT(*) FILTER (WHERE project_direction_id IS NULL),
        COUNT(DISTINCT company_id)
    FROM documents

    UNION ALL

    SELECT
        'document_operations',
        COUNT(*),
        COUNT(*) FILTER (WHERE o.project_direction_id IS NULL),
        COUNT(DISTINCT d.company_id)
    FROM document_operations o
    INNER JOIN documents d ON d.id = o.document_id

    UNION ALL

    SELECT
        'pl_daily_totals',
        COUNT(*),
        COUNT(*) FILTER (WHERE project_direction_id IS NULL),
        COUNT(DISTINCT company_id)
    FROM pl_daily_totals
) coverage
ORDER BY dataset;

SELECT
    COUNT(*) AS active_internal_transfers,
    COUNT(*) FILTER (WHERE project_direction_id IS NULL) AS missing_project
FROM cash_transaction
WHERE deleted_at IS NULL
  AND is_transfer = true;

ROLLBACK;
