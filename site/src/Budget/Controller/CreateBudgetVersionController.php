<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\CreateBudgetVersionAction;
use App\Budget\Application\CreateBudgetVersionFromFactAction;
use App\Budget\Application\DTO\CreateBudgetVersionCommand;
use App\Budget\Application\DTO\CreateBudgetVersionFromFactCommand;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Создать вариант: пустой на год, копию варианта или из факта прошлого года (`method` =
 * empty | copy | fact). Скрытый `versionId` — ключ повтора. POST /finance/budget/versions.
 *
 * Ошибка ввода не теряет форму: введённое и текст ошибки кладутся в сессию
 * ({@see self::FORM_STATE}), список открывает окно «Новый вариант» снова.
 */
#[Route('/finance/budget/versions', name: 'budget_version_create', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class CreateBudgetVersionController extends AbstractController
{
    public const string FORM_STATE = 'budget_new_form';
    private const array METHODS = ['empty', 'copy', 'fact'];

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CreateBudgetVersionAction $createVersion,
        private readonly CreateBudgetVersionFromFactAction $createFromFact,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $year = $request->request->getInt('year');
        $back = $this->redirectToRoute('budget_index', ['year' => $year]);
        $versionId = (string) $request->request->get('versionId');
        if (!$this->isCsrfTokenValid('budget_version_create', (string) $request->request->get('_token')) || !Uuid::isValid($versionId)) {
            $this->addFlash('danger', 'Форма устарела — обновите страницу.');

            return $back;
        }

        $name = (string) $request->request->get('name');
        $copyFrom = trim((string) $request->request->get('copyFrom'));
        $method = (string) $request->request->get('method', '' === $copyFrom ? 'empty' : 'copy');
        if (!\in_array($method, self::METHODS, true)) {
            $method = 'empty';
        }

        try {
            if ('fact' === $method) {
                $result = ($this->createFromFact)(new CreateBudgetVersionFromFactCommand($versionId, $companyId, $name, $year));
                if ($result->created) {
                    $this->addFlash('success', 0 === $result->cells
                        ? sprintf('Вариант создан. Факта ОПиУ за %d год в парах «проект × ЦФО» нет — план пустой.', $year - 1)
                        : sprintf('Вариант создан из факта %d года: заполнено %d %s в %d %s.', $year - 1, $result->cells, self::plural($result->cells, 'ячейка', 'ячейки', 'ячеек'), $result->pairs, self::plural($result->pairs, 'паре', 'парах', 'парах')));
                }
                $id = $result->versionId;
            } else {
                $id = ($this->createVersion)(new CreateBudgetVersionCommand(
                    $versionId,
                    $companyId,
                    $name,
                    year: 'copy' === $method ? null : $year,
                    copyFromVersionId: 'copy' === $method ? ('' === $copyFrom ? 'missing' : $copyFrom) : null,
                ));
            }
        } catch (InvalidBudgetInputException|BudgetVersionNotFoundException $exception) {
            $request->getSession()->set(self::FORM_STATE, [
                'name' => $name,
                'method' => $method,
                'copyFrom' => $copyFrom,
                'error' => $exception instanceof BudgetVersionNotFoundException ? 'Вариант-источник не найден.' : $exception->getMessage(),
                'field' => match (true) {
                    $exception instanceof BudgetVersionNotFoundException => 'copyFrom',
                    str_contains($exception->getMessage(), 'названи') => 'name',
                    default => 'form',
                },
            ]);

            return $back;
        }

        return $this->redirectToRoute('budget_version_show', ['id' => $id]);
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        $mod10 = $n % 10;

        return match (true) {
            $mod100 >= 11 && $mod100 <= 14 => $many,
            1 === $mod10 => $one,
            $mod10 >= 2 && $mod10 <= 4 => $few,
            default => $many,
        };
    }
}
