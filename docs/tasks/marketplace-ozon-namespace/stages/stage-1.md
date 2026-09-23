### Stage 1: перенос кода Ozon — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `93121786`

#### What was done
- 61 класс и 36 тестов перенесены `git mv` (сходство: медиана 97 %, минимум 78 %); скрипт `move_provider.py` (scratchpad, наследник скрипта этапа 5) — карта FQCN, namespace, `use` для неявных ссылок, все формы записи в baseline (`\`, `\\`, `\\\\`, `\.php`).
- Относительные пути к фикстурам и `config/marketplace/default_cost_mapping.yaml` в 7 перенесённых тестах пересчитаны от прежней цели.
- `routes.yaml`: `marketplace_ozon_controllers`; `ARCHITECTURE.md`: раскладка и путь `OzonAccrualSyncPlanner`.
- Опустевшие каталоги удалены: `Service/` целиком, `Infrastructure/Api`, `Infrastructure/Normalizer/Ozon`.

#### Checks
- phpstan OK с первого прогона; baseline 2410, эквивалентен master после применения карты переименований (все записи)
- cs, strict-types OK; unit 2978 / full 5189, assertions как до
- lint:container OK; debug:router идентичен (416); list идентичен; debug:messenger — только FQCN 4 обработчиков

#### Next
- internal + external review, handoff
