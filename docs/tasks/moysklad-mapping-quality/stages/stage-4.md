### Stage 4: legacy UI — проверка

**Risk:** HIGH-LOCAL. **Stage base commit:** `8372aa8f9`. **Work items:** 4.1,4.2.

#### What was done
- В существующей Twig/Tabler вкладке: scoped WB preset, запуск фонового отчёта, сохранённая сводка/прогресс/время, фильтры состояния/причины/ручного разбора, empty/running/stale/error.
- Основания и сравниваемые значения, количества, barcode, конкуренты в preview и diagnostics; ручная форма требует явного подтверждения спорного target с отпечатками.
- Два независимых paginator по25 с сохранением фильтров/страниц. Ручной поиск по точному barcode. READ/WRITE и CSRF без новых работников/расписаний/dependencies.

#### Files changed
- `site/src/MoySklad/{Controller,Form,Application/Action}` — existing UI contracts.
- `site/templates/moy_sklad/mappings/`, `site/templates/partials/_pagination.html.twig` — templates.
- `site/tests/{Functional,Integration,Unit}/MoySklad/` — observable regression tests.
- `ARCHITECTURE.md` — current contract.

#### Definition of Done
- [x] UI states/filters/evidence/explicit review, permissions/CSRF.
- [x] Regression tests RED→GREEN.
- [x] Desktop/mobile smoke1280/390 report/manual form: no overflow; screenshot inspection.
- [x] Combined Stage4/handoff fresh internal and external full-task review (Stage5).

#### Checks
- Targeted65tests/494assertions; extra12tests/92assertions; latest13tests/106assertions GREEN.
- Focused PHPStan includes worker fixtures: GREEN. Full checks tracked in Stage5.
- `npm run lint`, `npm run build`, `npm run test:unit`: GREEN,152tests. No Vite entry/CSS/JS/API schema changes; production assets unchanged by task.
- Browser smoke uses HTML captured by HTTP functional tests, real built CSS/fonts; scripts and remote app requests blocked. This proves rendering, not end-to-end queue/JS behavior; queue and CSRF are covered by functional/integration tests.

#### Internal / External review
Combined with handoff because final application-code Stage. Fresh internal: BLOCKER0/IMPORTANT1 (repeated full scan) fixed RED→GREEN; FOLLOW-UP1 GET fingerprint cost recorded. CI IMPORTANT1 missing WRITE-gate fixed, regression/read-only UI and full route coverage GREEN. Final external Claude1 round: IMPORTANT1 storage growth, MINOR2 fixed/verified13tests/127assertions; fixed without re-run. Details Stage5/handoff.

#### Risks / Next
Existing null barcodes require normal catalog reimport. WB preset defaults off. Conservative connection-wide revision/review fingerprint can invalidate another marketplace's report. Continue Stage5 automatically.
