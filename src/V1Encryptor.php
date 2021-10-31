<?php

declare(strict_types=1);

namespace SlamSymmetricEncryption;

use SodiumException;

final class V1Encryptor implements EncryptorInterface
{
    private string $key;

    public function __construct(string $key)
    {
        $this->key = sodium_base642bin($key, SODIUM_BASE64_VARIANT_ORIGINAL, '');
    }

    public static function generateKey(): string
    {
        return sodium_bin2base64(
            random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );
    }

    public function encrypt(string $plaintextMessage): string
    {
        $randnonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $nonce = sodium_crypto_generichash(
            $plaintextMessage,
            $randnonce,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
        );

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintextMessage,
                '',
                $nonce,
                $this->key
            );
        } catch (SodiumException $sodiumException) {
            throw new EncryptorException('Encryption failed', 0, $sodiumException);
        }

        return sodium_bin2base64($nonce, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)
            .'.'
            .sodium_bin2base64($ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)
        ;
    }

    public function decrypt(string $encryptedMessage): string
    {
        $dotPosition = strpos($encryptedMessage, '.');

        if (false === $dotPosition) {
            throw new EncryptorException('Invalid encrypted message format');
        }

        $nonce = substr($encryptedMessage, 0, $dotPosition);
        $ciphertext = substr($encryptedMessage, 1 + $dotPosition);

        $nonce = sodium_base642bin($nonce, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        $ciphertext = sodium_base642bin($ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');

        $return = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            '',
            $nonce,
            $this->key
        );

        if (false === $return) {
            throw new EncryptorException('Decryption failed');
        }

        return $return;
    }
}
