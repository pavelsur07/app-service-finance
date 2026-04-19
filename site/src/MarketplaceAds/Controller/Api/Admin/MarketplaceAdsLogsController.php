<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/marketplace-ads/admin/logs',
    name: 'marketplace_ads_admin_logs',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_OWNER')]
final class MarketplaceAdsLogsController extends AbstractController
{
    private const DEFAULT_LINES = 200;
    private const MAX_LINES = 1000;
    private const MIN_LINES = 1;
    private const SMALL_FILE_THRESHOLD_BYTES = 10 * 1024 * 1024;
    private const TAIL_CHUNK_SIZE = 8192;

    public function __construct(
        #[Autowire('%kernel.logs_dir%')]
        private readonly string $logsDir,
    ) {}

    public function __invoke(Request $request): Response
    {
        $lines = $this->resolveLines($request->query->get('lines'));
        $search = $this->resolveSearch($request->query->get('search'));

        $logFile = $this->findLatestLogFile();

        if (null === $logFile) {
            return $this->textResponse('No log file yet');
        }

        $tail = $this->readTail($logFile, $lines);

        if (null !== $search && '' !== $search) {
            $tail = array_values(array_filter(
                $tail,
                static fn (string $line): bool => str_contains($line, $search),
            ));
        }

        return $this->textResponse(implode("\n", $tail));
    }

    private function resolveLines(mixed $raw): int
    {
        if (null === $raw || '' === $raw) {
            return self::DEFAULT_LINES;
        }

        $value = (int) $raw;

        if ($value < self::MIN_LINES) {
            return self::MIN_LINES;
        }

        if ($value > self::MAX_LINES) {
            return self::MAX_LINES;
        }

        return $value;
    }

    private function resolveSearch(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        return '' === $trimmed ? null : $trimmed;
    }

    private function findLatestLogFile(): ?string
    {
        $pattern = rtrim($this->logsDir, '/') . '/marketplace_ads*.log';
        $matches = glob($pattern);

        if (false === $matches || [] === $matches) {
            return null;
        }

        $latest = null;
        $latestMtime = -1;
        foreach ($matches as $file) {
            if (!is_file($file)) {
                continue;
            }
            $mtime = filemtime($file);
            if (false === $mtime) {
                continue;
            }
            if ($mtime > $latestMtime) {
                $latestMtime = $mtime;
                $latest = $file;
            }
        }

        return $latest;
    }

    /**
     * @return list<string>
     */
    private function readTail(string $file, int $lines): array
    {
        $size = filesize($file);

        if (false === $size || 0 === $size) {
            return [];
        }

        if ($size <= self::SMALL_FILE_THRESHOLD_BYTES) {
            $all = file($file, FILE_IGNORE_NEW_LINES);
            if (false === $all) {
                return [];
            }

            return array_values(array_slice($all, -$lines));
        }

        return $this->readTailChunked($file, $lines);
    }

    /**
     * @return list<string>
     */
    private function readTailChunked(string $file, int $lines): array
    {
        $handle = fopen($file, 'rb');
        if (false === $handle) {
            return [];
        }

        try {
            fseek($handle, 0, SEEK_END);
            $position = ftell($handle);
            $buffer = '';
            $newlines = 0;

            while ($position > 0 && $newlines <= $lines) {
                $readSize = (int) min(self::TAIL_CHUNK_SIZE, $position);
                $position -= $readSize;
                fseek($handle, $position);
                $chunk = (string) fread($handle, $readSize);
                $buffer = $chunk . $buffer;
                $newlines = substr_count($buffer, "\n");
            }
        } finally {
            fclose($handle);
        }

        $all = explode("\n", $buffer);

        if ('' === end($all)) {
            array_pop($all);
        }

        return array_values(array_slice($all, -$lines));
    }

    private function textResponse(string $body): Response
    {
        return new Response(
            $body,
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; charset=utf-8'],
        );
    }
}
