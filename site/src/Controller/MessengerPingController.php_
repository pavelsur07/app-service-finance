<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\TestMessengerPing;
use App\Service\ActiveCompanyService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MessengerPingController extends AbstractController
{
    #[Route('/tools/messenger-ping', name: 'admin_messenger_ping', methods: ['GET','POST'])]
    public function ping(
        Request $request,
        ActiveCompanyService $companyCtx,
        MessageBusInterface $bus
    ): Response {
        if ($request->isMethod('POST')) {
            $id = bin2hex(random_bytes(8));
            $bus->dispatch(new TestMessengerPing($id, $companyCtx->getActiveCompany()->getId()));
            return $this->redirectToRoute('admin_messenger_ping', ['id' => $id]);
        }

        return $this->render('admin/tools/messenger_ping.html.twig', [
            'id' => $request->query->get('id'),
        ]);
    }
    #[Route('/tools/messenger-ping/status/{id}', name: 'admin_messenger_ping_status', methods: ['GET'])]
    public function status(string $id, CacheItemPoolInterface $cacheApp): JsonResponse
    {
        // тот же безопасный ключ
        $cacheKey = 'messenger_ping_' . preg_replace('/[{}()\/\\\\@:]/', '-', $id);

        $item = $cacheApp->getItem($cacheKey);

        return new JsonResponse([
            'found' => $item->isHit(),
            'data'  => $item->isHit() ? $item->get() : null,
        ]);
    }
}
