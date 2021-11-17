<?php

declare(strict_types=1);

namespace SlamCompressAndEncryptProxy\Test\Gzip;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SlamCompressAndEncryptProxy\Gzip\GzipStreamFilter;

/**
 * @covers \SlamCompressAndEncryptProxy\Gzip\GzipStreamFilter
 *
 * @internal
 */
final class GzipStreamFilterTest extends TestCase
{
    protected function setUp(): void
    {
        GzipStreamFilter::register();
    }

    /**
     * @test
     */
    public function stream_filter_gzip_stream(): void
    {
        $originalPlain = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, '
            .'sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut '
            .'enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi '
            .'ut aliquip ex ea commodo consequat. Duis aute irure dolor in '
            .'reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla '
            .'pariatur. Excepteur sint occaecat cupidatat non proident, sunt in '
            .'culpa qui officia deserunt mollit anim id est laborum.';

        $compressedStream = $this->streamFromContents($originalPlain);
        GzipStreamFilter::appendCompression('file.txt', $compressedStream);

        $compressed = stream_get_contents($compressedStream);
        fclose($compressedStream);
        static::assertNotSame($originalPlain, $compressed);

        $plainStream = $this->streamFromContents($compressed);
        GzipStreamFilter::appendDecompression('file.txt', $plainStream);

        $plain = stream_get_contents($plainStream);
        fclose($plainStream);
        static::assertSame(
            str_split(base64_encode($originalPlain), 64),
            str_split(base64_encode($plain), 64)
        );
    }

    /**
     * @test
     */
    public function detect_file_header_corruption(): void
    {
        $originalPlain = uniqid('contents_');

        $compressedStream = $this->streamFromContents($originalPlain);
        GzipStreamFilter::appendCompression('file.txt', $compressedStream);
        $compressed = stream_get_contents($compressedStream);
        fclose($compressedStream);
        static::assertNotSame($originalPlain, $compressed);

        $compressed = substr($compressed, 10);

        $plainStream = $this->streamFromContents($compressed);
        GzipStreamFilter::appendDecompression('file.txt', $plainStream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Stream/');
        stream_get_contents($plainStream);
    }

    /**
     * @test
     */
    public function detect_file_checksum_corruption(): void
    {
        $originalPlain = uniqid('contents_');

        $compressedStream = $this->streamFromContents($originalPlain);
        GzipStreamFilter::appendCompression('file.txt', $compressedStream);
        $compressed = stream_get_contents($compressedStream);
        fclose($compressedStream);
        static::assertNotSame($originalPlain, $compressed);

        // Nullify CRC32 bytes
        $compressed[-8] = "\x00";
        $compressed[-7] = "\x00";
        $compressed[-6] = "\x00";
        $compressed[-5] = "\x00";

        $plainStream = $this->streamFromContents($compressed);
        GzipStreamFilter::appendDecompression('file.txt', $plainStream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CRC32 checksum failed for file.txt/');
        stream_get_contents($plainStream);
    }

    /**
     * @test
     */
    public function detect_file_size_corruption(): void
    {
        $originalPlain = uniqid('contents_');

        $compressedStream = $this->streamFromContents($originalPlain);
        GzipStreamFilter::appendCompression('file.txt', $compressedStream);
        $compressed = stream_get_contents($compressedStream);
        fclose($compressedStream);
        static::assertNotSame($originalPlain, $compressed);

        // Nullify size bytes
        $compressed[-4] = "\x00";
        $compressed[-3] = "\x00";
        $compressed[-2] = "\x00";
        $compressed[-1] = "\x00";

        $plainStream = $this->streamFromContents($compressed);
        GzipStreamFilter::appendDecompression('file.txt', $plainStream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/File size differs for file.txt/');
        stream_get_contents($plainStream);
    }

    /**
     * @test
     */
    public function regression_gzipped_file(): void
    {
        $stream = fopen(__DIR__.'/gzipped_file.txt.gz', 'r');

        GzipStreamFilter::appendDecompression('gzipped_file.txt', $stream);

        $plain = stream_get_contents($stream);
        fclose($stream);

        static::assertStringStartsWith('<p>Lorem ipsum', $plain);
        static::assertStringEndsWith('afferat. </p>'."\n", $plain);
    }

    /**
     * @test
     */
    public function regression_gzipped_file_corrupted(): void
    {
        $stream = fopen(__DIR__.'/gzipped_file_corrupted.txt.gz', 'r');

        GzipStreamFilter::appendDecompression('gzipped_file.txt', $stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CRC32 checksum failed for gzipped_file.txt/');

        stream_get_contents($stream);
    }

    /**
     * @test
     */
    public function regression_gzipped_stream(): void
    {
        $stream = fopen(__DIR__.'/gzipped_stream.txt.gz', 'r');

        GzipStreamFilter::appendDecompression('gzipped_stream.txt', $stream);

        $plain = stream_get_contents($stream);
        fclose($stream);

        static::assertStringStartsWith('<p>Lorem ipsum', $plain);
        static::assertStringEndsWith('afferat. </p>'."\n", $plain);
    }

    /**
     * @test
     */
    public function prohibit_filename_with_nullbyte(): void
    {
        $originalPlain = uniqid('contents_');

        $compressedStream = $this->streamFromContents($originalPlain);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Filename cannot contain null-bytes/');

        GzipStreamFilter::appendCompression("\0".'gzipped_stream.txt', $compressedStream);
    }

    /**
     * @return resource
     */
    private function streamFromContents(string $contents)
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
