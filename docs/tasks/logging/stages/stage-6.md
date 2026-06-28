## Stage 6: конвенция логирования + аудит handler'ов — DONE

**Риск:** 🟢 LOW (документация + read-only аудит; код handler'ов НЕ менялся)
**Следующее действие:** continue → Phase Final (handoff). Точечные правки уровней — отдельные follow-up по решению Владельца.

### Что сделано
1. **Конвенция зафиксирована:**
   - `PATTERNS.md` §23 «Логирование: выбор уровня (error vs warning)» — правило, Messenger-паттерн (warning+Recoverable / error+Unrecoverable), агрегация в циклах, 4xx, PII. Добавлено в навигацию.
   - `CLAUDE.md` (раздел «Логирование») — краткое правило «error vs warning» с антипаттернами и ссылкой на §23.
2. **Аудит ~32 MessageHandler + связанных Command/Subscriber** на соответствие конвенции (read-only).

### Результаты аудита

#### Эталон (так и надо)
- `Marketplace/MessageHandler/SyncWbFinancialReportDayHandler` — образцовое разделение warning (retryable) / error (terminal). Использован как референс в §23.

#### Downgrade-кандидаты `error → warning` — видны в GlitchTip (реальный шум) ⭐
| Файл:строка | Сейчас | Почему мислейбл | Канал |
|---|---|---|---|
| `Marketplace/MessageHandler/CloseMonthStageHandler.php:50` | `error` | `\DomainException` «данные не готовы», не ретраится, проглатывается — ожидаемое доменное условие | app → **GlitchTip** |
| `Marketplace/MessageHandler/SyncOzonReportHandler.php:96` | `error` | Невалидная дата на входе → `skip`/`return`, не системный сбой | app → **GlitchTip** |

Это прямые источники ложных алертов — **рекомендую исправить в первую очередь** (отдельный мелкий этап + регрессионный тест на уровень).

#### Downgrade-кандидаты `error → warning` — канал ads файловый (косметика)
| Файл:строка | Сейчас | Почему мислейбл |
|---|---|---|
| `MarketplaceAds/MessageHandler/FetchOzonAdStatisticsHandler.php:210` | `error` | Комментарий прямо: «transient, Messenger сделает retry» |
| `MarketplaceAds/Command/AdBatchSchedulerCommand.php:185` | `error` | «transient … batch остаётся PLANNED, cron повторит» |

Канал `marketplace_ads` вне GlitchTip (решение Владельца) → на алерты не влияет, но уровень стоит привести в порядок при следующей правке этих файлов.

#### Upgrade-кандидаты `warning → error` — на обсуждение
| Файл:строка | Сейчас | Замечание |
|---|---|---|
| `Ingestion/Infrastructure/Messenger/SyncJobFailureSubscriber.php:70` | `warning` | «sync job marked as failed after retries exhausted» — терминальный сбой. Возможно `error` (нужен человек). Не ads. **Решение за Владельцем.** |
| `MarketplaceAds/…` (OzonAdReportPoller:138, RequestOzonAdBatchHandler:203/239, DownloadOzonAdReportHandler:119, AdLoadJobFailureSubscriber:76) | `warning` | Перманентные/terminal ads-сбои на warning. Это и есть «ads blind spot» из Stage 0 — **отложено** (ads остаётся файловым по решению Владельца). |

### Затронутые файлы
- `PATTERNS.md` — modified (§23 + навигация)
- `CLAUDE.md` — modified (правило error vs warning в разделе «Логирование»)
- `docs/tasks/logging/stages/stage-6.md` — new (этот отчёт)
- Код handler'ов — **НЕ менялся** (точечные правки уровней вынесены в follow-up по плану)

### Self-review
- [x] Scope compliance — только документация + аудит; поведение системы не изменено
- [x] Patterns / naming — N/A (документация)
- [x] Forbidden actions — none (код не тронут, миграций нет)
- [x] Security — N/A
- [x] CS / PHPStan / tests — N/A (нет изменений кода); существующие тесты не затронуты
- [x] ARCHITECTURE.md — N/A

### Follow-ups (для отдельных мелких этапов, требуют greenlight Владельца)
1. ⭐ Downgrade `error→warning` в `CloseMonthStageHandler:50` и `SyncOzonReportHandler:96` (+ регрессионный тест на уровень) — убирает реальный шум в GlitchTip.
2. Downgrade transient-error в `FetchOzonAdStatisticsHandler:210`, `AdBatchSchedulerCommand:185` (косметика, ads файловый).
3. Решить по `Ingestion SyncJobFailureSubscriber:70` (warning→error?).
4. «Ads observability» (Stage 0 Q4) — поднять реальные terminal-сбои ads в GlitchTip после чистки уровней. Отложено по решению Владельца.
5. Stage 5 (глобальное обогащение scope `company_id`/`user_id`/`message_class` + чистка `AppLogger::logSlowExecution`) — вынесено в follow-up.

### Открытые вопросы
- нет (готов к Phase Final)
