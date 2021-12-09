<?php

declare(strict_types=1);

namespace SlamFlysystem\Zip;

use DeflateContext;
use HashContext;
use InflateContext;
use php_user_filter;
use RuntimeException;

/**
 * Most of the code here was copied from the awesome ZipStream-PHP library.
 *
 * @see https://github.com/maennchen/ZipStream-PHP
 * @see https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT
 *
 * @internal
 */
final class ZipStreamFilter extends php_user_filter
{
    private const FILTERNAME_PREFIX = 'slamflysystemzip';
    private const MODE_COMPRESS = '.compress';
    private const MODE_DECOMPRESS = '.decompress';
    private const CHUNK_SIZE = 8192;

    private const HEADER_LENGTH = 30;

    /**
     * @see https://github.com/maennchen/ZipStream-PHP/pull/90
     */
    private const ZIP_VERSION_MADE_BY = 0x603;

    private const FILE_HEADER_SIGNATURE = 0x04034B50;
    private const CDR_FILE_SIGNATURE = 0x02014B50;
    private const CDR_EOF_SIGNATURE = 0x06054B50;
    private const DATA_DESCRIPTOR_SIGNATURE = 0x08074B50;
    private const ZIP64_CDR_EOF_SIGNATURE = 0x06064B50;
    private const ZIP64_CDR_LOCATOR_SIGNATURE = 0x07064B50;

    private const VERSION_ZIP64 = 0x002D;
    private const BITS_ZERO_HEADER_ONLY = 0x08;
    private const METHOD_DEFLATE = 0x08;

    private const HASH_ALGORITHM = 'crc32b';

    private string $filename;
    private int $time;
    private string $mode;
    private string $buffer = '';
    private string $header = '';
    private ?HashContext $hashContext = null;
    private ?DeflateContext $deflateContext = null;
    private int $inflateMethod;
    private ?InflateContext $inflateContext = null;

    private int $originalSize = 0;
    private int $compressedSize = 0;
    private int $totalLength = 0;

    private int $crc;

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

        $this->time = $this->unixToDosTime(time());

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

            $footer = $this->buildZip64ExtraBlock(true);

            $this->header = pack('V', self::FILE_HEADER_SIGNATURE);
            $this->header .= pack('v', self::VERSION_ZIP64);            // Version needed to Extract
            $this->header .= pack('v', self::BITS_ZERO_HEADER_ONLY);    // General purpose bit flags - data descriptor flag set: zero header only
            $this->header .= pack('v', self::METHOD_DEFLATE);           // Compression method: DEFLATE
            $this->header .= pack('V', $this->time);                    // Timestamp (DOS Format)
            $this->header .= pack('V', 0);                              // CRC32 of data (0 -> moved to data descriptor footer)
            $this->header .= pack('V', 0xFFFFFFFF);                     // Length of compressed data (forced to 0xFFFFFFFF for zero header)
            $this->header .= pack('V', 0xFFFFFFFF);                     // Length of original data (forced to 0xFFFFFFFF for zero header)
            $this->header .= pack('v', \strlen($this->filename));        // Length of filename
            $this->header .= pack('v', \strlen($footer));                // Extra data

            $this->header .= $this->filename;
            $this->header .= $footer;

            $this->totalLength = \strlen($this->header);
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
            $this->compressedSize += \strlen($newBucketData);

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
                $crc = hexdec(hash_final($this->hashContext));

                $fileFooter = pack('V', self::DATA_DESCRIPTOR_SIGNATURE);
                $fileFooter .= pack('V', $crc);
                $fileFooter .= pack('P', $this->compressedSize);
                $fileFooter .= pack('P', $this->originalSize);

                $this->totalLength += $this->compressedSize;
                $this->totalLength += \strlen($fileFooter);

                $footer = $this->buildZip64ExtraBlock(false);

                $header = pack('V', self::CDR_FILE_SIGNATURE);              // Central file header signature
                $header .= pack('v', self::ZIP_VERSION_MADE_BY);            // Made by version
                $header .= pack('v', self::VERSION_ZIP64);                  // Extract by version
                $header .= pack('v', self::BITS_ZERO_HEADER_ONLY);          // General purpose bit flags - data descriptor flag set
                $header .= pack('v', self::METHOD_DEFLATE);                 // Compression method
                $header .= pack('V', $this->time);                          // Timestamp (DOS Format)
                $header .= pack('V', $crc);                                 // CRC32
                $header .= pack('V', $this->compressedSize & 0xFFFFFFFF);   // Compressed Data Length
                $header .= pack('V', $this->originalSize & 0xFFFFFFFF);     // Original Data Length
                $header .= pack('v', \strlen($this->filename));              // Length of filename
                $header .= pack('v', \strlen($footer));                      // Extra data len (see above)
                $header .= pack('v', 0);                                    // Length of comment
                $header .= pack('v', 0);                                    // Disk number
                $header .= pack('v', 0);                                    // Internal File Attributes
                $header .= pack('V', 32);                                   // External File Attributes
                $header .= pack('V', 0);                                    // Relative offset of local header

                $cdrFile = $header.$this->filename.$footer;

                $cdrLength = \strlen($cdrFile);

                $cdr64Eof = pack('V', self::ZIP64_CDR_EOF_SIGNATURE);       // ZIP64 end of central file header signature
                $cdr64Eof .= pack('P', 44);                                 // Length of data below this header (length of block - 12) = 44
                $cdr64Eof .= pack('v', self::ZIP_VERSION_MADE_BY);          // Made by version
                $cdr64Eof .= pack('v', self::VERSION_ZIP64);                // Extract by version
                $cdr64Eof .= pack('V', 0x00);                               // disk number
                $cdr64Eof .= pack('V', 0x00);                               // no of disks
                $cdr64Eof .= pack('P', 1);                                  // no of entries on disk
                $cdr64Eof .= pack('P', 1);                                  // no of entries in cdr
                $cdr64Eof .= pack('P', $cdrLength);                         // CDR size
                $cdr64Eof .= pack('P', $this->totalLength);                 // CDR offset

                $cdr64Locator = pack('V', self::ZIP64_CDR_LOCATOR_SIGNATURE);   // ZIP64 end of central file header signature
                $cdr64Locator .= pack('V', 0x00);                               // Disc number containing CDR64EOF
                $cdr64Locator .= pack('P', $this->totalLength + $cdrLength);    // CDR offset
                $cdr64Locator .= pack('V', 1);                                  // Total number of disks

                $cdrEof = pack('V', self::CDR_EOF_SIGNATURE);           // end of central file header signature
                $cdrEof .= pack('v', 0x00);                             // disk number
                $cdrEof .= pack('v', 0x00);                             // no of disks
                $cdrEof .= pack('v', 1);                                // no of entries on disk
                $cdrEof .= pack('v', 1);                                // no of entries in cdr
                $cdrEof .= pack('V', $cdrLength & 0xFFFFFFFF);          // CDR size
                $cdrEof .= pack('V', $this->totalLength & 0xFFFFFFFF);  // CDR offset
                $cdrEof .= pack('v', 0);                                // Zip Comment size

                $newBucketData = $fileFooter.$cdrFile.$cdr64Eof.$cdr64Locator.$cdrEof;

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
            $header = substr($this->buffer, 0, self::HEADER_LENGTH);
            $this->buffer = substr($this->buffer, self::HEADER_LENGTH);
            $arrayHeader = unpack('Vhead/vver/vbits/vmethod/Vtime/Vcrc/Vzlen/Vlen/vfilename/vfooter', $header);

            if (self::FILE_HEADER_SIGNATURE !== $arrayHeader['head']) {
                throw new RuntimeException('Stream is not Zip');
            }

            \assert(\is_int($arrayHeader['method']));
            $this->inflateMethod = $arrayHeader['method'];
            \assert(\is_int($arrayHeader['filename']));
            $this->buffer = substr($this->buffer, $arrayHeader['filename']);
            \assert(\is_int($arrayHeader['footer']));
            $this->buffer = substr($this->buffer, $arrayHeader['footer']);

            \assert(\is_int($arrayHeader['crc']));
            $this->crc = $arrayHeader['crc'];

            $this->hashContext = hash_init(self::HASH_ALGORITHM);
            if (self::METHOD_DEFLATE === $this->inflateMethod) {
                $this->inflateContext = inflate_init(ZLIB_ENCODING_RAW);
            }
        }

        $writeChunkSize = self::CHUNK_SIZE;
        while ($writeChunkSize <= \strlen($this->buffer) || $closing) {
            $data = substr($this->buffer, 0, $writeChunkSize);
            $this->buffer = substr($this->buffer, $writeChunkSize);

            if ($closing && '' === $this->buffer) {
                $dataDescriptorPosition = strpos($data, pack('V', self::DATA_DESCRIPTOR_SIGNATURE));
                if (false === $dataDescriptorPosition) {
                    $cdrFilePosition = strpos($data, pack('V', self::CDR_FILE_SIGNATURE));
                    \assert(false !== $cdrFilePosition);
                    $data = substr($data, 0, $cdrFilePosition);
                } else {
                    $unpack = unpack('Vcrc', substr($data, $dataDescriptorPosition + 4, 4));
                    \assert(\is_int($unpack['crc']));
                    $this->crc = $unpack['crc'];
                    $data = substr($data, 0, $dataDescriptorPosition);
                }
            }

            $newBucketData = $data;
            if (self::METHOD_DEFLATE === $this->inflateMethod) {
                $newBucketData = inflate_add(
                    $this->inflateContext,
                    $data,
                    ZLIB_SYNC_FLUSH
                );
            }

            hash_update($this->hashContext, $newBucketData);

            \assert(\is_resource($this->stream));
            $newBucket = stream_bucket_new(
                $this->stream,
                $newBucketData
            );
            $consumed += \strlen($data);
            stream_bucket_append($out, $newBucket);

            if ($closing && '' === $this->buffer) {
                $crc = hexdec(hash_final($this->hashContext));
                if ($crc !== $this->crc) {
                    throw new RuntimeException('CRC32 checksum failed for '.$this->filename);
                }

                return;
            }
        }
    }

    private function buildZip64ExtraBlock(bool $force): string
    {
        $fields = [];
        if ($force || $this->originalSize >= 0xFFFFFFFF) {
            $fields[] = pack('P', $this->originalSize);
        }
        if ($force || $this->compressedSize >= 0xFFFFFFFF) {
            $fields[] = pack('P', $this->compressedSize);
        }

        if ($this->totalLength >= 0xFFFFFFFF) {
            // We won't test 4GB data...
            // @codeCoverageIgnoreStart
            $fields[] = pack('P', $this->totalLength);  // Offset of local header record
            // @codeCoverageIgnoreEnd
        }

        if ([] !== $fields) {
            array_unshift(
                $fields,
                pack('v', 0x0001),              // 64 bit extension
                pack('v', \count($fields) * 8)  // Length of data block
            );
        }

        return implode('', $fields);
    }

    private function unixToDosTime(int $when): int
    {
        $d = getdate($when);
        $d['year'] -= 1980;

        return
            ($d['year'] << 25) |
            ($d['mon'] << 21) |
            ($d['mday'] << 16) |
            ($d['hours'] << 11) |
            ($d['minutes'] << 5) |
            ($d['seconds'] >> 1);
    }
}
