<?php

declare(strict_types=1);

namespace SlamCompressAndEncryptProxy\Core;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;

/**
 * @internal
 */
abstract class AbstractProxyAdapter implements FilesystemAdapter
{
    public function __construct(
        private FilesystemAdapter $remoteAdapter
    ) {
    }

    abstract public static function getRemoteFileExtension(): string;

    /**
     * {@inheritDoc}
     */
    final public function fileExists(string $path): bool
    {
        return $this->remoteAdapter->fileExists($this->getRemotePath($path));
    }

    /**
     * {@inheritDoc}
     */
    final public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $contents);
        rewind($stream);

        $this->writeStream($path, $stream, $config);

        fclose($stream);
    }

    /**
     * {@inheritDoc}
     */
    final public function read(string $path): string
    {
        $stream = $this->readStream($path);
        $contents = stream_get_contents($this->readStream($path));
        fclose($stream);

        return $contents;
    }

    /**
     * {@inheritDoc}
     */
    final public function delete(string $path): void
    {
        $this->remoteAdapter->delete($this->getRemotePath($path));
    }

    /**
     * {@inheritDoc}
     */
    final public function deleteDirectory(string $path): void
    {
        $this->remoteAdapter->deleteDirectory($path);
    }

    /**
     * {@inheritDoc}
     */
    final public function createDirectory(string $path, Config $config): void
    {
        $this->remoteAdapter->createDirectory($path, $config);
    }

    /**
     * {@inheritDoc}
     */
    final public function setVisibility(string $path, string $visibility): void
    {
        $this->remoteAdapter->setVisibility($this->getRemotePath($path), $visibility);
    }

    /**
     * {@inheritDoc}
     */
    final public function visibility(string $path): FileAttributes
    {
        return $this->remoteAdapter->visibility($this->getRemotePath($path));
    }

    /**
     * {@inheritDoc}
     */
    final public function mimeType(string $path): FileAttributes
    {
        return $this->remoteAdapter->mimeType($this->getRemotePath($path));
    }

    /**
     * {@inheritDoc}
     */
    final public function lastModified(string $path): FileAttributes
    {
        return $this->remoteAdapter->lastModified($this->getRemotePath($path));
    }

    /**
     * {@inheritDoc}
     */
    final public function fileSize(string $path): FileAttributes
    {
        return $this->remoteAdapter->fileSize($this->getRemotePath($path));
    }

    /**
     * {@inheritDoc}
     */
    final public function listContents(string $path, bool $deep): iterable
    {
        $remoteList = $this->remoteAdapter->listContents($path, $deep);

        foreach ($remoteList as $content) {
            if ($content instanceof FileAttributes) {
                if (!str_ends_with($content->path(), static::getRemoteFileExtension())) {
                    continue;
                }

                $content = new FileAttributes(
                    substr($content->path(), 0, -\strlen(static::getRemoteFileExtension())),
                    $content->fileSize(),
                    $content->visibility(),
                    $content->lastModified(),
                    $content->mimeType(),
                    $content->extraMetadata()
                );
            }

            yield $content;
        }
    }

    /**
     * {@inheritDoc}
     */
    final public function move(string $source, string $destination, Config $config): void
    {
        $this->remoteAdapter->move(
            $this->getRemotePath($source),
            $this->getRemotePath($destination),
            $config
        );
    }

    /**
     * {@inheritDoc}
     */
    final public function copy(string $source, string $destination, Config $config): void
    {
        $this->remoteAdapter->copy(
            $this->getRemotePath($source),
            $this->getRemotePath($destination),
            $config
        );
    }

    final protected function getRemoteAdapter(): FilesystemAdapter
    {
        return $this->remoteAdapter;
    }

    final protected function getRemotePath(string $path): string
    {
        return $path.static::getRemoteFileExtension();
    }
}
