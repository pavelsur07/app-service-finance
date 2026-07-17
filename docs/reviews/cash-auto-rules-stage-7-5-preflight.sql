-- Stage 7.5 production preflight.
-- Aggregate read-only checks only: no company names, fact payloads, amounts, or external IDs.

BEGIN TRANSACTION READ ONLY;
SET LOCAL statement_timeout = '30s';
SET LOCAL lock_timeout = '5s';

SELECT 'companies' AS metric, COUNT(*)::bigint AS value FROM companies
UNION ALL
SELECT 'cash_transaction_rows', COUNT(*)::bigint FROM cash_transaction
UNION ALL
SELECT 'document_rows', COUNT(*)::bigint FROM documents
UNION ALL
SELECT 'document_operation_rows', COUNT(*)::bigint FROM document_operations
UNION ALL
SELECT 'pl_daily_total_rows', COUNT(*)::bigint FROM pl_daily_totals
ORDER BY metric;

WITH invalid_companies AS (
    SELECT company.id
    FROM companies company
    LEFT JOIN project_directions project
        ON project.company_id = company.id
       AND project.system_code = 'PROJECT_GENERAL'
    LEFT JOIN financial_responsibility_centers center
        ON center.company_id = company.id
       AND center.code = 'CFO_GENERAL'
    LEFT JOIN financial_responsibility_center_projects pair
        ON pair.company_id = company.id
       AND pair.project_direction_id = project.id
       AND pair.responsibility_center_id = center.id
    GROUP BY company.id
    HAVING COUNT(DISTINCT project.id) <> 1
        OR COUNT(DISTINCT center.id) <> 1
        OR COUNT(DISTINCT pair.id) <> 1
)
SELECT 'companies_without_exact_system_pair' AS metric, COUNT(*)::bigint AS value
FROM invalid_companies;

WITH duplicate_groups AS (
    SELECT COUNT(*)::bigint AS rows_in_group
    FROM pl_daily_totals
    GROUP BY company_id, pl_category_id, date, project_direction_id
    HAVING COUNT(*) > 1
)
SELECT
    COUNT(*)::bigint AS duplicate_groups,
    COALESCE(SUM(rows_in_group - 1), 0)::bigint AS extra_rows
FROM duplicate_groups;

ROLLBACK;
