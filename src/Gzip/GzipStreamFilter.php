<?php

declare(strict_types=1);

namespace SlamFlysystem\Gzip;

use DeflateContext;
use HashContext;
use InflateContext;
use php_user_filter;
use RuntimeException;

/**
 * @see https://datatracker.ietf.org/doc/html/rfc1952
 *
 * @internal
 */
final class GzipStreamFilter extends php_user_filter
{
    private const FILTERNAME_PREFIX = 'slamflysystemgzip';
    private const MODE_COMPRESS = '.compress';
    private const MODE_DECOMPRESS = '.decompress';
    private const CHUNK_SIZE = 8192;

    private const HEADER_LENGTH = 10;
    private const FOOTER_LENGTH = 8;

    private const ID1 = "\x1F";
    private const ID2 = "\x8B";
    private const CM = "\x08";
    private const XFL = "\x00";
    private const OS = "\x03";

    private const FLG_BYTE_POSITION = 3;

    private const FLG_FTEXT = 1;
    private const FLG_FHCRC = 2;
    private const FLG_FEXTRA = 4;
    private const FLG_FNAME = 8;
    private const FLG_FCOMMENT = 16;

    private const HASH_ALGORITHM = 'crc32b';

    private string $filename;
    private string $mode;
    private string $buffer = '';
    private string $header = '';
    private ?HashContext $hashContext = null;
    private ?DeflateContext $deflateContext = null;
    private ?InflateContext $inflateContext = null;
    private int $originalSize = 0;

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
    public static function appendCompression(string $filename, $stream): void
    {
        $resource = stream_filter_append(
            $stream,
            self::FILTERNAME_PREFIX.self::MODE_COMPRESS,
            STREAM_FILTER_READ,
            $filename
        );
        \assert(false !== $resource);
    }

    /**
     * @param resource $stream
     */
    public static function appendDecompression(string $filename, $stream): void
    {
        $resource = stream_filter_append(
            $stream,
            self::FILTERNAME_PREFIX.self::MODE_DECOMPRESS,
            STREAM_FILTER_READ,
            $filename
        );
        \assert(false !== $resource);
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param ?int     $consumed
     * @param bool     $closing
     */
    public function filter($in, $out, &$consumed, $closing): int
    {
        while (null !== ($bucket = stream_bucket_make_writeable($in))) {
            \assert(\is_string($bucket->data));

            $this->buffer .= $bucket->data;
        }

        if ('' === $this->buffer && !$closing) {
            return PSFS_FEED_ME;
        }

        $consumed ??= 0;

        match ($this->mode) {
            self::MODE_COMPRESS => $this->compressFilter($out, $consumed, $closing),
            self::MODE_DECOMPRESS => $this->decompressFilter($out, $consumed, $closing),
        };

        return PSFS_PASS_ON;
    }

    public function onCreate(): bool
    {
        \assert(\is_string($this->params));
        $this->filename = $this->params;

        if (str_contains($this->filename, "\0")) {
            throw new RuntimeException('Filename cannot contain null-bytes');
        }

        \assert(\is_string($this->filtername));
        $this->mode = match ($this->filtername) {
            self::FILTERNAME_PREFIX.self::MODE_COMPRESS => self::MODE_COMPRESS,
            self::FILTERNAME_PREFIX.self::MODE_DECOMPRESS => self::MODE_DECOMPRESS,
        };

        return true;
    }

    /**
     * @param resource $out
     */
    private function compressFilter($out, int &$consumed, bool $closing): void
    {
        if (null === $this->hashContext) {
            $this->hashContext = hash_init(self::HASH_ALGORITHM);
            $this->deflateContext = deflate_init(ZLIB_ENCODING_RAW);

            $this->header = self::ID1;
            $this->header .= self::ID2;
            $this->header .= self::CM;
            $this->header .= \chr(self::FLG_FNAME);
            $this->header .= pack('V', time());
            $this->header .= self::XFL;
            $this->header .= self::OS;
            $this->header .= basename($this->filename)."\0";
        }

        $readChunkSize = self::CHUNK_SIZE;
        while ($readChunkSize <= \strlen($this->buffer) || $closing) {
            $data = substr($this->buffer, 0, $readChunkSize);
            $this->buffer = substr($this->buffer, $readChunkSize);

            hash_update($this->hashContext, $data);
            $this->originalSize += \strlen($data);

            $newBucketData = deflate_add(
                $this->deflateContext,
                $data,
                $closing
                    ? ZLIB_FINISH
                    : ZLIB_NO_FLUSH
            );

            if ('' !== $newBucketData) {
                \assert(\is_resource($this->stream));
                $newBucket = stream_bucket_new(
                    $this->stream,
                    $this->header.$newBucketData
                );
                $consumed += \strlen($data);
                stream_bucket_append($out, $newBucket);
                $this->header = '';
            }

            if ($closing && '' === $this->buffer) {
                $crc = hash_final($this->hashContext, true);
                $newBucketData = $crc[3].$crc[2].$crc[1].$crc[0];
                $newBucketData .= pack('V', $this->originalSize);

                \assert(\is_resource($this->stream));
                $newBucket = stream_bucket_new(
                    $this->stream,
                    $newBucketData
                );

                stream_bucket_append($out, $newBucket);

                return;
            }
        }
    }

    /**
     * @param resource $out
     */
    private function decompressFilter($out, int &$consumed, bool $closing): void
    {
        if (null === $this->hashContext) {
            $this->hashContext = hash_init(self::HASH_ALGORITHM);
            $this->inflateContext = inflate_init(ZLIB_ENCODING_RAW);

            $header = substr($this->buffer, 0, self::HEADER_LENGTH);
            $this->buffer = substr($this->buffer, self::HEADER_LENGTH);

            if (self::ID1.self::ID2.self::CM !== substr($header, 0, 3)) {
                throw new RuntimeException('Stream is not GZip');
            }

            if (self::FLG_FNAME === (\ord($header[self::FLG_BYTE_POSITION]) & self::FLG_FNAME)) {
                $nullbytePosition = strpos($this->buffer, "\0");
                \assert(false !== $nullbytePosition);
                $this->buffer = substr($this->buffer, 1 + $nullbytePosition);
            }
        }

        $writeChunkSize = self::CHUNK_SIZE;
        while ($writeChunkSize <= \strlen($this->buffer) || $closing) {
            $data = substr($this->buffer, 0, $writeChunkSize);
            $this->buffer = substr($this->buffer, $writeChunkSize);

            if ($closing && '' === $this->buffer) {
                $footer = substr($data, -self::FOOTER_LENGTH);
            }

            $newBucketData = inflate_add(
                $this->inflateContext,
                $data,
                ZLIB_SYNC_FLUSH
            );

            hash_update($this->hashContext, $newBucketData);

            \assert(\is_resource($this->stream));
            $newBucket = stream_bucket_new(
                $this->stream,
                $newBucketData
            );
            $consumed += \strlen($data);
            stream_bucket_append($out, $newBucket);

            if ($closing && '' === $this->buffer) {
                \assert(isset($footer) && \is_string($footer));
                $crc = hash_final($this->hashContext, true);
                if ($crc[3].$crc[2].$crc[1].$crc[0] !== substr($footer, 0, 4)) {
                    throw new RuntimeException('CRC32 checksum failed for '.$this->filename);
                }

                return;
            }
        }
    }
}
