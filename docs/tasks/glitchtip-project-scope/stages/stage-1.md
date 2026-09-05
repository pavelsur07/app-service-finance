### Stage 1: читалка GlitchTip смотрит в свой проект, а репозиторий не раздаёт боевой DSN — DONE

**Risk:** MEDIUM
**Owner gate:** yes — решение о ротации DSN и проверка HEALTH_CHECK_TOKEN
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** STOP, owner action required

#### Stage scope
- Stage base commit: `63a8db2ad4d35059f43863a653a90416bccf26d2`
- Work items completed: `1.1` (область выборки `gt.sh`), `1.2` (боевой DSN в коммитируемом `.env`)

#### What was done

**Исходная гипотеза оказалась неверной, и это выяснилось по данным.** Задача была
сформулирована как «развести GlitchTip с чужим проектом conwix и выключить отправку из dev».
Проверка показала, что разводить нечего: в организации уже два проекта — `app_vashfindirru`
(id 1, наш, 11 unresolved issues) и `api_conwix` (id 2, чужой продукт, 26 unresolved).
События не смешиваются на стороне трекера.

Смешивала их **читалка**. `gt.sh` объявляет `PROJECT="app_vashfindirru"` и не использует эту
переменную: `cmd_list` ходил в `/organizations/<slug>/issues/`, то есть по всей организации, и
возвращал 37 issues обоих проектов вперемешку. Верхние строки по числу событий принадлежали
чужому продукту (issue 294 — 150 событий, `admin.conwix.com`), поэтому триаж по этой выдаче
уходил не туда. Именно из-за этого первичный разбор ошибок прода пошёл по ложному следу.

**Второй дефект — настоящий и в репозитории.** `site/.env` отслеживается git и содержал непустой
`SENTRY_DSN` (74 символа), указывающий на проект 1. Symfony читает `.env` во всех окружениях,
поэтому dev-контейнер любого разработчика писал в продовый проект GlitchTip без единого действия
с его стороны. Подтверждение в данных: issue 320 в `app_vashfindirru` — наша миграция
`uniq_ingest_order_status_event_observation` (`site/migrations/Version20260902170000.php`) с
тегом `environment=dev`.

Прод от `.env` не зависит: `SENTRY_DSN` приходит из GitHub secrets
(`.github/workflows/deploy.yml`) в `docker-compose.prod.yml` через якорь `x-php-env` (шесть
сервисов) и отдельный блок сервиса `scheduler`. Перекрытие проверено эмпирически.

Сделано:
1. `cmd_list` запрашивает `/projects/<slug>/$PROJECT/issues/`. Заодно в выдачу добавлена колонка
   `LAST` (`lastSeen`): без свежести приоритизация идёт по накопленному числу событий, и давно
   потухший issue выглядит важнее вчерашнего — на этом первичный разбор тоже спотыкался.
2. `SENTRY_DSN` в `site/.env` опустошён, рядом объяснено почему и куда класть локальное значение.

#### Files changed
- `gt.sh` — modified
- `site/.env` — modified
- `Makefile` — modified (цель `make test-gt`)
- `tests/shell/gt-test.sh` — new
- `site/tests/Unit/Config/CommittedEnvTest.php` — new

#### Definition of Done
- [x] `gt.sh list` возвращает только issues проекта `app_vashfindirru`
- [x] в выдаче видна свежесть
- [x] коммитируемый `.env` не несёт боевой DSN
- [x] прод продолжает получать DSN из окружения (проверено эмпирически)
- [x] оба изменения доказаны красными тестами на коде до правки
- Исключено из Stage: ротация DSN (owner), проверка `HEALTH_CHECK_TOKEN` (owner), корзина
  supercronic (owner gate, PR #2417)

#### Baseline
- `bash tests/shell/gt-test.sh` — теста не существовало; написан в этом стейдже
- красного baseline в репозитории нет: cs, strict-types и stan были зелёными до задачи

#### Checks
- targeted: `make test-gt` — OK; `php bin/phpunit --filter CommittedEnvTest` — OK (2 теста, 5 assertions)
- реальный прогон против GlitchTip: `./gt.sh list 20` вернул ровно 11 наших issues (до правки — 37 обоих проектов)
- full relevant stage: `make site-test-unit` — OK (2293, 4 pre-existing deprecations);
  `make site-test-integration` — OK (1218); `composer test:functional` — OK (598, 2 pre-existing deprecations)
- `make site-cs-check` — Found 0 of 2467
- `make site-cs-strict-types` — Found 0 of 2467
- `make site-stan` — No errors; `phpstan-baseline.neon` не менялся

**Красное доказательство.** На коде до правки `tests/shell/gt-test.sh` падает с
«не содержит /projects/test-org/app_vashfindirru/issues/», а лог заглушки показывает фактически
запрошенный организационный URL. `CommittedEnvTest` падает обоими тестами.

**Мутационная проверка:** удаление `// "-"` из `gt.sh` роняет сценарий с отсутствующим `lastSeen`
(колонка `LAST` становится пустой).

#### Internal automatic review
- Iterations: 1
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: регэксп поиска DSN-образных значений расширен, чтобы ловить DSN с путём
  (`https://key@host/some/path/42`); проверено, что на реальном `.env` ложных срабатываний нет
  (`DATABASE_URL` — схема `pgsql`, `TELEGRAM_WEBHOOK_URL` и `LLM_API_URL` — без `@`)
- FOLLOW-UP: см. «Risks / reviewer focus»

#### External Claude Code review
- Реализация — Claude Code, внешний ревьюер — Codex (`codex exec -s read-only --ephemeral`), по таблице ролей `CLAUDE.md`
- Iterations: 1
- Result: REVIEW_GREEN
- Confirmed findings fixed: одна находка MINOR — заглушка всегда возвращала непустой `lastSeen`,
  поэтому ветка fallback `(.lastSeen // "-")` тестом не исполнялась. Принята и исправлена уже
  после `REVIEW_GREEN`; изменение только в тесте, продакшн-код не трогался
- Rejected findings with reason: none
- Ограничение ревьюера: шелла у него не было, команды он не запускал. Дифф передан с **вырезанным
  значением `SENTRY_DSN`** — секрет наружу не отправлялся. Факты о GlitchTip (состав проектов,
  issue 320, перекрытие переменной окружения) переданы в промпт

#### Review fixes applied
- Добавлены сценарии ответа без `lastSeen` и с `lastSeen: null`; проверка усилена до регэкспа на
  колонку `LAST`, а не на любой дефис в выводе. Чувствительность доказана мутацией: удаление
  `// "-"` из `gt.sh` роняет тест (колонка становится пустой)

#### Risks / reviewer focus
- Значение DSN остаётся в истории git. Опустошение файла это не отменяет. Ротация ключа в
  GlitchTip и обновление секрета в GitHub — решение Владельца, в дифф не входит.
- `gt.sh` живёт в корне репозитория и не смонтирован в контейнер (`/app` — это `site/`), поэтому
  его тест написан на bash и запускается на хосте через `make test-gt`. В CI он **не выполняется**
  — ровно как существующий `tests/shell/provision-vf-prod-deploy-test.sh`. Регресс области
  выборки CI не поймает.
- `gt.sh show` и `gt.sh brief` обращаются к issue по id и остаются глобальными по организации:
  посмотреть чужой issue по-прежнему можно. Это осознанно — команда с явным id не вводит в
  заблуждение, в отличие от списка.

#### Отдельная находка, вынесенная Владельцу
В `site/.env` заполнен `HEALTH_CHECK_TOKEN` — 32 символа, не hex, без слов-маркеров
плейсхолдера, и это значение не встречается больше нигде в репозитории. Прод берёт его из
окружения (`HEALTH_CHECK_TOKEN: ${HEALTH_CHECK_TOKEN}` без значения по умолчанию), поэтому
коммитируемое значение влияет только на локальную разработку — **если оно не совпадает с
боевым**. Совпадает или нет, знает только Владелец. Значение не печаталось и не менялось.

#### Checkpoint
- `docs/tasks/glitchtip-project-scope/checkpoint.md` обновлён
- exact next action: решение Владельца по merge, по ротации DSN и по `HEALTH_CHECK_TOKEN`

#### Open questions
- none

#### Expected owner response
Recommended response:
`Мержи; DSN перевыпущу и HEALTH_CHECK_TOKEN проверю сам`

Alternative responses, when relevant:
- `HEALTH_CHECK_TOKEN боевой — вычисти его тем же способом`
- `Оставь в Draft, посмотрю сам`
