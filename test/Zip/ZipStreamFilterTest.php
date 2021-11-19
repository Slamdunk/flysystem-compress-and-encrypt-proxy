<?php

declare(strict_types=1);

namespace SlamFlysystem\Test\Zip;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SlamFlysystem\Zip\ZipStreamFilter;

/**
 * @covers \SlamFlysystem\Zip\ZipStreamFilter
 *
 * @internal
 */
final class ZipStreamFilterTest extends TestCase
{
    protected function setUp(): void
    {
        ZipStreamFilter::register();
    }

    /**
     * @test
     */
    public function stream_filter_zip_stream(): void
    {
        $originalPlain = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, '
            .'sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut '
            .'enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi '
            .'ut aliquip ex ea commodo consequat. Duis aute irure dolor in '
            .'reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla '
            .'pariatur. Excepteur sint occaecat cupidatat non proident, sunt in '
            .'culpa qui officia deserunt mollit anim id est laborum.'."\n";

        $compressedStream = $this->streamFromContents($originalPlain);
        ZipStreamFilter::appendCompression('file.txt', $compressedStream);

        $compressed = stream_get_contents($compressedStream);
        fclose($compressedStream);
        static::assertNotSame($originalPlain, $compressed);

        $plainStream = $this->streamFromContents($compressed);
        ZipStreamFilter::appendDecompression('file.txt', $plainStream);

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
        ZipStreamFilter::appendCompression('file.txt', $compressedStream);
        $compressed = stream_get_contents($compressedStream);
        fclose($compressedStream);
        static::assertNotSame($originalPlain, $compressed);

        $compressed = substr($compressed, 10);

        $plainStream = $this->streamFromContents($compressed);
        ZipStreamFilter::appendDecompression('file.txt', $plainStream);

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
        ZipStreamFilter::appendCompression('file.txt', $compressedStream);
        $compressed = stream_get_contents($compressedStream);
        fclose($compressedStream);
        static::assertNotSame($originalPlain, $compressed);

        // Nullify CRC32 bytes
        $dataDescriptorPos = strpos($compressed, pack('V', 0x08074B50));
        static::assertNotFalse($dataDescriptorPos);
        $compressed[$dataDescriptorPos + 4] = "\0";
        $compressed[$dataDescriptorPos + 5] = "\0";
        $compressed[$dataDescriptorPos + 6] = "\0";
        $compressed[$dataDescriptorPos + 7] = "\0";

        $plainStream = $this->streamFromContents($compressed);
        ZipStreamFilter::appendDecompression('file.txt', $plainStream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CRC32 checksum failed for file.txt/');
        stream_get_contents($plainStream);
    }

    /**
     * @test
     */
    public function regression_zipped_file(): void
    {
        $stream = fopen(__DIR__.'/zipped_file.txt.zip', 'r');

        ZipStreamFilter::appendDecompression('zipped_file.txt', $stream);

        $plain = stream_get_contents($stream);
        fclose($stream);

        static::assertStringStartsWith('<p>Lorem ipsum', $plain);
        static::assertStringEndsWith('afferat. </p>'."\n", $plain);
    }

    /**
     * @test
     */
    public function regression_file_stream(): void
    {
        $originalPlain = file_get_contents(__FILE__);
        $fileHandler = fopen(__FILE__, 'r');

        ZipStreamFilter::appendCompression('file.txt', $fileHandler);

        $compressed = stream_get_contents($fileHandler);
        fclose($fileHandler);
        static::assertNotSame($originalPlain, $compressed);

        $plainStream = $this->streamFromContents($compressed);
        ZipStreamFilter::appendDecompression('file.txt', $plainStream);

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
    public function prohibit_filename_with_nullbyte(): void
    {
        $originalPlain = uniqid('contents_');

        $compressedStream = $this->streamFromContents($originalPlain);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Filename cannot contain null-bytes/');

        ZipStreamFilter::appendCompression("\0".'zipipped_stream.txt', $compressedStream);
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
