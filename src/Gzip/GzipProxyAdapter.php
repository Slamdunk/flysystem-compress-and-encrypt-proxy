<?php

declare(strict_types=1);

namespace SlamCompressAndEncryptProxy\Gzip;

use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use SlamCompressAndEncryptProxy\Core\AbstractProxyAdapter;

final class GzipProxyAdapter extends AbstractProxyAdapter
{
    public function __construct(
        FilesystemAdapter $remoteAdapter
    ) {
        GzipStreamFilter::register();

        parent::__construct($remoteAdapter);
    }

    public static function getRemoteFileExtension(): string
    {
        return '.gz';
    }

    /**
     * {@inheritDoc}
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        GzipStreamFilter::appendCompression($path, $contents);

        $this->getRemoteAdapter()->writeStream($this->getRemotePath($path), $contents, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function readStream(string $path)
    {
        $contents = $this->getRemoteAdapter()->readStream($this->getRemotePath($path));

        GzipStreamFilter::appendDecompression($path, $contents);

        return $contents;
    }
}
