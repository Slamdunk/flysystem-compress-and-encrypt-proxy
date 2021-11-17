<?php

declare(strict_types=1);

namespace SlamCompressAndEncryptProxy;

use php_user_filter;
use RuntimeException;

/**
 * A simple stream_filter_append($stream, 'zlib.deflate') is enough
 * to compress the file. All the remaining fuzz is just to add
 * the headers and footers needed to make the content compatible
 * with `gzip` binary.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc1952
 *
 * @internal
 */
final class GzipStreamFilter extends php_user_filter
{
    private const FILTERNAME_PREFIX = 'slamflysystemgzip';
    private const MODE_COMPRESS_OPEN = '.compressopen';
    private const MODE_COMPRESS_CLOSE = '.compressclose';
    private const MODE_DECOMPRESS_OPEN = '.decompressopen';
    private const MODE_DECOMPRESS_CLOSE = '.decompressclose';

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

    private string $id;
    private string $filename;
    private string $mode;
    /**
     * @var array<string, array{'hash_context': \HashContext, 'original_size': int, 'footer': null|string}>
     */
    private static array $register = [];
    private bool $started = false;

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
        $streamId = uniqid($filename);
        $compressOpen = stream_filter_append(
            $stream,
            self::FILTERNAME_PREFIX.self::MODE_COMPRESS_OPEN,
            STREAM_FILTER_ALL,
            [
                'id' => $streamId,
                'filename' => $filename,
            ]
        );
        \assert(false !== $compressOpen);
        $zlibFilter = stream_filter_append($stream, 'zlib.deflate');
        \assert(false !== $zlibFilter);
        $compressClose = stream_filter_append(
            $stream,
            self::FILTERNAME_PREFIX.self::MODE_COMPRESS_CLOSE,
            STREAM_FILTER_ALL,
            [
                'id' => $streamId,
                'filename' => $filename,
            ]
        );
        \assert(false !== $compressClose);
    }

    /**
     * @param resource $stream
     */
    public static function appendDecompression(string $filename, $stream): void
    {
        $streamId = uniqid($filename);
        $decompressOpen = stream_filter_append(
            $stream,
            self::FILTERNAME_PREFIX.self::MODE_DECOMPRESS_OPEN,
            STREAM_FILTER_ALL,
            [
                'id' => $streamId,
                'filename' => $filename,
            ]
        );
        \assert(false !== $decompressOpen);
        $zlibFilter = stream_filter_append($stream, 'zlib.inflate');
        \assert(false !== $zlibFilter);
        $decompressClose = stream_filter_append(
            $stream,
            self::FILTERNAME_PREFIX.self::MODE_DECOMPRESS_CLOSE,
            STREAM_FILTER_ALL,
            [
                'id' => $streamId,
                'filename' => $filename,
            ]
        );
        \assert(false !== $decompressClose);
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param ?int     $consumed
     * @param bool     $closing
     */
    public function filter($in, $out, &$consumed, $closing): int
    {
        if (!isset(self::$register[$this->id])) {
            self::$register[$this->id] = [
                'hash_context' => hash_init(self::HASH_ALGORITHM),
                'original_size' => 0,
                'footer' => null,
            ];
        }

        if (self::MODE_COMPRESS_CLOSE === $this->mode) {
            $newBucketData = self::ID1;
            $newBucketData .= self::ID2;
            $newBucketData .= self::CM;
            $newBucketData .= \chr(self::FLG_FNAME);
            $newBucketData .= pack('V', time());
            $newBucketData .= self::XFL;
            $newBucketData .= self::OS;

            $newBucketData .= basename($this->filename)."\0";

            \assert(\is_resource($this->stream));
            $newBucket = stream_bucket_new(
                $this->stream,
                $newBucketData
            );
            stream_bucket_append($out, $newBucket);
        }

        $feeded = false;
        while (null !== ($bucket = stream_bucket_make_writeable($in))) {
            if (self::MODE_DECOMPRESS_OPEN === $this->mode && !$this->started) {
                \assert(\is_string($bucket->data));
                $header = substr($bucket->data, 0, self::HEADER_LENGTH);
                $bucket->data = substr($bucket->data, self::HEADER_LENGTH);
                \assert(\is_string($bucket->data));

                if (self::ID1.self::ID2.self::CM !== substr($header, 0, 3)) {
                    throw new RuntimeException('Stream is not GZip');
                }

                if (self::FLG_FNAME === (\ord($header[self::FLG_BYTE_POSITION]) & self::FLG_FNAME)) {
                    $nullbytePosition = strpos($bucket->data, "\0");
                    \assert(false !== $nullbytePosition);
                    $bucket->data = substr($bucket->data, 1 + $nullbytePosition);
                }

                $this->started = true;
            }

            if (self::MODE_DECOMPRESS_OPEN === $this->mode) {
                \assert(\is_string($bucket->data));
                self::$register[$this->id]['footer'] = substr($bucket->data, -self::FOOTER_LENGTH);
            }

            if (self::MODE_COMPRESS_OPEN === $this->mode || self::MODE_DECOMPRESS_CLOSE === $this->mode) {
                \assert(\is_string($bucket->data));
                hash_update(self::$register[$this->id]['hash_context'], $bucket->data);
                \assert(\is_int($bucket->datalen));
                self::$register[$this->id]['original_size'] += $bucket->datalen;
            }

            $consumed ??= 0;

            \assert(\is_int($bucket->datalen));
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
            $feeded = true;
        }

        if (self::MODE_COMPRESS_CLOSE === $this->mode && $closing) {
            $crc = hash_final(self::$register[$this->id]['hash_context'], true);
            $newBucketData = $crc[3].$crc[2].$crc[1].$crc[0];
            $newBucketData .= pack('V', self::$register[$this->id]['original_size']);

            \assert(\is_resource($this->stream));
            $newBucket = stream_bucket_new(
                $this->stream,
                $newBucketData
            );
            stream_bucket_append($out, $newBucket);

            unset(self::$register[$this->id]);
        }

        if (self::MODE_DECOMPRESS_OPEN === $this->mode && !$feeded && \is_string(self::$register[$this->id]['footer'])) {
            $crc = hash_final(self::$register[$this->id]['hash_context'], true);
            if ($crc[3].$crc[2].$crc[1].$crc[0] !== substr(self::$register[$this->id]['footer'], 0, 4)) {
                throw new RuntimeException('CRC32 checksum failed for '.$this->filename);
            }
            $fileSize = unpack('Vsize', substr(self::$register[$this->id]['footer'], 4));
            if (self::$register[$this->id]['original_size'] !== $fileSize['size']) {
                throw new RuntimeException('File size differs for '.$this->filename);
            }

            self::$register[$this->id]['footer'] = null;
        }

        if (!$feeded) {
            return PSFS_FEED_ME;
        }

        return PSFS_PASS_ON;
    }

    public function onCreate(): bool
    {
        \assert(\is_array($this->params));
        \assert(\array_key_exists('id', $this->params));
        \assert(\array_key_exists('filename', $this->params));
        \assert(\is_string($this->params['id']));
        \assert(\is_string($this->params['filename']));

        if (str_contains($this->params['filename'], "\0")) {
            throw new RuntimeException('Filename cannot contain null-bytes');
        }

        $this->id = $this->params['id'];
        $this->filename = $this->params['filename'];

        \assert(\is_string($this->filtername));
        $this->mode = match ($this->filtername) {
            self::FILTERNAME_PREFIX.self::MODE_COMPRESS_OPEN => self::MODE_COMPRESS_OPEN,
            self::FILTERNAME_PREFIX.self::MODE_COMPRESS_CLOSE => self::MODE_COMPRESS_CLOSE,
            self::FILTERNAME_PREFIX.self::MODE_DECOMPRESS_OPEN => self::MODE_DECOMPRESS_OPEN,
            self::FILTERNAME_PREFIX.self::MODE_DECOMPRESS_CLOSE => self::MODE_DECOMPRESS_CLOSE,
        };

        return true;
    }
}
