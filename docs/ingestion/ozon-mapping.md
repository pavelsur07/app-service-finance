# Ingestion Ozon Seller Mapping

## Resources

| Resource type | Source API | Mapper |
|---|---|---|
| `ozon_seller_daily_report` | `POST /v3/finance/transaction/list` | `OzonSellerReportMapper` |
| `ozon_seller_realization` | `POST /v2/finance/realization` | `OzonRealizationMapper` |

Legacy Marketplace Ozon pipelines remain unchanged. The Ingestion connector reads Ozon Seller data through `LegacyOzonClientAdapter`, writes raw NDJSON through `RawStorageFacade`, and normalizes rows into `FinancialTransaction`.

## Natural Keys

Base operation id:

| Input | Base id |
|---|---|
| `operation_id` present | `ozon:operation:{operation_id}` |
| `operation_id` missing | `ozon:fallback:{posting_number}:{sku}:{date}` |

Canonical transaction external id:

```text
{base id}:{component}
```

The component suffix is intentional. Current canonical uniqueness is `(companyId, source, externalId, type)`, and one Ozon operation can contain several rows with the same `TransactionType` such as multiple logistics or fee components. The suffix keeps daily and realization rows overwrite-compatible while preserving all components.

`operationGroupId` is UUIDv5 over `{companyId}:{base id}`. All components from one Ozon operation share the same group id.

## Daily Report Mapping

| Ozon field | Component | TransactionType | Direction |
|---|---|---|---|
| `accruals_for_sale` | `sale` | `SALE` | `IN` for positive, `OUT` for negative |
| `amount` with `operation_type=ClientReturnAgentOperation` | `refund` | `REFUND` | `OUT` |
| `sale_commission_amount` / `sale_commission` | `commission` | `COMMISSION` | by amount sign |
| `deliv_charge_amount` / `delivery_charge` | `logistics_delivery` | `LOGISTICS` | by amount sign |
| `return_delivery_charge_amount` / `return_delivery_charge` | `logistics_return_delivery` | `LOGISTICS` | by amount sign |
| `services_amounts.MarketplaceServiceItemReturnAfterDelivToCustomer` | `service_*` | `LOGISTICS` | by amount sign |
| `services_amounts.MarketplaceServiceItemDelivToCustomer` | `service_*` | `LAST_MILE` | by amount sign |
| other `services_amounts` / `services[]` | `service_*` | `FEE` | by amount sign |
| `acquiring` / `acquiring_amount` | `acquiring` | `ACQUIRING` | by amount sign |
| fallback nonzero `amount` | `other` | `OTHER` | by amount sign |

Amounts are stored as unsigned minor units in `Money`; direction is represented by `TransactionDirection`.

## Realization Mapping

Realization uses the same component rules and external id algorithm as the daily report. It additionally accepts common realization field names:

| Realization field | Canonical meaning |
|---|---|
| `seller_price` / `price` | sale amount |
| `commission_amount` | commission |
| `delivery_commission` | logistics delivery |
| `return_delivery_commission` | return logistics |
| `report_date`, `_header.stop_date` | `externalUpdatedAt` candidates |

Because realization `externalUpdatedAt` is later than the daily operation date, `UpsertFinancialTransactionAction` replaces preliminary daily values instead of creating duplicates.

## Dates And References

| Canon field | Source |
|---|---|
| `occurredAt` | `operation_date`, then `sale_date`, then `return_date`, then header dates |
| daily `externalUpdatedAt` | operation date |
| realization `externalUpdatedAt` | `realization_report_period_end`, `report_date`, header stop/doc date, then operation/sale/return date |
| `sourceTz` | `Europe/Moscow` |
| `orderRef` | `posting.posting_number` or `posting_number` |
| `payoutRef` | `payout_ref`, `realization_id`, or `_header.doc_number` |

## Control Sum

For each raw row, the mapper produces one control sum:

```text
sum(abs(mapped component amount minor))
```

The control sum is compared against all canonical transactions in the same `operationGroupId`. Ozon control sums require the raw record company id, so Ozon mappers implement `RawRecordAwareControlSumMapperInterface`; the base `controlSum()` method remains empty for backward compatibility with the generic mapper contract.
