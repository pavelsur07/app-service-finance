### Stage 2: правила и безопасная запись — DONE

Risk: HIGH-LOCAL. Stage base: `d66582791`. Work items: 2.1, 2.2.

Реализованы exact article/parent+size/code/WB alias/barcode union, сохранение архивных конкурентов и size ограничений, package guard, отдельные revision/source/target-bound подтверждения; текущие связи сохраняются. Версия identity_package_v2. Preview fingerprint включает title/barcodes/settings и полный candidate decision. Writer повторяет проверки в прежней CAS/idempotency boundary. Ручной повторный review текущего target не создаёт ревизию назначения.

Приёмка: policy 18 RED → 69 GREEN (187 assertions); preview rules 4 RED → GREEN; manual confirmation absent-method RED → GREEN, источник изменён → confirmation stale. Stale preview регрессии включают настройки, source/target name/barcodes; прежние clear/concurrent/replay тесты сохранены.
Module: `docker exec vf-mapping-quality-php php bin/phpunit tests/Unit/MoySklad tests/Integration/MoySklad` — 450 tests,1737 assertions,OK. Focused PHPStan MoySklad/tests — OK. Style с явным project config. Один обнаруженный регресс теста исторического migration-down (поздний FK) исправлен восстановлением исторической схемы только внутри откатываемой test transaction.
Internal: BLOCKER0/IMPORTANT0; исправлены test typing/list serialization; проверены tenant scopes, конфликт оснований, stale confirmation и replay после clear.
External: Claude CLI round1 — IMPORTANT1 (пробел теста same-target reconfirmation), MINOR5; все исправлены. Дополнен тест: свежий review той же revision, repeat без дублей, сохранение archived причины, manual no-op. Candidate fingerprint обязателен, UTC timestamp.
Internal дополнительный BLOCKER1: barcode INSERT/transfer мог пройти параллельно guarded writer. Две регрессии RED → GREEN: source FOR UPDATE + current barcode rows FOR SHARE. Round2 внешнего review запущен после исправленного BLOCKER.
После исправлений module: 452 tests/1754 assertions OK.

Round2: IMPORTANT3 (non-leaf conflict with sized variant; bounded source lock conflict; tests still targeted deleted forArticles), MINOR3. Все подтверждённые IMPORTANT исправлены: две policy регрессии RED→GREEN; busy writer RED→GREEN SQLSTATE55P03, timeout2s для любого SET; production forListings напрямую покрыт scoping/case/zeros/truncation100/51/null-empty/barcode, dead forArticles удалён. Targeted75 tests/219 assertions + bounded busy1 test/5 assertions OK.
При lock timeout/deadlock Action возвращает listing_busy409; batch пишет warning и при закрытом EM оставляет остаток NOT_PROCESSED. Часть совета «продолжать с закрытым EM» отклонена: это нарушает CLAUDE transaction contract и может потерять записи; пользователь повторяет неприменённые строки со свежим EM. Настройки Stage4 будут менять connection FOR UPDATE. Same-target no-op UI перечитывает актуальный Query после redirect (Stage4 contract).
External total:2 rounds, BLOCKER0 / IMPORTANT4 fixed without re-run; internal BLOCKER1 fixed (доказанные конкурентные тесты), IMPORTANT0. Повтор после IMPORTANT не требуется AGENTS§7.2. No open BLOCKER/IMPORTANT.

Final verification: Stage2 module456 tests/1770 assertions OK, focused PHPStan всех Stage2 PHP-файлов OK.
