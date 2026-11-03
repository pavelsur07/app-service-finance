# service-coverage — CHANGELOG

## v1.1.0 (2026-11-02)

Добавочное: `period_key` допускает `YYYY-Www` — ISO-неделя (МСК) недельного
ресурса; в ответ попадают недели, пересекающие окно `from`/`to`. Producer —
wb-fetcher (`wb.finance.sales-report.weekly`); ozon-fetcher таких ключей не
отдаёт. Consumer (`ServiceCoverageV1Parser`) принимает ключ в том же выпуске;
`schema_version` остаётся `"1"`.

## v1.0.0 (2026-10-18)

Первая версия: стандартный endpoint покрытия по правилу шлюза
(`docs/architecture/service-gateway.md`). Producer — ozon-fetcher; consumer —
шлюз Symfony (витрина `service_coverage`, страница «Покрытие Сервис GO»).
