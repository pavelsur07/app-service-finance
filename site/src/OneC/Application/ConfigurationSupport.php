<?php

declare(strict_types=1);

namespace App\OneC\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfigurationSupport
{
    public function __construct(
        #[Autowire('%onec.bp30_config_name%')] private string $configName,
        #[Autowire('%onec.minimum_config_version%')] private string $minimumConfigVersion,
        #[Autowire('%onec.minimum_processor_version%')] private string $minimumProcessorVersion,
    ) {
    }

    /** @return array{level: string, message: string} */
    public function evaluate(HandshakeSnapshot $snapshot): array
    {
        $v = $snapshot->versions;
        if ($v['config_name'] !== $this->configName || !preg_match('/^3\.0(?:\.|$)/', $v['config_version'])) {
            return ['level' => 'unsupported', 'message' => 'Поддерживается Бухгалтерия предприятия редакции 3.0.'];
        }
        if ('' !== $this->minimumProcessorVersion && version_compare($v['processor_version'], $this->minimumProcessorVersion, '<')) {
            return ['level' => 'unsupported', 'message' => 'Обновите обработку ВФ_Обмен.'];
        }
        if ('' !== $this->minimumConfigVersion && version_compare($v['config_version'], $this->minimumConfigVersion, '<')) {
            return ['level' => 'warning', 'message' => 'Рекомендуется обновить конфигурацию БП 3.0.'];
        }

        return ['level' => 'supported', 'message' => 'Поддерживаемая редакция БП 3.0.'];
    }
}
