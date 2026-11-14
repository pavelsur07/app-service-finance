# Stage 5 — синтетическая приёмка статей ДДС, проектов, ЦФО и дат

Исходные41сообщение имеют только частичныйgold, поэтому нельзя оценить выбор
аналитики и даты. Нужен отдельный воспроизводимый набор с явно заданными
вымышленными справочниками/ожиданиями. Это синтетическая проверка сопоставления,
не финансовая разметка реальных компаний и не доказательство реальной точности.

## Объём

12сценариев: точный выбор вA/B при одинаковых названиях и разныхID; одинаковые
названия двух статей/проектов/ЦФО; отсутствующие элементы; forbiddenpair;
несовместимость направления статьи; сегодня на границе суток вtimezone;
неподдерживаемая дата; два платежа с разными аналитиками; две даты в одной фразе.
Все суммы/имена/IDсинтетические, источники не вставляются в prompt.

В каждом fixture: безопасныеid/family, полныйPaymentInput, ожидаемые кандидаты
с literalanchor и всеми amount/currency/direction/execution_status/date/category/
project/CFOключами. Null означает ожидаемое воздержание, пропуск goldключа —
ошибка набора. Для forbiddenpair допустимы три безопасных tuple(проект,null),
(null,ЦФО),(null,null)+вопрос. Не выдумывать приоритет удаления стороны пары.
Индивидуальныеproject/CFOметрики в этом сценарии заменить отдельной метрикой
pairabstention, чтобы не выдавать произвольный вариант за единственный правильный.

Дополнить существующий runner встроенным --dataset source|semantic(defaultsource).
ПереиспользоватьPipeline,BoundedAdapter,privacy/route/budgetguards; отдельного
сетевого runner не создавать. Синтетическийhelperотвечает только за строгую
загрузку и scoring. Source41default и прежние частичные метрики не меняются.
Offline semanticmanifest: dataset,count,families,source/schema/prompt/runnerhashes,
metricsnull,network_calls0; не конструироватьадаптеры,не читатьключи.
Goldникогда не попадает в modelpayload; толькоfixture.payload.

Сопоставлять кандидаты1:1 по literalmonetaryanchor/occurrence, не по порядку/ref/
предсказанныманалитикам. Missingcandidate проваливает все соответствующие
знаменатели; extra/duplicateстроки понижаютcandidateprecision иexactcount.
Метрики — отдельные совпаденияcategory/project/CFO/date/direction/status/amount/
currency, nullabstention,pairabstention,requiredquestionpresence иexactrowtuple.
Вопросы оцениваются только на наличие; overallrealaccuracy/questionqualitynull.
Результат отклонённогоpipeline — отсутствующий проверенный ответ, не rawextract.

## Чек-лист

- [x] До кода написаны REDтестыloader/scorer; fixtureallkeys/anchors/ID/pairs/schema
 проверяются без сети.
- [x] Равные именаA/Bне смешиваютID; вариантыforbiddenpair не вводят новый приоритет.
- [x] Перестановкаответов не меняет score; swappingvalidanalytics обнаруживается.
- [x] Missingcandidate/duplicate/extra/missinggoldkey/expectednull проверены.
- [x] Offlineобаdatasetрежима имеют0calls,semanticgoldнеподмешиваетсявprompt.
- [x] Финальное независимое ревью без открытых B/I; проверки перечислены ниже.
- [x] Никакихlivecalls по новому набору без подготовленного согласованного объёма.

Делегируемый независимый участок: толькоsemanticJSONL/helperиегоunit tests.
Интеграция CLI и общиеcoreизменения остаются у основного агента; Git/коммиты/
production/modelcalls подагенту не делегируются.

## Локальные доказательства — 14 ноября 2026

До исправлений зафиксированы RED-тесты. После изменений: `make ai-service-test`
— 402 passed / 1 skipped; `make ai-service-evals` — 9 passed / 1 skipped;
`make ai-service-lint` — Ruff/format 97 files, mypy 57 files PASS.
Python skip связан с отсутствием site mount в стандартном контейнере;
eval skip — с отсутствием ключей. Живые вызовы этими проверками не выполнялись.
Cash/Telegram: 66 tests / 434 assertions PASS; canonical CS/strict и PHPStan
для двух изменённых PHP-тестов PASS. Один общий synthetic JSON проверяет
финансовые поля Python output и Cash consumer; Telegram покрывает отдельный
manual-paid/ack/Confirm сценарий. Живой Telegram сетью не проверялся.

Scorer отдельно отклоняет correction/no_payment как новые payments; RED→GREEN
для обоих наборов. Manifest фиксирует hashes pipeline/task/verifier/preparer
и semantic scorer. Исходные live-отчёты неизменны; raw outputs не сохранялись,
их повторный пересчёт и восстановление невозможны.

Финальный независимый focused review: B0/I0/M0. Предыдущие замечания
об alignment и неоднозначности справочников закрыты regression tests.
