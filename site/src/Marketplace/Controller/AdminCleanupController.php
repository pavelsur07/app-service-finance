<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ⚠️ ОДНОРАЗОВЫЙ КОНТРОЛЛЕР — удалить после использования.
 * Очистка данных маркетплейса: продажи, возвраты, затраты, листинги, сырые документы.
 * Сохраняет подключения, сбрасывает их статус синхронизации.
 */
#[Route('/admin/marketplace-cleanup')]
#[IsGranted('ROLE_ADMIN')]
final class AdminCleanupController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[Route('', name: 'admin_marketplace_cleanup_preview', methods: ['GET'])]
    public function preview(): Response
    {
        $counts = [
            'marketplace_sales'           => $this->count('marketplace_sales'),
            'marketplace_returns'         => $this->count('marketplace_returns'),
            'marketplace_costs'           => $this->count('marketplace_costs'),
            'marketplace_raw_documents'   => $this->count('marketplace_raw_documents'),
            'marketplace_barcode_catalog' => $this->count('marketplace_barcode_catalog'),
            'marketplace_listings_total'  => $this->count('marketplace_listings'),
            'marketplace_listings_mapped' => (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM marketplace_listings WHERE product_id IS NOT NULL'
            ),
        ];

        $mappedListings = $this->connection->fetchAllAssociative(
            'SELECT id, marketplace, marketplace_sku, name FROM marketplace_listings WHERE product_id IS NOT NULL'
        );

        $connections = $this->connection->fetchAllAssociative(
            'SELECT id, marketplace, last_sync_at FROM marketplace_connections'
        );

        $html = '<style>
            body { font-family: monospace; padding: 20px; }
            table { border-collapse: collapse; margin-bottom: 20px; }
            td, th { border: 1px solid #ccc; padding: 6px 12px; }
            th { background: #f0f0f0; }
            .warn { color: #c00; font-weight: bold; }
            .ok { color: #060; }
            .btn { display: inline-block; padding: 10px 20px; background: #c00; color: #fff;
                   text-decoration: none; border-radius: 4px; margin-top: 20px; font-size: 16px; }
        </style>';

        $html .= '<h2>⚠️ Предпросмотр очистки маркетплейса</h2>';
        $html .= '<p class="warn">Будет удалено:</p>';
        $html .= '<table><tr><th>Таблица</th><th>Записей</th></tr>';
        foreach ($counts as $table => $count) {
            $class = $count > 0 ? 'warn' : 'ok';
            $html .= "<tr><td>{$table}</td><td class='{$class}'>{$count}</td></tr>";
        }
        $html .= '</table>';

        $html .= '<p><strong>Привязанные листинги (потребуют повторного маппинга):</strong></p>';
        $html .= '<table><tr><th>ID</th><th>Маркетплейс</th><th>SKU</th><th>Название</th></tr>';
        foreach ($mappedListings as $row) {
            $html .= "<tr><td>{$row['id']}</td><td>{$row['marketplace']}</td><td>{$row['marketplace_sku']}</td><td>{$row['name']}</td></tr>";
        }
        $html .= '</table>';

        $html .= '<p><strong>Подключения (статус сбросится, подключения сохранятся):</strong></p>';
        $html .= '<table><tr><th>ID</th><th>Маркетплейс</th><th>last_sync_at</th></tr>';
        foreach ($connections as $row) {
            $html .= "<tr><td>{$row['id']}</td><td>{$row['marketplace']}</td><td>{$row['last_sync_at']}</td></tr>";
        }
        $html .= '</table>';

        $html .= '<a href="/admin/marketplace-cleanup/run" class="btn"
                     onclick="return confirm(\'Точно выполнить очистку? Это необратимо.\')">
                     🗑 Выполнить очистку
                  </a>';

        return new Response($html);
    }

    #[Route('/run', name: 'admin_marketplace_cleanup_run', methods: ['GET'])]
    public function run(): Response
    {
        $this->connection->beginTransaction();

        try {
            // Удаляем в правильном порядке (FK)
            $deleted = [];
            $deleted['marketplace_sales']           = $this->connection->executeStatement('DELETE FROM marketplace_sales');
            $deleted['marketplace_returns']         = $this->connection->executeStatement('DELETE FROM marketplace_returns');
            $deleted['marketplace_costs']           = $this->connection->executeStatement('DELETE FROM marketplace_costs');
            $deleted['marketplace_raw_documents']   = $this->connection->executeStatement('DELETE FROM marketplace_raw_documents');
            $deleted['marketplace_barcode_catalog'] = $this->connection->executeStatement('DELETE FROM marketplace_barcode_catalog');
            $deleted['marketplace_listings']        = $this->connection->executeStatement('DELETE FROM marketplace_listings');

            // Сбрасываем статус подключений — они останутся, но запустят повторную загрузку
            $this->connection->executeStatement(
                'UPDATE marketplace_connections
                 SET last_sync_at = NULL, last_successful_sync_at = NULL, last_sync_error = NULL'
            );

            $this->connection->commit();

            $html = '<style>body { font-family: monospace; padding: 20px; } .ok { color: #060; }</style>';
            $html .= '<h2 class="ok">✅ Очистка выполнена успешно</h2>';
            $html .= '<table border="1" cellpadding="6"><tr><th>Таблица</th><th>Удалено записей</th></tr>';
            foreach ($deleted as $table => $count) {
                $html .= "<tr><td>{$table}</td><td>{$count}</td></tr>";
            }
            $html .= '</table>';
            $html .= '<p>Подключения сохранены, статус синхронизации сброшен.</p>';
            $html .= '<p><strong>Следующие шаги:</strong></p>';
            $html .= '<ol>
                <li>Удали этот контроллер (<code>AdminCleanupController.php</code>)</li>
                <li>Перейди в Интеграции и нажми "Синхронизировать" для каждого подключения</li>
                <li>Или дождись автоматического крона</li>
                <li>Восстанови маппинг листингов вручную</li>
              </ol>';
            $html .= '<a href="/marketplace">→ Перейти к маркетплейсам</a>';

            return new Response($html);
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            return new Response(
                '<style>body{font-family:monospace;padding:20px;}.err{color:#c00}</style>'
                . '<h2 class="err">❌ Ошибка — транзакция откатана</h2>'
                . '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>',
                500,
            );
        }
    }

    private function count(string $table): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$table}");
    }
}
