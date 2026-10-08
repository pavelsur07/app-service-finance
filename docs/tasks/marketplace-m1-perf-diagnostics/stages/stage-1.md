### Stage 1: инструментирование, отчёт, тесты — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `5a8c36d9`
**Work items:** 1.1 ядро, 1.2 точки замеров, 1.3 CLI + снимок очередей, 1.4 тесты

#### What was done
- `PerformanceRecorder` (область на сообщение/команду, накопление этапов, лимит, отказоустойчивость), подписчики Messenger/Console, Doctrine flush listener, декоратор ObjectStorage, точки замеров в клиентах Ozon/WB и конвейере.
- `app:marketplace:perf-report`, `MessengerQueueSnapshotQuery`.

#### Definition of Done
- [x] замеры этапов, флаг, канал/ротация
- [x] очередь: lag из id Redis Stream, handler, ошибки/повторы, снимок pending/oldest/failed
- [x] CLI-отчёт с пустыми данными и ограничениями
- [x] тесты успеха, ошибок, выключенного флага, отказа логирования; pipeline-паритет до копейки

#### Checks
- unit 3264 OK, integration pipeline OK, stan/cs/strict-types OK, локальный e2e воркера.

#### Internal review
- iterations: 1 (fresh session); IMPORTANT 2 исправлены; MINOR исправлены; FOLLOW-UP: deploy.yml флаг.

#### External review
- required: yes (Large, handoff); rounds: 1; result: fixed without re-run (3 IMPORTANT fixed, 1 rejected — чужой untracked файл).

#### Next
- handoff
