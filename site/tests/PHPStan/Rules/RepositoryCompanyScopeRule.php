<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rules;

use Doctrine\ORM\EntityRepository;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeCombinator;

/**
 * Метод-запрос tenant-scoped Repository обязан принимать идентификатор компании.
 *
 * `CLAUDE.md`, раздел «Безопасность — IDOR (критично)»: «Каждый Repository-метод
 * обязан принимать string $companyId», «$repo->find($id) без companyId —
 * запрещено». Там же сказано, что в ревью этот пункт проверяется первым, потому
 * что IDOR в проде — инцидент. Ревьюер может устать; правило — нет.
 *
 * Под правило попадают только репозитории, чья сущность принадлежит компании.
 * Первая редакция этого не проверяла и объявила долгом методы, у сущностей
 * которых поля компании нет вовсе: каталог тарифов Billing, справочники
 * внешних категорий, боты Telegram, сам `CompanyRepository`. Требовать от них
 * `companyId` значило бы пометить плохим состояние, из которого нет выхода,
 * а «починка» сломала бы аутентификацию и обходы воркеров.
 *
 * Принадлежность выводится из модели, а не из списка исключений. Сущность
 * ищется двумя способами, потому что в проекте сосуществуют два стиля:
 * docblock `@extends ServiceEntityRepository<Entity>` (41 репозиторий) и
 * только `parent::__construct($registry, Entity::class)` (59). Правило на узле
 * класса видит оба; редакция на узле метода видела лишь первый и потому
 * ошибалась на большинстве.
 *
 * Чего правило НЕ доказывает — названо прямо, чтобы его не принимали за
 * доказательство отсутствия IDOR:
 *
 * 1. Проверяется сигнатура, а не использование. Метод с параметром
 *    `string $companyId`, который его не использует, правило пропустит.
 * 2. Владение компанией прослеживается на один уровень. Сущность, принадлежащая
 *    компании через двух посредников, будет сочтена глобальной.
 * 3. Читающий метод определяется по префиксу имени. Имя вне списка — например
 *    `warmSplits()` — под правило не попадёт.
 * 4. Вызовы унаследованных от Doctrine `find()`, `findBy()`, `findOneBy()`,
 *    `findAll()`, `count()` в местах использования не проверяются вовсе:
 *    это отдельное call-site правило, вынесенное в FOLLOW-UP.
 *
 * Всё перечисленное — известные ограничения эвристики, а не недосмотр. Правило
 * ловит частый и дешёвый в исправлении случай; полноту дало бы только
 * fail-closed правило с явным исключением на каждый метод, и это отдельное
 * решение о цене, которое принимает Владелец.
 *
 * @implements Rule<InClassNode>
 */
final class RepositoryCompanyScopeRule implements Rule
{
    /**
     * Префиксы читающих методов. Метод, который ничего не выбирает, IDOR
     * создать не может. Список включает `is*`, `are*`, `max*`, `min*`,
     * `paginate*`, `list*`, `all*`, `total*` — это тоже запросы, и первая
     * редакция их пропускала.
     */
    private const QUERY_PREFIXES = [
        'all',
        'are',
        'count',
        'exists',
        'fetch',
        'find',
        'get',
        'has',
        'is',
        'list',
        'load',
        'max',
        'min',
        'paginate',
        'iterate',
        'search',
        'stream',
        'sum',
        'total',
    ];

    /**
     * Имена параметров, которые считаются идентификатором компании.
     * `$company` допускаем наравне с `$companyId`: часть репозиториев принимает
     * саму сущность, и это тоже ограничивает выборку.
     */
    private const COMPANY_PARAMETERS = [
        'companyId',
        'company',
    ];

    /**
     * Поля сущности, наличие которых означает принадлежность компании.
     * Только сущности: инференс по типу параметра-носителя удалён — см.
     * докблок класса и `parameterScopesQuery()`.
     */
    private const COMPANY_PROPERTIES = [
        'companyId',
        'company',
    ];

    /**
     * Явный отказ от правила для метода, который обязан ходить по всем
     * компаниям: агрегат по платформе, backfill из CLI, служебный обход.
     *
     * Форма: `@companyScopeExempt <причина>` в docblock метода. Причина
     * обязательна — без неё тег не считается. Такой отказ виден в диффе рядом
     * с кодом, а не спрятан в конфиге, и рецензент читает его вместе с методом.
     * Без этого механизма правило помечало бы плохим состояние, из которого
     * нет выхода, — ровно то, что CLAUDE.md называет дефектом гейта.
     */
    private const EXEMPT_TAG = '@companyScopeExempt';

    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        // InClassNode, а не Class_: на узле Class_ scope ещё не внутри класса,
        // и getClassReflection() возвращает null — правило молчит на всём.
        return InClassNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();
        if ($classReflection->isInterface()) {
            return [];
        }

        if (!$this->isRepository($classReflection->getName())) {
            return [];
        }

        $originalNode = $node->getOriginalNode();
        if (!$originalNode instanceof Class_) {
            return [];
        }

        if (!$this->isTenantScoped($classReflection, $originalNode, $scope)) {
            return [];
        }

        $errors = [];
        foreach ($originalNode->getMethods() as $method) {
            if (!$this->isUnscopedQueryMethod($method, $scope)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Метод-запрос %s::%s() не принимает $companyId — выборка не ограничена компанией (IDOR).',
                $classReflection->getName(),
                $method->name->toString(),
            ))
                ->identifier('vashfindir.repositoryCompanyScope')
                ->tip('Добавить параметр string $companyId и фильтр по нему в QueryBuilder.')
                ->line($method->getStartLine())
                ->build();
        }

        return $errors;
    }

    private function isUnscopedQueryMethod(ClassMethod $method, Scope $scope): bool
    {
        if (!$method->isPublic() || $method->isAbstract()) {
            return false;
        }

        if (!$this->isQueryMethod($method->name->toString())) {
            return false;
        }

        if ($this->isExempt($method)) {
            return false;
        }

        foreach ($method->params as $param) {
            if ($this->parameterScopesQuery($param, $scope)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ограничением считается ровно то, что требует CLAUDE.md: параметр
     * `string $companyId` — либо `$company` с объектным типом, потому что часть
     * репозиториев принимает саму сущность. В обоих случаях тип обязан быть
     * ненулевым: `?string $companyId = null` гарантии не даёт, и вызывающий код
     * волен не ограничивать выборку вовсе.
     *
     * Предикат намеренно узкий и неигровой. Прежние редакции пытались вывести
     * ограничение из типа параметра-носителя (DTO с полем компании), и каждая
     * такая догадка открывала новую дыру: nullable-поле, union-ветвь без
     * гарантии, intersection. Метод, который ограничивает выборку не прямым
     * параметром, объявляет это явно — тегом `@companyScopeExempt <причина>`,
     * который читается в ревью рядом с кодом.
     */
    private function parameterScopesQuery(Node\Param $param, Scope $scope): bool
    {
        if (!$param->var instanceof Node\Expr\Variable || !\is_string($param->var->name)) {
            return false;
        }

        $name = $param->var->name;
        if (!\in_array($name, self::COMPANY_PARAMETERS, true)) {
            return false;
        }

        if (null === $param->type) {
            return false;
        }

        $type = $scope->getFunctionType($param->type, false, false);
        if (TypeCombinator::containsNull($type)) {
            return false;
        }

        return 'companyId' === $name
            ? $type->isString()->yes()
            : $type->isObject()->yes();
    }

    /**
     * Отказ засчитывается, только если на строке тега осталась непустая
     * причина ПОСЛЕ удаления закрывающего `*​/`. Прежние шаблоны принимали
     * `@companyScopeExempt` без причины: сначала `\s` уводил на следующую
     * строку, затем `\S` совпадал со звёздочкой закрытия на той же строке.
     */
    private function isExempt(ClassMethod $method): bool
    {
        $docComment = $method->getDocComment();
        if (null === $docComment) {
            return false;
        }

        foreach (explode("\n", $docComment->getText()) as $line) {
            $line = ltrim($line);

            // Однострочная форма `/** @tag причина */` тоже допустима.
            if (str_starts_with($line, '/**')) {
                $line = ltrim(substr($line, 3));
            } elseif (str_starts_with($line, '*')) {
                $line = ltrim(substr($line, 1));
            } else {
                continue;
            }
            if (!str_starts_with($line, self::EXEMPT_TAG)) {
                continue;
            }

            $reason = substr($line, \strlen(self::EXEMPT_TAG));
            if ('' !== $reason && !str_starts_with($reason, ' ') && !str_starts_with($reason, "\t")) {
                continue;
            }

            $reason = trim(rtrim(trim($reason), '*/'));
            if ('' !== $reason) {
                return true;
            }
        }

        return false;
    }

    /**
     * Соглашение проекта: класс репозитория оканчивается на `Repository`.
     * Namespace не проверяем: два репозитория лежат вне `*\Repository\*`
     * (`App\Catalog\Infrastructure\ProductRepository`,
     * `App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository`),
     * и редакция, требовавшая namespace, их пропускала.
     */
    private function isRepository(string $className): bool
    {
        return str_starts_with($className, 'App\\')
            && str_ends_with($className, 'Repository');
    }

    /**
     * Если сущность определить не удалось, принадлежность считается
     * подтверждённой: при IDOR цена ложноположительного срабатывания — строка
     * в baseline, цена ложноотрицательного — инцидент.
     */
    private function isTenantScoped(ClassReflection $repository, Class_ $node, Scope $scope): bool
    {
        $entity = $this->entityFromTemplate($repository) ?? $this->entityFromConstructor($node, $scope);
        if (null === $entity) {
            return true;
        }

        return $this->ownsCompany($entity, 1);
    }

    /**
     * Владение компанией — своё поле либо поле владельца.
     *
     * `LoanPaymentSchedule` принадлежит `Loan`, а тот — компании;
     * `ReconciliationLog` принадлежит `ProcessingBatch`. Редакция, смотревшая
     * только на собственные поля сущности, считала такие репозитории
     * глобальными и пропускала их целиком.
     *
     * Глубина ограничена одним уровнем сознательно: дальше цепочка владения
     * перестаёт быть очевидной, а цена ошибки в обе стороны растёт. Более
     * глубокие цепочки остаются известным ограничением — см. докблок класса.
     */
    private function ownsCompany(ClassReflection $entity, int $depth): bool
    {
        foreach (self::COMPANY_PROPERTIES as $property) {
            if ($entity->hasProperty($property)) {
                return true;
            }
        }

        if ($depth <= 0) {
            return false;
        }

        foreach ($entity->getNativeReflection()->getProperties() as $property) {
            $type = $property->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $ownerName = $type->getName();
            if (!$this->reflectionProvider->hasClass($ownerName)) {
                continue;
            }

            if ($this->ownsCompany($this->reflectionProvider->getClass($ownerName), $depth - 1)) {
                return true;
            }
        }

        return false;
    }

    private function entityFromTemplate(ClassReflection $repository): ?ClassReflection
    {
        $ancestor = $repository->getAncestorWithClassName(EntityRepository::class);
        if (null === $ancestor) {
            return null;
        }

        $entityType = $ancestor->getActiveTemplateTypeMap()->getType('T');
        if (null === $entityType) {
            return null;
        }

        return $this->resolve($entityType->getObjectClassNames());
    }

    /**
     * `parent::__construct($registry, Entity::class)` — стиль MakerBundle,
     * которым написано большинство репозиториев проекта.
     */
    private function entityFromConstructor(Class_ $node, Scope $scope): ?ClassReflection
    {
        $constructor = $node->getMethod('__construct');
        if (null === $constructor || null === $constructor->stmts) {
            return null;
        }

        foreach ($constructor->stmts as $statement) {
            if (!$statement instanceof Expression || !$statement->expr instanceof StaticCall) {
                continue;
            }

            $call = $statement->expr;
            if (!$call->class instanceof Name || 'parent' !== $call->class->toLowerString()) {
                continue;
            }

            // Именно __construct: любой другой `parent::method(Foo::class)`
            // мог бы подсунуть посторонний класс и выключить правило.
            if (!$call->name instanceof Node\Identifier || '__construct' !== $call->name->toLowerString()) {
                continue;
            }

            foreach ($call->getArgs() as $arg) {
                if (!$arg->value instanceof ClassConstFetch || !$arg->value->class instanceof Name) {
                    continue;
                }

                $entity = $this->resolve([$scope->resolveName($arg->value->class)]);
                if (null !== $entity) {
                    return $entity;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $classNames
     */
    private function resolve(array $classNames): ?ClassReflection
    {
        if (1 !== \count($classNames) || !$this->reflectionProvider->hasClass($classNames[0])) {
            return null;
        }

        $reflection = $this->reflectionProvider->getClass($classNames[0]);

        return $reflection->isClass() ? $reflection : null;
    }

    private function isQueryMethod(string $methodName): bool
    {
        $lower = strtolower($methodName);

        foreach (self::QUERY_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
