<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Webmozart\Assert\Assert;

final class FlysystemS3ObjectStorage implements ObjectStorageInterface
{
    private Filesystem $filesystem;

    public function __construct(
        string $bucket,
        string $region,
        string $endpoint = '',
        string $accessKey = '',
        string $secretKey = '',
        string $pathStyleEndpoint = '0',
    ) {
        Assert::notEmpty($bucket);
        Assert::notEmpty($region);

        $clientConfig = [
            'version' => 'latest',
            'region' => $region,
            'http' => [
                // Не декодировать Content-Encoding: храним уже сжатые байты (.ndjson.gz)
                // как opaque-payload и разжимаем сами. Иначе curl падает с error 61.
                'decode_content' => false,
                // Таймауты — чтобы блип/зависание timeweb не вешало синхронную команду
                // (и не съедало ресурсы контейнера). Fail-fast вместо бесконечного ожидания.
                'connect_timeout' => 5,
                'timeout' => 30,
            ],
            // Авто-ретрай транзиентных сбоев (500/502/503/504 + таймауты/сеть),
            // иначе одиночный блип timeweb = ошибка команды.
            'retries' => [
                'mode' => 'standard',
                'max_attempts' => 5,
            ],
        ];

        if ('' !== $endpoint) {
            $clientConfig['endpoint'] = $endpoint;
        }

        if ('' !== $accessKey || '' !== $secretKey) {
            $clientConfig['credentials'] = [
                'key' => $accessKey,
                'secret' => $secretKey,
            ];
        }

        if (in_array(strtolower($pathStyleEndpoint), ['1', 'true', 'yes'], true)) {
            $clientConfig['use_path_style_endpoint'] = true;
        }

        $this->filesystem = new Filesystem(new AwsS3V3Adapter(new S3Client($clientConfig), $bucket));
    }

    public function write(string $path, string $contents): StoredObject
    {
        try {
            $this->filesystem->write($path, $contents);
        } catch (FilesystemException $exception) {
            throw new ObjectStorageException(sprintf('Failed to write object "%s".', $path), 0, $exception);
        }

        return new StoredObject($path, strlen($contents));
    }

    public function read(string $path): string
    {
        try {
            return $this->filesystem->read($path);
        } catch (FilesystemException $exception) {
            throw new ObjectStorageException(sprintf('Failed to read object "%s".', $path), 0, $exception);
        }
    }

    public function readStream(string $path)
    {
        try {
            return $this->filesystem->readStream($path);
        } catch (FilesystemException $exception) {
            throw new ObjectStorageException(sprintf('Failed to read object "%s".', $path), 0, $exception);
        }
    }

    public function exists(string $path): bool
    {
        try {
            return $this->filesystem->fileExists($path);
        } catch (FilesystemException $exception) {
            throw new ObjectStorageException(sprintf('Failed to check object "%s".', $path), 0, $exception);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->filesystem->delete($path);
        } catch (FilesystemException $exception) {
            throw new ObjectStorageException(sprintf('Failed to delete object "%s".', $path), 0, $exception);
        }
    }
}
