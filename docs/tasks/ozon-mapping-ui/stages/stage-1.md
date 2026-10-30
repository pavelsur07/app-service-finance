### Stage 1 — DONE
Base c5485f18f; HIGH-LOCAL; backend для кнопки и запрета конфликтов.
Существующая conflict query переиспользована для деталей; два scoped actions с общим
advisory key сериализуют Ozon writes; repair проверяет replacements и меняет только
is_active/updated_at legacy. CSRF+MARKETPLACE_WRITE, active company, idempotence.
Read-only GET, суммы/формулы/документы/другие marketplaces не менялись.
Baseline 15 tests/114 assertions. Red 5 failures (missing endpoint + no guards),
green 23 tests/136 assertions. Focused PHPStan green; CS fixed only task files.
Initial test fixture assumptions corrected: missing replacement must use standard code;
foreign active-company test logs in actual owner, not an unauthorized selection fallback.
Initial PHPStan findings: DBAL int|string cast + nullable owner narrowed; fixed.
Internal: 0 BLOCKER/IMPORTANT; external at handoff (<500 code/test lines).
Next Stage 2 automatically, baseline UI test red proves missing labels.
