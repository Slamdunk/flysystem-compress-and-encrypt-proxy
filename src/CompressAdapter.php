<?php

declare(strict_types=1);

namespace SlamCompressAndEncryptProxy;

use League\Flysystem\Config;

final class CompressAdapter extends AbstractProxyAdapter
{
    public static function getRemoteFileExtension(): string
    {
        return '.gz';
    }

    /**
     * {@inheritDoc}
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $zlibFilter = stream_filter_append($contents, 'zlib.deflate');
        \assert(false !== $zlibFilter);

        $this->getRemoteAdapter()->writeStream($this->getRemotePath($path), $contents, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function readStream(string $path)
    {
        $contents = $this->getRemoteAdapter()->readStream($this->getRemotePath($path));

        $zlibFilter = stream_filter_append($contents, 'zlib.inflate');
        \assert(false !== $zlibFilter);

        return $contents;
    }
}
