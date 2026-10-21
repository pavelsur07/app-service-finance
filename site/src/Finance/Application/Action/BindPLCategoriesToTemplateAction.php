<?php

declare(strict_types=1);

namespace App\Finance\Application\Action;

use App\Company\Facade\CompanyFacade;
use App\Finance\Entity\PLCategory;
use App\Finance\Exception\PLTemplateBindingException;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Finance\Repository\PLCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Сопоставляет статьи компании со статьями шаблона ОПиУ её профиля: присваивает своим статьям
 * коды шаблона. После этого «Базовый шаблон ОПиУ» опознаёт их и дополняет дерево компании, а не
 * строит второе рядом.
 *
 * Сопоставляется только статья без кода и того же типа, что статья шаблона; код не должен быть
 * занят. Любое нарушение отклоняет всё сопоставление — коды присваиваются одной транзакцией.
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

        $templateTypes = [];
        foreach ($this->templates->nodes($company->getBusinessProfile()) as $node) {
            if (null !== $node->code) {
                $templateTypes[$node->code] = $node->type;
            }
        }
        $takenCodes = array_flip($this->categories->findCodesByCompany($company));

        /** @var list<array{PLCategory, string}> $plan */
        $plan = [];
        $seen = [];
        foreach ($bindings as $code => $categoryId) {
            $code = (string) $code;
            $type = $templateTypes[$code] ?? throw PLTemplateBindingException::unknownCode($code);
            $category = Uuid::isValid($categoryId) ? $this->categories->findOneBy(['id' => $categoryId, 'company' => $company]) : null;
            if (!$category instanceof PLCategory) {
                throw PLTemplateBindingException::categoryNotFound($categoryId);
            }
            if (null !== $category->getCode()) {
                throw PLTemplateBindingException::categoryHasCode($category->getName(), $category->getCode());
            }
            if ($category->getType() !== $type) {
                throw PLTemplateBindingException::typeMismatch($category->getName(), $code);
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

        $this->em->wrapInTransaction(static function () use ($plan): void {
            foreach ($plan as [$category, $code]) {
                $category->setCode($code);
            }
        });

        $this->logger->info('P&L categories bound to template', ['companyId' => $companyId, 'bound' => \count($plan)]);

        return \count($plan);
    }
}
