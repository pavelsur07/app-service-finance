<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\MoySklad\Application\Action\ApplyAutomaticListingMappingsAction;
use App\MoySklad\Application\Command\ApplyAutomaticMappingsCommand;
use App\MoySklad\Enum\AutomaticMappingDecisionStatus;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Form\ApplyAutomaticMappingsType;
use App\MoySklad\Infrastructure\Cache\AutomaticMappingPreviewStorage;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
#[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
final class ApplyAutomaticMappingsController extends AbstractController
{
    #[Route('/moy-sklad/connections/{connectionId}/mappings/{marketplace}/automatic/{previewId}/apply', name: 'moysklad_automatic_mapping_apply', requirements: ['connectionId' => Requirement::UUID, 'previewId' => Requirement::UUID, 'marketplace' => 'ozon|wildberries'], methods: ['POST'])]
    public function __invoke(string $connectionId, string $marketplace, string $previewId, Request $request, ActiveCompanyService $activeCompany,
        MoySkladConnectionWriteRepository $connections, AutomaticMappingPreviewStorage $storage, ApplyAutomaticListingMappingsAction $action): Response
    {
        $connectionId = strtolower($connectionId);
        $previewId = strtolower($previewId);
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
        $actor = $this->getUser();
        $actorId = $actor instanceof User ? $actor->getId() : null;
        if (null === $actorId) {
            throw $this->createAccessDeniedException();
        }
        $values = $request->request->all();
        if (!is_string($values['_token'] ?? null) || !$this->isCsrfTokenValid(ApplyAutomaticMappingsType::csrfId($previewId), $values['_token'])) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $ids = $values['selectedIds'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 25 || [] === $ids) {
            throw new UnprocessableEntityHttpException('Выберите от1 до25 строк.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !Uuid::isValid($id)) {
                throw new UnprocessableEntityHttpException('Некорректный листинг.');
            }
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw new UnprocessableEntityHttpException('Листинги не должны повторяться.');
        }
        $session = $request->getSession();
        $session->start();
        try {
            $preview = $storage->get($session->getId(), $previewId, $companyId, $actorId, $connectionId, $marketplace);
            if (null === $preview) {
                throw new ListingMappingException('not_found', 'Предпросмотр недоступен. Сформируйте новый.');
            }
            $ready = [];
            foreach ($preview->items as $item) {
                if (AutomaticMappingDecisionStatus::READY === $item->decision->status) {
                    $ready[$item->listingId] = $item->source->sku;
                }
            }
            $form = $this->createForm(ApplyAutomaticMappingsType::class, null, ['listings' => $ready]);
            $form->submit($values);
            if (!$form->isValid()) {
                throw new UnprocessableEntityHttpException('Выберите доступные строки из предпросмотра.');
            }
            $result = $action(new ApplyAutomaticMappingsCommand($companyId, $actorId, $connectionId, $marketplace, $preview, $ids));
            $attemptId = Uuid::uuid7()->toString();
            try {
                $storage->putResult($session->getId(), $previewId, $attemptId, $result);
            } catch (ListingMappingException) {
                // Some rows have committed. Do not render/query the possibly closed EM.
                return new Response('Не удалось сохранить результат попытки. Некоторые связи могли сохраниться. Проверьте историю или повторите запрос.', 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }
        } catch (ListingMappingException $error) {
            throw new HttpException($error->getStatusCode(), $error->getMessage());
        }

        return $this->redirectToRoute('moysklad_automatic_mapping_show', ['connectionId' => $connectionId, 'marketplace' => $marketplace, 'previewId' => $previewId, 'attempt' => $attemptId], 303);
    }
}
