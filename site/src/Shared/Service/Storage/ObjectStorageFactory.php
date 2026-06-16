<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

use Psr\Container\ContainerInterface;
use Webmozart\Assert\Assert;

final readonly class ObjectStorageFactory
{
    public function __construct(
        private ContainerInterface $storages,
        private string $driver,
    ) {
    }

    public function create(): ObjectStorageInterface
    {
        $driver = '' === trim($this->driver) ? 'local' : trim($this->driver);
        Assert::oneOf($driver, ['local', 's3']);

        /** @var ObjectStorageInterface $storage */
        $storage = $this->storages->get($driver);

        return $storage;
    }
}
