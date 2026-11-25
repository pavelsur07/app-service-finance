<?php

declare(strict_types=1);

namespace App\Cash\Http;

use App\Cash\Application\Report\IntragroupGroupAccess;
use App\Cash\Exception\IntragroupDecisionException;
use App\Company\Entity\User;
use App\Company\Facade\ConsolidationGroupFacadeInterface;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class CashflowGroupFormRequest
{
    public function __construct(
        private ActiveCompanyService $activeCompany,
        private ConsolidationGroupFacadeInterface $groups,
        private IntragroupGroupAccess $access,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /** @param \Closure(string, string, string): void $submit id активной компании, пользователя и головной компании из проверки CSRF */
    public function handle(Request $request, User $user, string $success, \Closure $submit): RedirectResponse
    {
        $activeId = (string) $this->activeCompany->getActiveCompany()->getId();
        $userId = (string) $user->getId();
        $head = $this->groups->headOf($activeId);
        if (null === $head) {
            throw new AccessDeniedException('Компания вне группы консолидации.');
        }
        $this->access->assertOwner($this->groups->members($head), $userId);
        if (!$this->csrf->isTokenValid(new CsrfToken('cashflow-group'.$head, $request->request->getString('_token')))) {
            throw new AccessDeniedException('Форма устарела. Обновите сверку.');
        }

        $period = array_filter(['from' => $request->query->getString('from'), 'to' => $request->query->getString('to')]);
        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);
        try {
            $submit($activeId, $userId, $head);
            $session->getFlashBag()->add('success', $success);
        } catch (IntragroupDecisionException $exception) {
            $session->getFlashBag()->add('danger', $exception->getMessage());
        }

        return new RedirectResponse($this->urls->generate('report_cashflow_group_reconciliation', $period), Response::HTTP_SEE_OTHER);
    }
}
