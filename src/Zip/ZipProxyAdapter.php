<?php

declare(strict_types=1);

namespace SlamFlysystem\Zip;

use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use SlamFlysystem\Core\AbstractProxyAdapter;

final class ZipProxyAdapter extends AbstractProxyAdapter
{
    public function __construct(
        FilesystemAdapter $remoteAdapter
    ) {
        ZipStreamFilter::register();

        parent::__construct($remoteAdapter);
    }

    public static function getRemoteFileExtension(): string
    {
        return '.zip';
    }

    /**
     * {@inheritDoc}
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        ZipStreamFilter::appendCompression($path, $contents);

        $this->getRemoteAdapter()->writeStream($this->getRemotePath($path), $contents, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function readStream(string $path)
    {
        $contents = $this->getRemoteAdapter()->readStream($this->getRemotePath($path));

        ZipStreamFilter::appendDecompression($path, $contents);

        return $contents;
    }
}
