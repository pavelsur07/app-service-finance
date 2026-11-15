<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\MoySklad\Application\DTO\AutomaticMappingPreview;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Form\AutomaticMappingsPreviewType;
use App\MoySklad\Infrastructure\Cache\AutomaticMappingPreviewStorage;
use App\MoySklad\Infrastructure\Query\AutomaticMappingPreviewQuery;
use App\MoySklad\Infrastructure\Query\MappingCharacteristicsQuery;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class PreviewAutomaticMappingsController extends AbstractController
{
    #[Route('/moy-sklad/connections/{connectionId}/mappings/{marketplace}/automatic/preview', name: 'moysklad_automatic_mapping_preview', requirements: ['connectionId' => Requirement::UUID, 'marketplace' => 'ozon|wildberries'], methods: ['POST'])]
    public function __invoke(string $connectionId, string $marketplace, Request $request, ActiveCompanyService $activeCompany,
        MoySkladConnectionWriteRepository $connections, MappingCharacteristicsQuery $characteristics, AutomaticMappingPreviewQuery $query,
        AutomaticMappingPreviewStorage $storage, ClockInterface $clock): Response
    {
        $connectionId = strtolower($connectionId);
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
        $actor = $this->getUser();
        $actorId = $actor instanceof User ? $actor->getId() : null;
        if (null === $actorId) {
            throw $this->createAccessDeniedException();
        }
        $context = $request->query->all();
        $charQ = $context['char_q'] ?? '';
        $selected = $context['selected'] ?? '';
        $charPage = filter_var($context['char_page'] ?? 1, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if (!is_string($charQ) || mb_strlen($charQ) > 100 || !is_string($selected) || mb_strlen($selected) > 255 || false === $charPage) {
            throw new UnprocessableEntityHttpException('Некорректный поиск характеристики.');
        }
        $selected = '' === $selected ? null : $selected;
        $values = $request->request->all();
        $csrfId = AutomaticMappingsPreviewType::csrfId($connectionId, $marketplace, $charQ, $charPage, $selected);
        if (!is_string($values['_token'] ?? null) || !$this->isCsrfTokenValid($csrfId, $values['_token'])) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        foreach (['_token', 'q', 'page', 'sizeCharacteristicId'] as $field) {
            if (isset($values[$field]) && !is_string($values[$field])) {
                throw new UnprocessableEntityHttpException('Некорректные поля предпросмотра.');
            }
        }
        $items = $characteristics->page($companyId, $connectionId, $charQ, $charPage)->items;
        if (null !== $selected) {
            $current = $characteristics->find($companyId, $connectionId, $selected) ?? throw $this->createNotFoundException();
            if (!in_array($selected, array_column($items, 'id'), true)) {
                $items[] = $current;
            }
        }
        $value = $values['sizeCharacteristicId'] ?? '';
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException('Некорректная характеристика.');
        }
        if ('' !== $value && null === $characteristics->find($companyId, $connectionId, $value)) {
            throw $this->createNotFoundException();
        }
        $form = $this->createForm(AutomaticMappingsPreviewType::class, null, ['characteristics' => $items]);
        $form->submit($values);
        if (!$form->isValid()) {
            throw new UnprocessableEntityHttpException('Проверьте параметры и выберите характеристику из показанного списка.');
        }
        $data = $form->getData() ?? [];
        try {
            $page = $query->page($companyId, $connectionId, $marketplace, $data['q'] ?? '', (int) ($data['page'] ?? 1), $data['sizeCharacteristicId'] ?? null);
            $preview = AutomaticMappingPreview::fromPage($companyId, $actorId, $connectionId, $marketplace, $page, $clock->now());
            $session = $request->getSession();
            $session->start();
            $storage->put($session->getId(), $preview);
        } catch (ListingMappingException $error) {
            throw new HttpException($error->getStatusCode(), $error->getMessage());
        }

        return $this->redirectToRoute('moysklad_automatic_mapping_show', ['connectionId' => $connectionId, 'marketplace' => $marketplace, 'previewId' => $preview->id], 303);
    }
}
