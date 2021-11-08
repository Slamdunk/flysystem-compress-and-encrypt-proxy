<?php

declare(strict_types=1);

namespace SlamCompressAndEncryptProxy;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;

final class CompressAndEncryptAdapter implements FilesystemAdapter
{
    private FilesystemAdapter $adapter;

    public function __construct(
        FilesystemAdapter $adapter,
        string $key
    ) {
        $this->adapter = new CompressAdapter(
            new EncryptAdapter(
                $adapter,
                $key
            )
        );
    }

    public static function generateKey(): string
    {
        return EncryptAdapter::generateKey();
    }

    public static function getRemoteFileExtension(): string
    {
        return CompressAdapter::getRemoteFileExtension().EncryptAdapter::getRemoteFileExtension();
    }

    /**
     * {@inheritDoc}
     */
    public function fileExists(string $path): bool
    {
        return $this->adapter->fileExists($path);
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->adapter->write($path, $contents, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->adapter->writeStream($path, $contents, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function read(string $path): string
    {
        return $this->adapter->read($path);
    }

    /**
     * {@inheritDoc}
     */
    public function readStream(string $path)
    {
        return $this->adapter->readStream($path);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $path): void
    {
        $this->adapter->delete($path);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteDirectory(string $path): void
    {
        $this->adapter->deleteDirectory($path);
    }

    /**
     * {@inheritDoc}
     */
    public function createDirectory(string $path, Config $config): void
    {
        $this->adapter->createDirectory($path, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function setVisibility(string $path, string $visibility): void
    {
        $this->adapter->setVisibility($path, $visibility);
    }

    /**
     * {@inheritDoc}
     */
    public function visibility(string $path): FileAttributes
    {
        return $this->adapter->visibility($path);
    }

    /**
     * {@inheritDoc}
     */
    public function mimeType(string $path): FileAttributes
    {
        return $this->adapter->mimeType($path);
    }

    /**
     * {@inheritDoc}
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->adapter->lastModified($path);
    }

    /**
     * {@inheritDoc}
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->adapter->fileSize($path);
    }

    /**
     * {@inheritDoc}
     */
    public function listContents(string $path, bool $deep): iterable
    {
        return $this->adapter->listContents($path, $deep);
    }

    /**
     * {@inheritDoc}
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $this->adapter->move($source, $destination, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $this->adapter->copy($source, $destination, $config);
    }
}
