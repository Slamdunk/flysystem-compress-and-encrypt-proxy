<?php

declare(strict_types=1);

namespace SlamCompressAndEncryptProxy;

use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;

final class EncryptAdapter extends AbstractProxyAdapter
{
    public function __construct(
        FilesystemAdapter $remoteAdapter,
        private string $key
    ) {
        $this->key = sodium_base642bin($this->key, SODIUM_BASE64_VARIANT_ORIGINAL);
        if (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES !== \strlen($this->key)) {
            throw new InvalidArgumentException(sprintf(
                'Provided key is not long exactly %s bytes. Consider using %s::generateKey() to get a strong one.',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
                __CLASS__
            ));
        }

        EncryptorStreamFilter::register();

        parent::__construct($remoteAdapter);
    }

    public static function generateKey(): string
    {
        return sodium_bin2base64(
            sodium_crypto_secretstream_xchacha20poly1305_keygen(),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );
    }

    public static function getRemoteFileExtension(): string
    {
        return '.encrypted';
    }

    /**
     * {@inheritDoc}
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        EncryptorStreamFilter::appendEncryption($contents, $this->key);

        $this->getRemoteAdapter()->writeStream($this->getRemotePath($path), $contents, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function readStream(string $path)
    {
        $contents = $this->getRemoteAdapter()->readStream($this->getRemotePath($path));

        EncryptorStreamFilter::appendDecryption($contents, $this->key);

        return $contents;
    }
}
