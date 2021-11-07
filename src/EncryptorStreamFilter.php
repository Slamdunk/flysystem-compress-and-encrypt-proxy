<?php

declare(strict_types=1);

namespace SlamCompressAndEncryptProxy;

use php_user_filter;
use RuntimeException;

/**
 * @internal
 */
final class EncryptorStreamFilter extends php_user_filter
{
    private const FILTERNAME_PREFIX = 'slamflysystemencryptor';
    private const MODE_ENCRYPT = '.encrypt';
    private const MODE_DECRYPT = '.decrypt';
    private const CHUNK_SIZE = 8192;

    private ?string $key;
    private string $mode;
    private ?string $state = null;
    private string $buffer = '';

    private static bool $filterRegistered = false;

    public static function register(): void
    {
        if (self::$filterRegistered) {
            return;
        }

        $success = stream_filter_register(self::FILTERNAME_PREFIX.'.*', __CLASS__);
        \assert(true === $success);
        self::$filterRegistered = true;
    }

    /**
     * @param resource $stream
     */
    public static function appendEncryption($stream, string $key): void
    {
        $resource = stream_filter_append(
            $stream,
            self::FILTERNAME_PREFIX.self::MODE_ENCRYPT,
            STREAM_FILTER_ALL,
            $key
        );
        \assert(false !== $resource);
    }

    /**
     * @param resource $stream
     */
    public static function appendDecryption($stream, string $key): void
    {
        $resource = stream_filter_append(
            $stream,
            self::FILTERNAME_PREFIX.self::MODE_DECRYPT,
            STREAM_FILTER_ALL,
            $key
        );
        \assert(false !== $resource);
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int      $consumed
     * @param bool     $closing
     */
    public function filter($in, $out, &$consumed, $closing): int
    {
        while (null !== ($bucket = stream_bucket_make_writeable($in))) {
            \assert(\is_string($bucket->data));

            $this->buffer .= $bucket->data;
        }

        if ('' === $this->buffer) {
            return PSFS_FEED_ME;
        }

        return match ($this->mode) {
            self::MODE_ENCRYPT => $this->encryptFilter($out, $consumed, $closing),
            self::MODE_DECRYPT => $this->decryptFilter($out, $consumed, $closing),
        };
    }

    public function onCreate(): bool
    {
        \assert(\is_string($this->params));
        $this->key = $this->params;
        sodium_memzero($this->params);

        \assert(\is_string($this->filtername));
        $this->mode = match ($this->filtername) {
            self::FILTERNAME_PREFIX.self::MODE_ENCRYPT => self::MODE_ENCRYPT,
            self::FILTERNAME_PREFIX.self::MODE_DECRYPT => self::MODE_DECRYPT,
        };

        return true;
    }

    /**
     * @param resource $out
     * @param int      $consumed
     * @param bool     $closing
     */
    private function encryptFilter($out, &$consumed, $closing): int
    {
        $header = '';
        if (null === $this->state) {
            \assert(\is_string($this->key));
            [$this->state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
            sodium_memzero($this->key);
            \assert(\is_string($header));
        }

        $readChunkSize = self::CHUNK_SIZE - SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        while ($readChunkSize <= \strlen($this->buffer) || $closing) {
            $data = substr($this->buffer, 0, $readChunkSize);
            $this->buffer = substr($this->buffer, $readChunkSize);

            $tag = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            if ($closing && '' === $this->buffer) {
                $tag = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            $newBucketData = $header.sodium_crypto_secretstream_xchacha20poly1305_push(
                $this->state,
                $data,
                '',
                $tag
            );

            \assert(\is_resource($this->stream));
            $newBucket = stream_bucket_new(
                $this->stream,
                $newBucketData
            );
            $consumed += \strlen($data);
            stream_bucket_append($out, $newBucket);

            $header = '';
            if ($closing && '' === $this->buffer) {
                sodium_memzero($this->state);

                break;
            }
        }

        return PSFS_PASS_ON;
    }

    /**
     * @param resource $out
     * @param int      $consumed
     * @param bool     $closing
     */
    private function decryptFilter($out, &$consumed, $closing): int
    {
        if (null === $this->state) {
            $header = substr($this->buffer, 0, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $this->buffer = substr($this->buffer, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

            \assert(\is_string($this->key));
            $this->state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
            sodium_memzero($this->key);
        }

        $writeChunkSize = self::CHUNK_SIZE;
        while ($writeChunkSize <= \strlen($this->buffer) || $closing) {
            $data = substr($this->buffer, 0, $writeChunkSize);
            $this->buffer = substr($this->buffer, $writeChunkSize);

            $expectedTag = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            if ($closing && '' === $this->buffer) {
                $expectedTag = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            [$newBucketData, $tag] = sodium_crypto_secretstream_xchacha20poly1305_pull($this->state, $data);
            \assert(\is_string($newBucketData));

            if ($expectedTag !== $tag) {
                throw new RuntimeException('Encrypted stream corrupted');
            }
            \assert(\is_resource($this->stream));
            $newBucket = stream_bucket_new(
                $this->stream,
                $newBucketData
            );
            $consumed += \strlen($data);
            stream_bucket_append($out, $newBucket);

            if ($closing && '' === $this->buffer) {
                sodium_memzero($this->state);

                break;
            }
        }

        return PSFS_PASS_ON;
    }
}
