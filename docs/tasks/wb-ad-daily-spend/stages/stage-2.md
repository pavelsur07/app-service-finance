# Stage 2 Report: Exact campaign-to-SKU allocation and projection

Stage base commit: `80b30dfb03dce33a6d107535686e84db1682eb7f`
Risk: HIGH-LOCAL
Status: complete

## Delivered

- Aggregates all `/adv/v1/upd` rows by campaign before RUB rounding.
- Uses only `/adv/v3/fullstats.days[].apps[].nms[].sum` as SKU weights; higher
  campaign/day/app totals are intentionally ignored.
- Allocates actual expense in integer minor units and assigns the complete
  rounding residue to the SKU with the largest weight, with a deterministic
  natural-`nmId` tie break.
- Emits explicit `__unallocated__` expense when positive weights are absent.
- Omits zero-actual campaigns because they have no expense to project.
- Preserves a real but unmapped WB `nmId` in `AdDocument` totals without a
  listing line and leaves its raw document in `DRAFT` for mapping repair.
- Treats intentional `__unallocated__` as successfully processed without a
  listing line.
- Adds nullable `AdRawDocument.sourceKey` and a unique
  `(company_id, marketplace, source_key)` constraint for idempotent daily WB
  reruns while preserving multiple Ozon rows with `NULL` source keys.
- Corrects current `/adv/v3/fullstats` query parameters to `beginDate` and
  `endDate`.
- Updates MarketplaceAds architecture and source-key repository contract.

## Financial invariant

For every non-zero campaign/day:

```text
sum(AdDocument.totalCost for allocated nmId)
+ AdDocument.totalCost for __unallocated__
= Money(sum(/adv/v1/upd.updSum))
```

The implementation never uses `float`. A 50-scenario property-style test plus
targeted rounding, negative correction, zero-weight, and duplicate-row tests
verify the invariant.

## Checks

- MarketplaceAds unit suite: green, 321 tests / 2025 assertions.
- Targeted repository/projection integration:
  green, 20 tests / 61 assertions.
- Full MarketplaceAds integration:
  green, 169 tests / 681 assertions.
- Symfony container lint: green.
- Doctrine ORM mapping validation: green.
- New migration executed successfully in the isolated test database.
- Full test-schema sync remains red from broad pre-existing repository drift;
  it does not include a missing Stage 2 mapping operation.
- `git diff --check`: green.

## Internal review

- Fixed numeric PHP array-key coercion by prefixing campaign and nmId map keys.
- Added integer-overflow guards for aggregated view/click counters.
- Rejects unsupported decimal precision instead of silently truncating it.
- Added deterministic zero-spend behavior and exact-total scenario coverage.
- No unresolved BLOCKER or IMPORTANT findings.

## External Claude Code review

- Initial result: `REVIEW_GREEN` with safe MINOR observations.
- Fixed both MINOR observations:
  architecture version is now 1.64, and zero-spend campaigns do not create
  stale DRAFT projections.
- Repeat review result: `REVIEW_GREEN`.
- Accepted FOLLOW-UP:
  - consider graceful unallocated fallback plus explicit observability for
    malformed non-authoritative analytics;
  - review concurrent-index rollout if the production raw table is large.
- Rejected optional largest-remainder refinement because the approved task rule
  assigns the residue to the SKU with maximum weight.

## Compatibility and operations

- Ozon behavior is unchanged; all projection changes are WB-gated.
- No live WB request was made.
- No production migration, data, deployment, worker, or cron action was run.
