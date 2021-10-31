<?php

declare(strict_types=1);

namespace SlamSymmetricEncryptionTest;

use PHPUnit\Framework\TestCase;
use SlamSymmetricEncryption\EncryptorInterface;
use SlamSymmetricEncryption\V1Encryptor;

/**
 * @covers \SlamSymmetricEncryption\V1Encryptor
 *
 * @internal
 */
final class EncryptorsTest extends TestCase
{
    /**
     * @param callable():EncryptorInterface $encryptorFactory
     *
     * @dataProvider provideEncryptors
     */
    public function testLocalEncryption(callable $encryptorFactory): void
    {
        $encryptor = $encryptorFactory();

        $message = uniqid('foo_');

        $encrypted = $encryptor->encrypt($message);

        static::assertNotSame($encrypted, $message);

        $decrypted = $encryptor->decrypt($encrypted);

        static::assertSame($message, $decrypted);
    }

    /**
     * @return array<string, list<callable(): EncryptorInterface>>
     */
    public function provideEncryptors(): array
    {
        return [
            V1Encryptor::class => [static function (): EncryptorInterface {
                return new V1Encryptor(V1Encryptor::generateKey());
            }],
        ];
    }
}
