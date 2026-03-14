Полный путь к папкам:
site/src/Marketplace/Application/Inventory/
site/src/Marketplace/Command/Inventory/
site/src/Marketplace/Controller/Inventory/
site/src/Marketplace/Controller/Api/Inventory/
site/src/Marketplace/Domain/Inventory/
site/src/Marketplace/DTO/Inventory/
site/src/Marketplace/Entity/Inventory/
site/src/Marketplace/Enum/Inventory/
site/src/Marketplace/Facade/Inventory/
site/src/Marketplace/Infrastructure/Inventory/ - Query / Repository / Service
site/src/Marketplace/Message/Inventory/
site/src/Marketplace/MessageHandler/Inventory/

Важно: при разработке ничего не выдумывать и не предполагать. Если данных или контекста недостаточно, 
сначала запросить недостающую информацию.

Потоки данных (CQRS-light):

WRITE (изменение данных)
Controller → Command(DTO) → Application Action → Domain → Repo

READ (чтение данных)
Controller → Infrastructure/Query (DBAL) → DTO / array

Company Context — КРИТИЧНОЕ ПРАВИЛО
Единственный способ получить компанию
use App\Shared\Service\ActiveCompanyService;
ТОЛЬКО в Controller
$company = $this->companyService->getActiveCompany();

Где ЗАПРЕЩЕНО использовать ActiveCompanyService:
- Application/Action
- MessageHandler
- Worker
- CLI Command
- Domain
- Infrastructure/Query
Почему: В worker/CLI нет HTTP сессии → fatal error

В модулях не использовать прямую связь с сущностью Company.
Во всех командах, DTO и внутренних контрактах передавать компанию только как companyId типа string (UUID).
При необходимости получить объект Company используем App\Company\Facade\CompanyFacade

Пагинация:
Для пагинации использовать Pagerfanta.
В Twig подключать стандартный шаблон виджета: partials/_pagerfanta.html.twig.
Работа с компанией

Библиотеки используемые в проекте:
OpenSpout\Reader\XLS\Reader as XlsReader
OpenSpout\Reader\XLSX\Reader as XlsxReader
Ramsey\Uuid\Uuid

