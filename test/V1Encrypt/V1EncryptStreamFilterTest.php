<?php

declare(strict_types=1);

namespace SlamFlysystem\Test\V1Encrypt;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SlamFlysystem\V1Encrypt\V1EncryptStreamFilter;

/**
 * @covers \SlamFlysystem\V1Encrypt\V1EncryptStreamFilter
 *
 * @internal
 */
final class V1EncryptStreamFilterTest extends TestCase
{
    protected function setUp(): void
    {
        V1EncryptStreamFilter::register();
    }

    /**
     * @test
     *
     * @dataProvider provideCases
     */
    public function stream_filter_encrypt_stream(string $originalPlain, ?string $additionalOutStream, ?string $additionalInStream): void
    {
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();

        $cipherStream = $this->streamFromContents($originalPlain);
        if (null !== $additionalOutStream) {
            static::assertNotFalse(stream_filter_append($cipherStream, $additionalOutStream));
        }
        V1EncryptStreamFilter::appendEncryption($cipherStream, $key);

        $cipher = stream_get_contents($cipherStream);
        fclose($cipherStream);
        static::assertNotSame($originalPlain, $cipher);

        $plainStream = $this->streamFromContents($cipher);
        V1EncryptStreamFilter::appendDecryption($plainStream, $key);
        if (null !== $additionalInStream) {
            static::assertNotFalse(stream_filter_append($plainStream, $additionalInStream));
        }

        $plain = stream_get_contents($plainStream);
        fclose($plainStream);
        static::assertSame(
            str_split(base64_encode($originalPlain), 1024),
            str_split(base64_encode($plain), 1024)
        );
    }

    public function provideCases(): array
    {
        return [
            'alone-short' => ['foo', null, null],
            'alone-long' => [str_repeat('foo', 10000), null, null],
            'alone-random-short' => [base64_encode(random_bytes(1000)), null, null],
            'alone-random-long' => [base64_encode(random_bytes(100000)), null, null],
            'zlib-short' => ['foo', 'zlib.deflate', 'zlib.inflate'],
            'zlib-long' => [str_repeat('foo', 1000000), 'zlib.deflate', 'zlib.inflate'],
            'zlib-random-short' => [base64_encode(random_bytes(1000)), 'zlib.deflate', 'zlib.inflate'],
            'zlib-random-long' => [base64_encode(random_bytes(100000)), 'zlib.deflate', 'zlib.inflate'],
        ];
    }

    /**
     * @test
     */
    public function detect_file_corruption(): void
    {
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();

        $chunkSize = 8192;
        $originalPlain = random_bytes(10 * ($chunkSize - SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES));

        $cipherStream = $this->streamFromContents($originalPlain);
        V1EncryptStreamFilter::appendEncryption($cipherStream, $key);

        $cipher = stream_get_contents($cipherStream);
        fclose($cipherStream);
        static::assertNotSame($originalPlain, $cipher);
        static::assertSame(
            (10 * $chunkSize) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES,
            \strlen($cipher)
        );

        $truncatedCipher = substr($cipher, 0, (9 * $chunkSize) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $plainStream = $this->streamFromContents($truncatedCipher);
        V1EncryptStreamFilter::appendDecryption($plainStream, $key);

        $this->expectException(RuntimeException::class);
        stream_get_contents($plainStream);
    }

    /**
     * @test
     */
    public function consecutive_filtering(): void
    {
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();

        $cipherStream1 = $this->streamFromContents('123');
        V1EncryptStreamFilter::appendEncryption($cipherStream1, $key);

        $cipherStream2 = $this->streamFromContents('456');
        V1EncryptStreamFilter::appendEncryption($cipherStream2, $key);

        $cipher1 = stream_get_contents($cipherStream1);
        $cipher2 = stream_get_contents($cipherStream2);

        fclose($cipherStream1);
        fclose($cipherStream2);

        $plainStream1 = $this->streamFromContents($cipher1);
        V1EncryptStreamFilter::appendDecryption($plainStream1, $key);

        $plainStream2 = $this->streamFromContents($cipher2);
        V1EncryptStreamFilter::appendDecryption($plainStream2, $key);

        $plain1 = stream_get_contents($plainStream1);
        $plain2 = stream_get_contents($plainStream2);

        fclose($plainStream1);
        fclose($plainStream2);

        static::assertSame('123', $plain1);
        static::assertSame('456', $plain2);
    }

    /**
     * @test
     */
    public function regression(): void
    {
        $key = base64_decode('Z+Ry4nDufKcJ19pU2pEMgGiac9GBWFjEV18Cpb9jxRM=', true);
        $originalPlain = 'foobar';

        // To recreate assets, uncomment following lines
        // $cipherStream = $this->streamFromContents($content);
        // V1EncryptStreamFilter::appendEncryption($cipherStream, $key);
        // $cipher = stream_get_contents($cipherStream);
        // fclose($cipherStream);
        // var_dump(base64_encode($cipher)); exit;

        $cipher = base64_decode('UbQpWpd03RyW8a2YiVQSlkmfeEN76IgkN67yPRb7UoXcxUeL7LmUGizXL7zwbtc=', true);

        $plainStream = $this->streamFromContents($cipher);
        V1EncryptStreamFilter::appendDecryption($plainStream, $key);

        $plain = stream_get_contents($plainStream);

        fclose($plainStream);

        static::assertSame($originalPlain, $plain);
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
