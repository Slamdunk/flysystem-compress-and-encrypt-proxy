<?php

declare(strict_types=1);

namespace SlamFlysystemEncryptedZipProxy;

use php_user_filter;

final class EncryptorStreamFilter extends php_user_filter
{
    private const FILTERNAME_PREFIX = 'slamflysystemencryptor';
    private const MODE_ENCRYPT = '.encrypt';
    private const MODE_DECRYPT = '.decrypt';
    private const ENCRYPT_READ_BYTES = 8175;
    private const DECRYPT_READ_BYTES = 8192;

    private ?string $key;
    private string $mode;
    private ?string $state = null;
    private string $buffer = '';

    private static bool $filterRegistered = false;

    // public $stream;

    public static function register(): void
    {
        if (self::$filterRegistered) {
            return;
        }

        stream_filter_register(self::FILTERNAME_PREFIX.'.*', __CLASS__);
        self::$filterRegistered = true;
    }

    /**
     * @param resource $stream
     */
    public static function appendEncryption($stream, string $key): void
    {
        stream_filter_append(
            $stream,
            self::FILTERNAME_PREFIX.self::MODE_ENCRYPT,
            STREAM_FILTER_ALL,
            $key
        );
    }

    /**
     * @param resource $stream
     */
    public static function appendDecryption($stream, string $key): void
    {
        stream_filter_append(
            $stream,
            self::FILTERNAME_PREFIX.self::MODE_DECRYPT,
            STREAM_FILTER_ALL,
            $key
        );
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int      $consumed
     * @param bool     $closing
     */
    public function filter($in, $out, &$consumed, $closing): int
    {
        if (self::MODE_ENCRYPT === $this->mode) {
            return $this->encryptFilter($in, $out, $consumed, $closing);
        }

        if (self::MODE_DECRYPT === $this->mode) {
            return $this->decryptFilter($in, $out, $consumed, $closing);
        }

        return PSFS_ERR_FATAL;
    }

    public function onCreate(): bool
    {
        if (self::FILTERNAME_PREFIX.self::MODE_ENCRYPT === $this->filtername) {
            \assert(\is_string($this->params));
            $this->key = $this->params;
            sodium_memzero($this->params);
            $this->mode = self::MODE_ENCRYPT;

            return true;
        }

        if (self::FILTERNAME_PREFIX.self::MODE_DECRYPT === $this->filtername) {
            \assert(\is_string($this->params));
            $this->key = $this->params;
            sodium_memzero($this->params);
            $this->mode = self::MODE_DECRYPT;

            return true;
        }

        return false;
    }

    public function onClose(): void
    {
        if (null !== $this->state) {
            sodium_memzero($this->state);
        }
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int      $consumed
     * @param bool     $closing
     */
    private function encryptFilter($in, $out, &$consumed, $closing): int
    {
        $output = false;
        $lastBucket = null;
        while (null !== ($bucket = stream_bucket_make_writeable($in))) {
            \assert(\is_string($bucket->data));

            $this->buffer .= $bucket->data;
            $lastBucket = $bucket;
            $output = true;
        }

        if (!$output) {
            return PSFS_FEED_ME;
        }

        if (self::ENCRYPT_READ_BYTES > \strlen($this->buffer) && !$closing) {
            return PSFS_FEED_ME;
        }

        $header = '';
        if (null === $this->state) {
            \assert(\is_string($this->key));
            [$this->state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
            sodium_memzero($this->key);
            \assert(\is_string($header));
        }

        $data = substr($this->buffer, 0, self::ENCRYPT_READ_BYTES);
        $this->buffer = substr($this->buffer, self::ENCRYPT_READ_BYTES);

        $lastBucket->data = $header.sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $data);
        $lastBucket->datalen = \strlen($lastBucket->data);

        $consumed += \strlen($data);
        stream_bucket_append($out, $lastBucket);

        if ($closing && '' !== $this->buffer) {
            $newBucketData = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $this->buffer);

            $newBucket = stream_bucket_new(
                $this->stream,
                $newBucketData
            );
            $consumed += \strlen($this->buffer);
            stream_bucket_append($out, $newBucket);
        }

        return PSFS_PASS_ON;
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int      $consumed
     * @param bool     $closing
     */
    private function decryptFilter($in, $out, &$consumed, $closing): int
    {
        $output = false;
        while (null !== ($bucket = stream_bucket_make_writeable($in))) {
            \assert(\is_string($bucket->data));

            $this->buffer .= $bucket->data;
            $output = true;
        }

        if (!$output) {
            return PSFS_FEED_ME;
        }

        $header = '';
        if (null === $this->state) {
            $header = substr($this->buffer, 0, 24);
            $this->buffer = substr($this->buffer, 24);

            \assert(\is_string($this->key));
            $this->state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
            sodium_memzero($this->key);
        }

        if (self::DECRYPT_READ_BYTES > \strlen($this->buffer) && !$closing) {
            return PSFS_FEED_ME;
        }

        $data = substr($this->buffer, 0, self::DECRYPT_READ_BYTES);
        $this->buffer = substr($this->buffer, self::DECRYPT_READ_BYTES);

        $consumedData = \strlen($header) + \strlen($data);
        [$newBucketData] = sodium_crypto_secretstream_xchacha20poly1305_pull($this->state, $data);

        $newBucket = stream_bucket_new(
            $this->stream,
            $newBucketData
        );
        $consumed += $consumedData;
        stream_bucket_append($out, $newBucket);

        if ($closing && '' !== $this->buffer) {
            [$newBucketData] = sodium_crypto_secretstream_xchacha20poly1305_pull($this->state, $this->buffer);

            $newBucket = stream_bucket_new(
                $this->stream,
                $newBucketData
            );
            $consumed += \strlen($this->buffer);
            stream_bucket_append($out, $newBucket);
        }

        return PSFS_PASS_ON;
    }
}
