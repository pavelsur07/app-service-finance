<?php

declare(strict_types=1);

namespace App\Company\Form;

use App\Company\Application\ProjectDirection\ProjectDirectionCopyPreview;
use App\Company\Entity\ProjectDirection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** @phpstan-import-type CopyDecision from ProjectDirectionCopyPreview */
final class ProjectDirectionCopyDecisions
{
    /** @return array<string, CopyDecision> */
    public static function read(Request $request): array
    {
        if ($request->request->has('editDecisions') && !$request->request->has('decisionsComplete')) {
            throw new BadRequestHttpException('Не все решения по кодам были получены.');
        }

        $submitted = $request->request->all('decisions');
        if ($request->request->has('decisionsJson')) {
            try {
                $submitted = json_decode($request->request->getString('decisionsJson'), true, 4, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new BadRequestHttpException('Некорректные решения по кодам проектов.', $e);
            }
            if (!\is_array($submitted)) {
                throw new BadRequestHttpException('Некорректные решения по кодам проектов.');
            }
        }

        $decisions = [];
        foreach ($submitted as $id => $input) {
            if (!\is_array($input) || !\is_string($id) || !\is_string($input['code'] ?? null)) {
                throw new BadRequestHttpException('Некорректное решение по коду проекта.');
            }

            $mode = $input['mode'] ?? null;
            if ('skip' === $mode) {
                continue;
            }
            if ('link' !== $mode && 'rename' !== $mode) {
                throw new BadRequestHttpException('Некорректное решение по коду проекта.');
            }
            $decisions[$id] = [
                'mode' => $mode,
                'code' => 'rename' === $mode ? (ProjectDirection::normalizeCode($input['code']) ?? '') : '',
            ];
        }
        ksort($decisions);

        return $decisions;
    }
}
