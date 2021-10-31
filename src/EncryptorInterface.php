<?php

declare(strict_types=1);

namespace SlamSymmetricEncryption;

interface EncryptorInterface
{
    public function encrypt(string $plaintextMessage): string;

    public function decrypt(string $encryptedMessage): string;
}
