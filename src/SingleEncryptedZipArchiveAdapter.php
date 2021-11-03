<?php

declare(strict_types=1);

namespace SlamFlysystemSingleEncryptedZipArchive;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use ZipArchive;

final class SingleEncryptedZipArchiveAdapter implements FilesystemAdapter
{
    private ZipArchive $zip;

    public function __construct(
        private FilesystemAdapter $remoteAdapter,
        private string $password,
        private string $localWorkingDirectory
    ) {
        if (12 > \strlen($password)) {
            throw new WeakPasswordException(sprintf(
                'Provided password is less then 12 chars. Consider using %s::generateKey() to get a strong one.',
                __CLASS__
            ));
        }

        if (!is_dir($localWorkingDirectory) || !is_writable($localWorkingDirectory)) {
            throw new UnableToWriteToDirectoryException("{$localWorkingDirectory} is not writable");
        }

        $this->zip = new ZipArchive();
    }

    public function __destruct()
    {
        foreach (glob($this->localWorkingDirectory.\DIRECTORY_SEPARATOR.'*.zip') as $file) {
            @unlink($file);
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
        return stream_get_contents($this->readZipStream($path));
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
                    substr($content->path(), 0, -4),
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
        throw new UnsupportedOperationException(__METHOD__.' operation is not supported');
    }

    /**
     * {@inheritDoc}
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        throw new UnsupportedOperationException(__METHOD__.' operation is not supported');
    }

    private function writeToLocalZip(string $path, string $contents): string
    {
        $localZipPath = $this->getLocalZipPath($path);
        $this->zip->open($localZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->zip->addFromString(basename($path), $contents);
        $this->zip->setEncryptionName(basename($path), ZipArchive::EM_AES_256, $this->password);
        $this->zip->close();

        return $localZipPath;
    }

    /**
     * @return resource
     */
    private function readZipStream(string $path)
    {
        $remotePath = $this->getRemotePath($path);
        $localZipPath = $this->getLocalZipPath($path);
        $contents = $this->remoteAdapter->readStream($remotePath);

        error_clear_last();
        $stream = @fopen($localZipPath, 'w+');

        if (!(false !== $stream && false !== stream_copy_to_stream($contents, $stream) && fclose($stream))) {
            $reason = error_get_last()['message'] ?? '';

            throw new UnableToWriteFileException("Unable to write to {$localZipPath}: {$reason}");
        }

        $this->zip->open($localZipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS);
        $this->zip->setPassword($this->password);

        return $this->zip->getStream(basename($path));
    }

    private function getRemotePath(string $path): string
    {
        return $path.'.zip';
    }

    private function getLocalZipPath(string $path): string
    {
        $pathFingerprint = sprintf(
            '%s#%s',
            \get_class($this->remoteAdapter),
            $path
        );

        return sprintf(
            '%s%s%s_%s.zip',
            $this->localWorkingDirectory,
            \DIRECTORY_SEPARATOR,
            sha1($pathFingerprint),
            basename($path)
        );
    }
}
