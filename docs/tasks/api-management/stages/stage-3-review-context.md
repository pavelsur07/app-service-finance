# Stage3 review context

Base36b773b3; task docs/tasks/api-management/TASK.md. Stage3 only: explicit prepared permissions, owner-enabled resources, default-deny endpoint guard, audited versioned save. Stage2 UI exists; actual editing UI/HTTP409 reload functional tests are Stage4, not missing scope here.

All production catalog resources intentionally disconnected. Save Action rejects enabling disconnected resources; pure policy accepts server connected-resources argument so future behavior can be tested. Entity test setup can model enabled resources; no public write path bypasses Action. No financial endpoint added.

Internal fresh review0BLOCKER/0IMPORTANT/0MINOR. Integrated Api unit/integration/functional and route gate338tests1354assertions PASS; PHPStan Api/tests/migration0errors. Tests include full16×16 independent permission matrix, real HTTP missing/unknown/defaultdeny, disconnected prepared grants, next-request freshness, owner A/member B, stale browser/identity-map version, no-op, rollback after actual SQL update and audit insertion.

Test-only fixture site/tests/Functional/Api/Fixtures/PermissionController.php is registered in services_test.yaml and routes/test/api_permissions.yaml; external-review wrapper may omit fixture diff, source remains readable. No production fixture route. Migration20260913090200 applied only localTEST; empty defaults preserve old inserts. PublicID DBAL difference predates thisStage and was documented/accepted at Stage1.
