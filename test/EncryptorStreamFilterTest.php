<?php

declare(strict_types=1);

namespace SlamFlysystemEncryptedZipProxyTest;

use PHPUnit\Framework\TestCase;
use SlamFlysystemEncryptedZipProxy\EncryptorStreamFilter;

/**
 * @covers \SlamFlysystemEncryptedZipProxy\EncryptorStreamFilter
 *
 * @internal
 */
final class EncryptorStreamFilterTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider provideCases
     */
    public function stream_filter_encrypt_stream(string $originalPlain, ?string $additionalOutStream, ?string $additionalInStream): void
    {
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        EncryptorStreamFilter::register();

        $cipherStream = $this->streamFromContents($originalPlain);
        if (null !== $additionalOutStream) {
            stream_filter_append($cipherStream, $additionalOutStream);
        }
        EncryptorStreamFilter::appendEncryption($cipherStream, $key);

        $cipher = stream_get_contents($cipherStream);
        static::assertNotSame($originalPlain, $cipher);

        $plainStream = $this->streamFromContents($cipher);
        EncryptorStreamFilter::appendDecryption($plainStream, $key);
        if (null !== $additionalInStream) {
            stream_filter_append($plainStream, $additionalInStream);
        }

        $plain = stream_get_contents($plainStream);
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
            'alone-random' => [base64_encode(random_bytes(10000)), null, null],
            'zlib-short' => ['foo', 'zlib.deflate', 'zlib.inflate'],
            'zlib-long' => [str_repeat('foo', 10000), 'zlib.deflate', 'zlib.inflate'],
            'zlib-random' => [base64_encode(random_bytes(10000)), 'zlib.deflate', 'zlib.inflate'],
        ];
    }

    /**
     * @test
     */
    public function consecutive_filtering(): void
    {
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        EncryptorStreamFilter::register();

        $cipherStream1 = $this->streamFromContents('123');
        stream_filter_append($cipherStream1, 'zlib.deflate');
        EncryptorStreamFilter::appendEncryption($cipherStream1, $key);

        $cipherStream2 = $this->streamFromContents('456');
        stream_filter_append($cipherStream2, 'zlib.deflate');
        EncryptorStreamFilter::appendEncryption($cipherStream2, $key);

        $cipher1 = stream_get_contents($cipherStream1);
        $cipher2 = stream_get_contents($cipherStream2);

        $plainStream1 = $this->streamFromContents($cipher1);
        EncryptorStreamFilter::appendDecryption($plainStream1, $key);
        stream_filter_append($plainStream1, 'zlib.inflate');

        $plainStream2 = $this->streamFromContents($cipher2);
        EncryptorStreamFilter::appendDecryption($plainStream2, $key);
        stream_filter_append($plainStream2, 'zlib.inflate');

        $plain1 = stream_get_contents($plainStream1);
        $plain2 = stream_get_contents($plainStream2);

        static::assertSame('123', $plain1);
        static::assertSame('456', $plain2);
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
