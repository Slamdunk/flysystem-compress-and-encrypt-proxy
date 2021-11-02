<?php

declare(strict_types=1);

namespace SlamFlysystemSingleEncryptedZipArchive;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use ZipArchive;

final class SingleEncryptedZipArchiveAdapter implements FilesystemAdapter
{
    public function __construct(
        private FilesystemAdapter $remoteAdapter,
        private string $password,
        private string $localWorkingDirectory
    ) {
        if (!is_dir($localWorkingDirectory) || !is_writable($localWorkingDirectory)) {
            throw new UnableToWriteToDirectoryException("{$localWorkingDirectory} is not writable");
        }
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
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
        $localZipPath = $this->writeToLocalZip($path, $contents);

        $this->remoteAdapter->write($this->getRemotePath($path), file_get_contents($localZipPath), $config);
    }

    /**
     * {@inheritDoc}
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $localZipPath = $this->writeToLocalZip($path, stream_get_contents($contents));

        $this->remoteAdapter->writeStream($this->getRemotePath($path), fopen($localZipPath, 'r'), $config);
    }

    /**
     * {@inheritDoc}
     */
    public function read(string $path): string
    {
        $zipStream = $this->readZipStream($path);

        return $this->remoteAdapter->read(stream_get_contents($zipStream));
    }

    /**
     * {@inheritDoc}
     */
    public function readStream(string $path)
    {
        return $this->readZipStream($path);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $path): void
    {
        $this->remoteAdapter->delete($path);
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
        $this->remoteAdapter->setVisibility($path, $visibility);
    }

    /**
     * {@inheritDoc}
     */
    public function visibility(string $path): FileAttributes
    {
        return $this->remoteAdapter->visibility($path);
    }

    /**
     * {@inheritDoc}
     */
    public function mimeType(string $path): FileAttributes
    {
        return $this->remoteAdapter->mimeType($path);
    }

    /**
     * {@inheritDoc}
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->remoteAdapter->lastModified($path);
    }

    /**
     * {@inheritDoc}
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->remoteAdapter->fileSize($path);
    }

    /**
     * {@inheritDoc}
     */
    public function listContents(string $path, bool $deep): iterable
    {
        return $this->remoteAdapter->listContents($path, $deep);
    }

    /**
     * {@inheritDoc}
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $this->remoteAdapter->move($source, $destination, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $this->remoteAdapter->copy($source, $destination, $config);
    }

    private function writeToLocalZip(string $path, string $contents): string
    {
        $localZipPath = $this->getLocalZipPath($path);
        $basename = basename($path);
        $zip = new ZipArchive();
        $zip->open($localZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($basename, $contents);
        $zip->close();

        return $localZipPath;
    }

    /**
     * @return resource
     */
    private function readZipStream(string $path)
    {
        $localZipPath = $this->getLocalZipPath($path);
        $zip = new ZipArchive();
        $zip->open($localZipPath, ZipArchive::CHECKCONS);
        $stream = $zip->getStream(basename($path));
        $zip->close();

        return $stream;
    }

    private function getRemotePath(string $path): string
    {
        return $path.'.zip';
    }

    private function getLocalZipPath(string $path): string
    {
        return $this->localWorkingDirectory.\DIRECTORY_SEPARATOR.sha1($path).'_'.basename($path).'.zip';
    }
}
