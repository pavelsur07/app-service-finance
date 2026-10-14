<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Yaml\Yaml;

/**
 * Каждое сообщение, у которого есть handler, прописано в `messenger.yaml` ровно
 * на одном существующем транспорте.
 *
 * Сообщение без routing Symfony выполняет синхронно в том же процессе, что его
 * отправил: внешний HTTP или тяжёлая обработка уходят в веб-запрос или cron,
 * без ретраев и без очереди `failed`. Ошибка тихая — так 14.10.2026 нашлось
 * `ScoreCompanyCounterpartiesMessage` (этап 4 плана Cash).
 */
final class MessengerRoutingTest extends TestCase
{
    /**
     * Handler'ы, которые через шину не отправляются: класс вызывается напрямую,
     * атрибут остался. Причина обязательна; устаревшее исключение — ошибка.
     */
    private const NOT_DISPATCHED = [
        'App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand' => 'ProcessMarketplaceRawDocumentAction вызывают напрямую ProcessRawDocumentStepMessageHandler и ReprocessMarketplacePeriodAction; в шину команда не отправляется, атрибут #[AsMessageHandler] рудиментарный.',
    ];

    public function testEveryHandledMessageIsRoutedToExactlyOneKnownTransport(): void
    {
        $config = $this->messengerConfig();
        // `failed` — failure transport: сообщение туда не маршрутизируют, оно
        // попадает туда после исчерпания ретраев.
        $transports = array_values(array_diff(array_keys($config['transports'] ?? []), [$config['failure_transport'] ?? 'failed']));
        $routing = $config['routing'] ?? [];
        $problems = [];

        foreach ($this->handledMessages() as $message => $handler) {
            if (isset(self::NOT_DISPATCHED[$message])) {
                continue;
            }

            if (!isset($routing[$message])) {
                $problems[] = sprintf('%s (handler %s) не прописано в routing — выполнится синхронно.', $message, $handler);

                continue;
            }

            $target = $routing[$message];
            if (\is_array($target) && isset($target['senders'])) {
                $target = $target['senders'];
            }
            $target = array_values((array) $target);
            if (1 !== count($target)) {
                $problems[] = sprintf('%s прописано на %d транспортов, нужен ровно один.', $message, count($target));

                continue;
            }

            if (!in_array($target[0], $transports, true)) {
                $problems[] = sprintf('%s прописано на неизвестный транспорт «%s».', $message, $target[0]);
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    public function testNotDispatchedExceptionsAreStillAccurate(): void
    {
        $handled = $this->handledMessages();
        $routing = $this->messengerConfig()['routing'] ?? [];

        foreach (self::NOT_DISPATCHED as $message => $reason) {
            self::assertArrayHasKey($message, $handled, sprintf('Исключение для %s устарело: handler больше нет.', $message));
            self::assertArrayNotHasKey($message, $routing, sprintf('Исключение для %s устарело: сообщение уже в routing.', $message));
            self::assertGreaterThan(20, mb_strlen($reason), sprintf('У исключения для %s нет причины.', $message));
            self::assertSame([], $this->filesDispatching($message), sprintf('Исключение для %s неверно: сообщение отправляется в шину.', $message));
        }
    }

    /**
     * Файлы src, где сообщение создаётся рядом с dispatch() — грубая, но
     * честная проверка утверждения «в шину не отправляется».
     *
     * @return list<string>
     */
    private function filesDispatching(string $message): array
    {
        $short = substr($message, (int) strrpos($message, '\\') + 1);
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src'));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (1 === preg_match('/dispatch\(\s*new\s+(?:\\\\?[\w\\\\]*\\\\)?'.preg_quote($short, '/').'\s*\(/', $source)) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function messengerConfig(): array
    {
        $config = Yaml::parseFile(dirname(__DIR__, 3).'/config/packages/messenger.yaml');
        self::assertIsArray($config);

        $messenger = $config['framework']['messenger'] ?? null;
        self::assertIsArray($messenger);

        return $messenger;
    }

    /**
     * @return array<string, string> FQCN сообщения => handler
     */
    private function handledMessages(): array
    {
        $handled = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src'));

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, 'AsMessageHandler')
                || 1 !== preg_match('/^namespace ([^;]+);/m', $source, $namespace)
                // Отступ допустим: dg/bypass-finals в тестах переписывает
                // `final readonly class` в `  class` при чтении файла.
                || 1 !== preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $source, $class)) {
                continue;
            }

            $className = $namespace[1].'\\'.$class[1];
            self::assertTrue(class_exists($className), sprintf('Класс %s не загружается.', $className));
            $reflection = new \ReflectionClass($className);

            foreach ($reflection->getAttributes(AsMessageHandler::class) as $attribute) {
                $arguments = $attribute->getArguments();
                $method = $reflection->getMethod($arguments['method'] ?? '__invoke');
                $handled[$arguments['handles'] ?? $this->firstParameterType($method)] = $className;
            }

            foreach ($reflection->getMethods() as $method) {
                foreach ($method->getAttributes(AsMessageHandler::class) as $attribute) {
                    $handled[$attribute->getArguments()['handles'] ?? $this->firstParameterType($method)] = $className.'::'.$method->getName();
                }
            }
        }

        self::assertNotEmpty($handled, 'Не найдено ни одного handler — сканер сломан.');

        return $handled;
    }

    private function firstParameterType(\ReflectionMethod $method): string
    {
        $parameter = $method->getParameters()[0] ?? null;
        self::assertNotNull($parameter, sprintf('%s::%s: у handler нет параметра-сообщения.', $method->class, $method->getName()));
        $type = $parameter->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type, sprintf('%s::%s: тип сообщения не определён.', $method->class, $method->getName()));

        return $type->getName();
    }
}
