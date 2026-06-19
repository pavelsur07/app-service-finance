### Stage 5: API types and docs — DONE

**Risk:** LOW
**Next action:** final handoff

#### What was done
- Regenerated `site/assets/api/schema.d.ts` from the OpenAPI dump.
- Added 422 error response schemas for the new verification endpoints.
- Updated `ARCHITECTURE.md` with the new verification API and known shop-scope caveat.
- Ran focused verification tests and generated API type check.

#### Files changed
- `site/assets/api/schema.d.ts` — modified/generated
- `ARCHITECTURE.md` — modified
- `site/src/Ingestion/Controller/Api/Verification/*Controller.php` — modified OpenAPI annotations

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated

#### Checks
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli sh -lc 'php -d memory_limit=-1 bin/console nelmio:apidoc:dump --format=json > var/openapi.json'` — passed.
- `docker compose run --rm site-frontend sh -lc 'npx openapi-typescript var/openapi.json -o assets/api/schema.d.ts && npx openapi-typescript var/openapi.json -o /tmp/schema.check.d.ts && diff /tmp/schema.check.d.ts assets/api/schema.d.ts'` — passed.
- `docker compose run --rm site-frontend sh -lc 'npx -y @stoplight/spectral-cli lint var/openapi.json'` — failed before linting: no Spectral ruleset found.
- `make api-types` — failed in this environment because `site-php-cli` was not running, then `exec` dump exited 137; equivalent container `run` workflow above passed.

#### Risks / reviewer focus
- Generated TypeScript marks OpenAPI object properties optional because of current inline OA schemas; endpoint query params and response keys are still documented and generated.

#### Open questions
- none
