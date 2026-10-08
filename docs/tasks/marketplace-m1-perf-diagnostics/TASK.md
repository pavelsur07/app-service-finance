# TASK: Marketplace M1 — Lightweight Performance Diagnostics

Источник: бриф Владельца в чате 2026-10-08 (основной TASK + дополнение). Текст ниже — сжатая
фиксация обоих без изменения требований.

## Контекст

Основание: Stage 0, `docs/architecture/marketplace-audit/01–07`, особенно `06-migration-roadmap.md`.
Production: Symfony, Redis Streams, PostgreSQL, S3; 2–3 кабинета, проблемы производительности
уже проявляются. Prometheus/Grafana нет; на M1 новую инфраструктуру не добавляем.

## Цель

Измеримый baseline производительности Ozon/WB и Marketplace processing, чтобы найти реальные
bottlenecks и обоснованно выбрать архитектуру Symfony / Go / Python, в том числе отдельно решить
о переносе **загрузки, парсинга и source normalization** в Go/Python.
**Диагностический Stage: оптимизацию и перенос не выполнять. M2 не начинать.**

## Уточнённая целевая архитектура (дополнение)

1. Symfony остаётся владельцем финансовых операций, правил, закрытия периодов и posting.
2. Production S3 уже хранит Raw Data — новое Raw Storage не создавать, хранение не менять.
3. Нормализация — потенциально самостоятельный сервис (Go или Python); не закреплять её за
   Symfony архитектурным решением.
4. Инструментирование по этапам: Marketplace API fetch; S3 read/write; Source parsing;
   Source normalization; Financial mapping; Financial posting; Queue waiting.
5. Каждый этап измерять независимо: duration, rows, bytes, memory, errors, retries, где применимо.
6. Выявить зависимости нормализации от Doctrine, финансовых Entity, справочников и закрытых периодов.
7. Рекомендации по границе Source Normalization ↔ Financial Processing.
8. Без Prometheus/Grafana и новой инфраструктуры.
9. Не менять финансовую логику, очереди, Raw Data, S3, существующие контракты.

Все изменения backward-compatible и rollback-safe; после deploy — проверка работоспособности и
минимальной нагрузки instrumentation.

## 1. Основной принцип

Legacy Marketplace работает до, во время и после M1. Запрещено менять: сценарии загрузки Ozon/WB;
нормализацию и финансовые расчёты; финансовые операции и закрытие месяца; routing, transports,
семантику сообщений; схему и содержимое Raw Data в S3; порядок posting.
Instrumentation — additive, отключаемый, rollback-safe.

## 2. Instrumentation

Семь этапов: API fetch; S3 read/write; source parsing; source normalization; financial mapping;
financial processing/posting; Messenger queue waiting и handler execution. Этап, который нельзя
выделить без изменения бизнес-поведения, — ограничение в документации, без искусственной границы.

Структурированные события Monolog. Поля: `stage`, `provider`, `duration_ms`, `rows`/`bytes` когда
доступны, `memory_peak_bytes`, `outcome`, `retry_count` когда доступен, безопасный идентификатор
кабинета/job, если допустимо политикой логирования. Не писать токены, raw payload, ПДн, финансовые
значения. Без синхронной записи в PostgreSQL на каждую операцию.

## 3. Queue diagnostics

Проверить фактическую реализацию Messenger и Redis transport. Измерять: enqueue-to-start lag (если
есть надёжное время постановки), handler duration, ошибки и повторы, failed/pending/oldest message
age где безопасно. Payload и message classes ради метрик не менять; если точный lag невозможен —
безопасный read-only замер и документированное ограничение.

## 4. Управление диагностикой

Единый env feature flag; минимальный overhead при выключенном; отключение без релиза кода (с
перезапуском процессов); ограничение объёма и retention логов; защита от высокой кардинальности и
неконтролируемого логирования; сбой instrumentation не прерывает бизнес-операцию.

## 5. CLI Performance Report

Read-only команда, отчёт по диагностическим логам за период: количество операций; p50/p95/p99 по
этапам и провайдерам; max peak memory; rows/sec и bytes/sec если есть данные; ошибки и retries;
queue lag и handler runtime отдельно; число отсутствующих/неполных измерений. Markdown/JSON.
Без тяжёлых запросов к production PostgreSQL и без неограниченного сканирования логов.

## 6. Аудит границы нормализации

`docs/architecture/marketplace-audit/08-normalization-boundary.md`: source parsing Ozon/WB; source
normalization; financial mapping; зависимости от Doctrine и финансовых Entity; справочники и
настройки компаний; корректировки и закрытые периоды; что технически выносится в Go/Python; что
обязательно остаётся в Symfony. Без рефакторинга нормализации.

## 7. Acceptance

1. Legacy Ozon/WB работает без изменения результатов.
2. Замеры полностью отключаются флагом.
3. CLI формирует корректный отчёт, включая отсутствие данных.
4. В логах нет секретов, Raw Data, финансовых payload.
5. Сообщения и формат S3 не меняются.
6. Тесты: успешная обработка, ошибки, выключенная диагностика, отказ логирования.
7. Накладные расходы измерены и приведены; недоступные измерения обозначены.
8. Проверена совместимость rolling deploy и старых сообщений.
9. Нет изменений финансовой семантики и инвариантов.

## 8. Production baseline (после отдельного разрешения на deployment)

Health workers; успешность Ozon/WB sync; очереди Redis и failed; отсутствие новых финансовых
расхождений; baseline на 2–3 кабинетах; сравнение длительности/памяти с выключенной и включённой
диагностикой. Наблюдение 7–14 дней, PR от него не зависит. Stage не объявлять подтверждённым в
production до sanity check.

## 9. Документация

`08-normalization-boundary.md`, `09-m1-diagnostics.md`, `10-m1-baseline-template.md`; строка M1 в
`06-migration-roadmap.md`: структурированные логи + CLI-отчёт вместо обязательного
Prometheus/Grafana dashboard (они — возможный будущий этап, не зависимость M1).

## 10. Порядок и handoff

Инфраструктура → дизайн → замеры → CLI → тесты → локальная проверка → review → отдельный PR →
summary. **Без merge, deployment и production-команд без отдельного подтверждения.**
Handoff: URL PR, изменённые файлы, тесты, что измеряется и что нет, как включить/выключить, план
deploy и rollback, риски и рекомендации перед M2. Статус: `Ready for review / merge and deploy approval`.
