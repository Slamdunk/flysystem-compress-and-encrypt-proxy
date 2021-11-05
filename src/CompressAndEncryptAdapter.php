<?php

declare(strict_types=1);

namespace SlamCompressAndEncryptProxy;

use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;

final class CompressAndEncryptAdapter implements FilesystemAdapter
{
    public const REMOTE_FILE_EXTENSION = '.gz.encrypted';
    private FilesystemAdapter $remoteAdapter;
    private string $key;

    public function __construct(
        FilesystemAdapter $remoteAdapter,
        string $key
    ) {
        $key = sodium_base642bin($key, SODIUM_BASE64_VARIANT_ORIGINAL);
        if (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES !== \strlen($key)) {
            throw new InvalidArgumentException(sprintf(
                'Provided key is not long exactly %s bytes. Consider using %s::generateKey() to get a strong one.',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
                __CLASS__
            ));
        }

        EncryptorStreamFilter::register();
        $this->remoteAdapter = $remoteAdapter;
        $this->key = $key;
    }

    public static function generateKey(): string
    {
        return sodium_bin2base64(
            sodium_crypto_secretstream_xchacha20poly1305_keygen(),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );
    }

    /**
     * {@inheritDoc}
     */
    public function fileExists(string $path): bool
    {
        return $this->remoteAdapter->fileExists($this->getRemotePath($path));
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $path, string $contents, Config $config): void
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
    public function writeStream(string $path, $contents, Config $config): void
    {
        $zlibFilter = stream_filter_append($contents, 'zlib.deflate');
        \assert(false !== $zlibFilter);
        EncryptorStreamFilter::appendEncryption($contents, $this->key);

        $this->remoteAdapter->writeStream($this->getRemotePath($path), $contents, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function read(string $path): string
    {
        return stream_get_contents($this->readStream($path));
    }

    /**
     * {@inheritDoc}
     */
    public function readStream(string $path)
    {
        $contents = $this->remoteAdapter->readStream($this->getRemotePath($path));

        EncryptorStreamFilter::appendDecryption($contents, $this->key);
        $zlibFilter = stream_filter_append($contents, 'zlib.inflate');
        \assert(false !== $zlibFilter);

        return $contents;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $path): void
    {
        $this->remoteAdapter->delete($this->getRemotePath($path));
    }

    /**
     * {@inheritDoc}
     */
    public function deleteDirectory(string $path): void
    {
        $this->remoteAdapter->deleteDirectory($path);
    }

    /**
     * {@inheritDoc}
     */
    public function createDirectory(string $path, Config $config): void
    {
        $this->remoteAdapter->createDirectory($path, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function setVisibility(string $path, string $visibility): void
    {
        $this->remoteAdapter->setVisibility($this->getRemotePath($path), $visibility);
    }

    /**
     * {@inheritDoc}
     */
    public function visibility(string $path): FileAttributes
    {
        return $this->remoteAdapter->visibility($this->getRemotePath($path));
    }

    /**
     * {@inheritDoc}
     */
    public function mimeType(string $path): FileAttributes
    {
        return $this->remoteAdapter->mimeType($this->getRemotePath($path));
    }

    /**
     * {@inheritDoc}
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->remoteAdapter->lastModified($this->getRemotePath($path));
    }

    /**
     * {@inheritDoc}
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->remoteAdapter->fileSize($this->getRemotePath($path));
    }

    /**
     * {@inheritDoc}
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $remoteList = $this->remoteAdapter->listContents($path, $deep);

        foreach ($remoteList as $content) {
            if ($content instanceof FileAttributes) {
                $content = new FileAttributes(
                    substr($content->path(), 0, -\strlen(self::REMOTE_FILE_EXTENSION)),
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
    public function move(string $source, string $destination, Config $config): void
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
    public function copy(string $source, string $destination, Config $config): void
    {
        $this->remoteAdapter->copy(
            $this->getRemotePath($source),
            $this->getRemotePath($destination),
            $config
        );
    }

    private function getRemotePath(string $path): string
    {
        return $path.self::REMOTE_FILE_EXTENSION;
    }
}
