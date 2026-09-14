# Stage 2: Tabler UI

Risk: MEDIUM. Work items: 2.1, 2.2.
Review base:238dd997b27ecca5a1ae205fb417534e79de3439; integrated upstream base0b4206db373fa89cff0d67146641093bdafc8461.
Status: implementation and internal review complete; final checks tracked in handoff.

В старом Twig/Tabler интерфейсе добавлено меню МойСклад → Интеграция, список подключений с пагинацией20, формы и модальные операции. Статус проверки отделён от включённости; неподтверждённый срок действия токена не выдумывается. Поля секретов всегда пусты. Submit spinner предотвращает повторную отправку, BFCache восстанавливает кнопки.

Files: site/templates/moy_sklad, templates/partials/_sidebar.html.twig, assets/legacy-app.js. Используется существующий Vite entry; новых entry/mount/API contracts нет. UI Kit/React не используется по прямому указанию владельца; checklist их компонентов неприменим.

Checks: Twig5templates PASS, frontend npm run lint и npm run build PASS в Node24 контейнере с frozen yarn.lock; первое предупреждение отсутствующей UX Turbo metadata устранено добавлением vendor/symfony/ux-turbo в изолированную сборочную копию; финальная сборка PASS5.34s, legacy entry11.16kB/gzip4.20kB. Browser Chromium1440/375:3cards, no horizontal overflow, empty token fields, modal open/Escape, spinner/duplicate prevention, no JS errors. Шесть снимков в /tmp/moysklad-browser-artifacts. Rendered authenticated fixture HTML использован для browser smoke; POST поведение отдельно проверено functional tests.

Review: internal UI/functional review, paginationIMPORTANT fixed. Общий внешний handoff review охватил templates. Shared mobile sidebar collapse — существующий FOLLOW-UP; не менялся. Размер legacy entry изменён минимальным submit handler; отдельное численное сравнение baseline bundle не выполнялось.

Delivery note: Stage scopes implemented concurrently and integrated in checkpoint7100b57b, followed by upstream merge and review fixes; separate pre-implementation stage_base commits for Stage2/3 were not recorded. This is a workflow deviation, not a claim of sequential Stage closure.

Дополнительные UI Kit guards: check:ui-kit на финальном дереве8958 нарушений, на base0b4206db8964; check:uikit-react-mapping47 на обоих. Они проверяют старые Tabler-шаблоны как UI Kit и устаревшие React mapping; не являются зелёными. UI Kit не входит в согласованный объём; guards не ослаблялись.
