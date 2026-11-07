### Stage 1: оболочка и UI-компоненты ДДС — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `03e7ad64bc794c1739781f4e431076e31bb7e60e`
**Work items:** 1.1,1.2

- [x] Та же base/legacy оболочка, sidebar, global flash/billing notice, card-header tabs.
- [x] Период from/to, контрагент/limit, chips/reset без page; route id имеет приоритет в pagination.
- [x] Shared picker через alias, optional URL override; существующий native select/choices сохранён.
- [x] Scoped CSS/guarded JS; новых Vite entries/API/dependencies нет.

**Checks:** новые4теста сначала RED (другая оболочка, отсутствующие controls, подмена id пагинации, native-only picker), затем PASS28assertions. Модуль Settlement+existing picker:53tests/250assertions PASS. Twig15files PASS; focused PHPStan PASS; changed test CS fixer PASS; frontend lint/build/67unit tests PASS.

**Browser:** настоящий локальный test HTTP с синтетическими fixtures, собранными assets/Inter/logo; period dropdown/focus/Escape, enhanced picker visible; pageerror0. Before snapshots4Settlement screens×4viewports в ignored site/var/settlement-ui-evidence; DDS reference снят отдельно по реальному route.

**Internal review:** 1iteration; BLOCKER0/IMPORTANT1 found+fixed: сохранён role=alert на ошибках Bootstrap формы. MINOR fixed: заголовок/lead отдельными элементами, filter alignment, targeted h1 selector нового layout. Старый Journey selector ограничен page root, финансовые assertions не изменены. Open0.

**External:** not required at this Stage: полный Stage diff с новыми файлами меньше500lines; required at handoff.

**Bundle:** baseline legacy JS45.55kB/CSS31.42kB; Stage1 рост≈0.5kB JS/1.7kB CSS, только local presentation. Проверки всех экранов/визуальная полировка продолжаются Stage2/3.

**Next:** Stage2 автоматически, без production действий.
