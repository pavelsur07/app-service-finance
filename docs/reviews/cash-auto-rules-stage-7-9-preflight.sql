-- Stage 7.9 read-only aggregate preflight.
-- This file contains no writes and returns no company, rule, project, or user identifiers.
-- Run each section only after owner approval through the restricted read-only production path.

-- A. Safe before the Stage 7.9 responsibility_center_id column exists.
SELECT
    COUNT(*) AS total_rules,
    COUNT(*) FILTER (WHERE is_active) AS active_rules,
    COUNT(*) FILTER (WHERE is_active AND project_direction_id IS NOT NULL) AS active_project_target_rules,
    COUNT(*) FILTER (WHERE is_active AND project_direction_id IS NULL) AS active_without_project_target
FROM cash_transaction_auto_rule;

SELECT
    COUNT(*) AS companies_with_active_project_rules,
    COALESCE(SUM(rule_count), 0) AS active_project_rules,
    COALESCE(MAX(rule_count), 0) AS max_active_project_rules_per_company
FROM (
    SELECT company_id, COUNT(*) AS rule_count
    FROM cash_transaction_auto_rule
    WHERE is_active
      AND project_direction_id IS NOT NULL
    GROUP BY company_id
) grouped_rules;

SELECT
    COUNT(*) FILTER (WHERE project.system_code = 'PROJECT_GENERAL') AS active_system_project_targets,
    COUNT(*) FILTER (WHERE project.system_code IS DISTINCT FROM 'PROJECT_GENERAL') AS active_custom_project_targets
FROM cash_transaction_auto_rule rule
JOIN project_directions project ON project.id = rule.project_direction_id
WHERE rule.is_active;

-- B. Cutover gate after the nullable Stage 7.9 column is deployed and owners configure rules.
-- Expected result before enabling the pair planner/runtime defaults: 0.
SELECT COUNT(*) AS active_project_rules_without_cfo
FROM cash_transaction_auto_rule
WHERE is_active
  AND project_direction_id IS NOT NULL
  AND responsibility_center_id IS NULL;

-- Every configured active complete target must remain company-scoped, active, and allowed.
-- Expected result before cutover: 0.
SELECT COUNT(*) AS invalid_active_complete_targets
FROM cash_transaction_auto_rule rule
JOIN financial_responsibility_centers center ON center.id = rule.responsibility_center_id
LEFT JOIN financial_responsibility_center_projects pair
       ON pair.company_id = rule.company_id
      AND pair.project_direction_id = rule.project_direction_id
      AND pair.responsibility_center_id = rule.responsibility_center_id
WHERE rule.is_active
  AND rule.project_direction_id IS NOT NULL
  AND rule.responsibility_center_id IS NOT NULL
  AND (
      center.company_id <> rule.company_id
      OR center.status <> 'active'
      OR pair.id IS NULL
  );
