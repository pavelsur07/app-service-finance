-- Prepared only. Production: explicit named approval + owner-controlled write channel required.
-- Repair: disable only the two legacy mappings; keep realization enabled.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
DO $repair$
DECLARE
    matched integer;
    changed integer;
BEGIN
    -- Lock all four expected rows in stable ID order before validating their current state.
    PERFORM id FROM marketplace_sale_mappings
    WHERE company_id = '19621cff-b028-45d9-9193-11f47ad9a8b2'
      AND id IN ('a5a617c9-19c2-4d58-8b53-9235f4cb0a8a', '01a158ef-ebec-71d5-8a5b-668a54a97ba4', '01a188d3-7b83-7340-926c-a80a472a9d02', '01a188d3-7b88-726a-99e2-a7c4fc86de8d')
    ORDER BY id FOR UPDATE;

    WITH expected(id, source, op, category, negative, legacy) AS (VALUES
        ('a5a617c9-19c2-4d58-8b53-9235f4cb0a8a'::uuid, 'sale_revenue', 'sale', 'f580a3c1-342b-449d-9d2c-007be9f9858f'::uuid, false, true),
        ('01a158ef-ebec-71d5-8a5b-668a54a97ba4'::uuid, 'return_refund', 'return', '63411175-935c-4956-ba2b-38c649314e24'::uuid, true, true),
        ('01a188d3-7b83-7340-926c-a80a472a9d02'::uuid, 'sale_realization', 'sale', 'f580a3c1-342b-449d-9d2c-007be9f9858f'::uuid, false, false),
        ('01a188d3-7b88-726a-99e2-a7c4fc86de8d'::uuid, 'return_realization', 'return', '63411175-935c-4956-ba2b-38c649314e24'::uuid, true, false)
    )
    SELECT COUNT(*) INTO matched
    FROM expected e JOIN marketplace_sale_mappings m ON m.id = e.id
    JOIN pl_categories c ON c.id = m.pl_category_id AND c.company_id = m.company_id
    WHERE m.company_id = '19621cff-b028-45d9-9193-11f47ad9a8b2' AND m.marketplace = 'ozon'
      AND m.amount_source = e.source AND m.operation_type = e.op
      AND m.pl_category_id = e.category AND m.is_negative = e.negative
      AND (e.legacy OR m.is_active = true)
      AND c.code = CASE e.op WHEN 'sale' THEN 'REV_SPP_SALES' ELSE 'REV_SPP_RETURNS' END;
    IF matched <> 4 THEN
        RAISE EXCEPTION 'Expected four exact company-scoped Ozon rules; got %. No changes applied.', matched;
    END IF;

    UPDATE marketplace_sale_mappings SET is_active = false, updated_at = CURRENT_TIMESTAMP
    WHERE company_id = '19621cff-b028-45d9-9193-11f47ad9a8b2' AND marketplace = 'ozon'
      AND id IN ('a5a617c9-19c2-4d58-8b53-9235f4cb0a8a', '01a158ef-ebec-71d5-8a5b-668a54a97ba4')
      AND is_active <> false;
    GET DIAGNOSTICS changed = ROW_COUNT;
    RAISE NOTICE 'Legacy rules changed: % (0 on repeated execution)', changed;
END
$repair$;
COMMIT;
