# Stage 3: Перенос секретов и handoff

Risk: HIGH-LOCAL. Work items: 3.1, 3.2.
Review base:238dd997b27ecca5a1ae205fb417534e79de3439; integrated upstream base0b4206db373fa89cff0d67146641093bdafc8461.
Status: implementation and internal review complete; final checks tracked in handoff.

Добавлена company-scoped команда app:moysklad:encrypt-secrets. Dry-run по умолчанию; --execute шифрует и проверяет оба токена до очистки plaintext в одной транзакции на запись. Повторный запуск безопасен, batch100, диагностические сообщения без секретов.

Files: Application/Action/EncryptConnectionSecretsAction.php, Command/EncryptConnectionSecretsCommand.php, unit/integration tests, operations.md, review-findings.md, handoff.md.

Checks: backfill integration5/28 до diagnostic assertions PASS; atomic failure, company scope, idempotency covered. Final module and project gates recorded in handoff. Production actions не выполнялись.

Review: fresh internal+external integrated review round1 REVIEW_GREEN; diagnosticMINOR fixed without re-run. Release risk: irreversible down, backup needed, separate migration/deploy/backfill approval; wrapper limitations and invariant reconciliation documented in operations.md.

Delivery note: Stage scopes implemented concurrently and integrated in checkpoint7100b57b, followed by upstream merge and review fixes; separate pre-implementation stage_base commits for Stage2/3 were not recorded. This is a workflow deviation, not a claim of sequential Stage closure.
