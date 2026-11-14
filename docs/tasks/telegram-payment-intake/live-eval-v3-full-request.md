# Полная live-проверка v3 — подготовленный следующий запуск

Статус: РАЗРЕШЁН И ВЫПОЛНЕН 14 ноября. Ниже сохранён исходный объём запроса.
Факт:41case/41call, campaign122/163; повтор helper заблокирован исходным guard.
Израсходовано81,остался1; новый41case-run допускает до82generations с учётом
schema retry. Текущий результат10development cases:19/19кандидатов,18/18точных
сумм с известным масштабом,5/7направлений,10needs_review/0rejected. Полная
приёмка, unseen generalization, analytics/date accuracy этим не установлены.

Конкретный объём разрешения:

- Один полный run на тех же41обезличенных сообщениях, та же модель
  deepseek/deepseek-flash, тот же gateway и существующий ключ только в памяти.
- До82дополнительных вызовов, общий campaign cap82→163, никаких автоматических
  дополнительных запусков. Общий оценочный денежный лимит остаётся300RUB.
- Известная предыдущая usage estimate4.81949560RUB; резерв новых максимальных
  токенов261.8179968RUB; суммарная peak-rate оценка266.63749240RUB<300.
  Это не гарантия фактического provider invoice; тарифы/FX dated14November.
- Продлить исключение root-SSH только для этого одноразового read-only/nonroot
  контейнера, с теми же256MiB/CPU0.5/tmpfs/caps/secret-handling ограничениями.
  API/worker/сеть/секреты/CI/настройки и постоянные права не менять.
- После run убрать только собственный контейнер, сохранить metadata-only report
  отдельно и проверить существующие контейнеры read-only. Не выполнять repair.

Runtime sourcebd490a8fbd3ddd1d511046bae7c337e0fb958b34. Уже проверенный bundle
SHA202aad82c69189c11dcff036e4f38c07c2a461ba97b049ed3320c6cc6e3d96dd (66files),
без пересборки/изменения промпта. Prepared harnessSHA
ba81b257dcd48cfd79f82d2535b4e3297b5f803ea32be4046879730fda6fa284;
plan и helper находятся в ignored site/var/payment-intake-review/.
Default invocation проверен:plan_only,SSH0,modelcalls0. Лимит не изменён.

Точная команда только после именованного разрешения владельца:

```bash
python3 site/var/payment-intake-review/execute-proposed-prompt-v3-full-eval.py \
  --execute-approved-extended-trial
```

Before execute helper проверяет ledger81used/max82/remaining1/noactive, затем
фиксирует одобренное расширениеmax163 и активный run. Неизвестное число calls
после сбоя полностью списывает82, повтор без разбирательства запрещён. Pending
approval не считается согласием. No merge/deploy/pilot requested by this action.

Готовый ответ: «Разрешаю полный v3 eval: ещё до82вызовов, общий лимит300рублей,
тем же ключом, с тем же исключением для одноразового root-SSH eval».

## Фактическое выполнение

Owner «разрешаю» получено в ответ на этот подготовленный объём. Один полный
run исходным immutable helper:41case/41call,49/49annotated candidates,
47/47known-scale amounts,5/7directions,41needs_review/0rejected. Общая кампания
122/163,41remaining,usage estimate8.23956098RUB. Before/afterfullmetadataPASS,
evalcontainers0. Stage5/pilotpending из-за пропусков направлений, дополнительной
строкиtg008и неполногоqualitygold/end-to-end evidence. См.[полныйотчёт](live-eval-result-2026-11-14.md).
Этот документ не является разрешением на merge/deploy/pilot/production processing.
