<?php

declare(strict_types=1);

namespace App\Finance\Application\Action;

use App\Company\Facade\CompanyFacade;
use App\Finance\Entity\PLCategory;
use App\Finance\Exception\PLTemplateBindingException;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Finance\Repository\PLCategoryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Сопоставляет статьи компании со статьями шаблона ОПиУ её профиля: присваивает своим статьям
 * коды шаблона. После этого «Базовый шаблон ОПиУ» опознаёт их и дополняет дерево компании, а не
 * строит второе рядом.
 *
 * Сопоставляется только статья без кода того же типа и того же уровня (корень — с корнем шаблона,
 * вложенная — с вложенной: корень компании под кодом вложенной статьи остался бы корнем, и корень
 * шаблона над ним встал бы рядом вторым деревом); группу шаблона, которая суммирует потомков, —
 * только статья, которая тоже их суммирует. Код не должен быть занят. Любое нарушение отклоняет
 * всё сопоставление — коды присваиваются одной транзакцией.
 */
final readonly class BindPLCategoriesToTemplateAction
{
    public function __construct(
        private CompanyFacade $companies,
        private DefaultPLCategoryTreeProvider $templates,
        private PLCategoryRepository $categories,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $bindings код статьи шаблона → id статьи компании
     *
     * @return int сколько статей сопоставлено
     */
    public function __invoke(string $companyId, array $bindings): int
    {
        $company = $this->companies->findById($companyId)
            ?? throw new \DomainException(\sprintf('Компания %s не найдена.', $companyId));
        if ([] === $bindings) {
            return 0;
        }

        $templateNodes = [];
        foreach ($this->templates->nodes($company->getBusinessProfile()) as $node) {
            if (null !== $node->code) {
                $templateNodes[$node->code] = $node;
            }
        }
        $takenCodes = array_flip($this->categories->findCodesByCompany($company));

        /** @var list<array{PLCategory, string}> $plan */
        $plan = [];
        $seen = [];
        foreach ($bindings as $code => $categoryId) {
            $code = (string) $code;
            $node = $templateNodes[$code] ?? throw PLTemplateBindingException::unknownCode($code);
            $category = Uuid::isValid($categoryId) ? $this->categories->findOneBy(['id' => $categoryId, 'company' => $company]) : null;
            if (!$category instanceof PLCategory) {
                throw PLTemplateBindingException::categoryNotFound($categoryId);
            }
            if (null !== $category->getCode()) {
                throw PLTemplateBindingException::categoryHasCode($category->getName(), $category->getCode());
            }
            if ($category->getType() !== $node->type) {
                throw PLTemplateBindingException::typeMismatch($category->getName(), $code);
            }
            if ((null === $category->getParent()) !== (null === $node->parent)) {
                throw PLTemplateBindingException::levelMismatch($category->getName(), $code);
            }
            if ($node->type->sumsChildren($node->formula) && !$category->getType()->sumsChildren($category->getFormula())) {
                throw PLTemplateBindingException::notSumming($category->getName(), $code);
            }
            if (isset($takenCodes[$code])) {
                throw PLTemplateBindingException::codeTaken($code);
            }
            if (isset($seen[$categoryId])) {
                throw PLTemplateBindingException::categoryTwice($category->getName());
            }
            $seen[$categoryId] = true;
            $plan[] = [$category, $code];
        }

        // Проверка «без кода» выше — по прочитанному без блокировки: два одновременных сохранения
        // прошли бы её оба, и второе молча заменило бы код первого. Под блокировкой строки статья
        // перечитывается и проверяется заново; занятый за это время код ловит уникальный индекс.
        try {
            $this->em->wrapInTransaction(function () use ($plan): void {
                foreach ($plan as [$category, $code]) {
                    $this->em->refresh($category, LockMode::PESSIMISTIC_WRITE);
                    if (null !== $category->getCode()) {
                        throw PLTemplateBindingException::categoryHasCode($category->getName(), $category->getCode());
                    }
                    $category->setCode($code);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw PLTemplateBindingException::concurrentChange();
        }

        $this->logger->info('P&L categories bound to template', ['companyId' => $companyId, 'bound' => \count($plan)]);

        return \count($plan);
    }
}
