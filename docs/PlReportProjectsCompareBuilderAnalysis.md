# Analysis of `PlReportProjectsCompareBuilder`

The builder is structurally correct for aggregating project-level P&L values using `PlReportCalculator`. Key behaviors:

- Normalizes inverted date inputs by swapping `$from`/`$to` when needed.
- Builds a single range period via `PlReportPeriod::forRange` and reuses existing category formatting from `PLCategoryRepository` for totals.
- Iterates over provided projects, skipping those without persisted IDs, and collects formatted per-project values plus raw totals for the `_total` column.
- Marks an overhead project in the payload when it matches the optional `$overheadProject` argument.
- Returns warnings aggregated across all calculator invocations.

## Potential risks / considerations

- Projects without IDs are silently skipped, which is consistent with current checks but may hide input issues if callers expect all projects to appear.
- Category formats are looked up only by direct match on company categories; if a category is missing or has a `null` format, the builder falls back to `PLValueFormat::MONEY` for totals.
- Row order follows the sequence encountered in calculator results; if calculators omit zero-value categories, the resulting table may exclude them.
