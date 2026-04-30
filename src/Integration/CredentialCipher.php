<?php

declare(strict_types=1);

namespace Kosmokrator\Integration;

final class CredentialCipher
{
    private const PREFIX = 'enc:v1:';

    public function encrypt(string $value): string
    {
        if ($value === '' || str_starts_with($value, self::PREFIX) || ! function_exists('sodium_crypto_secretbox')) {
            return $value;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($value, $nonce, $this->key());

        return self::PREFIX.base64_encode($nonce.$ciphertext);
    }

    public function decrypt(?string $value): ?string
    {
        if ($value === null || ! str_starts_with($value, self::PREFIX) || ! function_exists('sodium_crypto_secretbox_open')) {
            return $value;
        }

        $payload = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key());

        return $plaintext === false ? null : $plaintext;
    }

    private function key(): string
    {
        $configured = getenv('KOSMO_CREDENTIAL_KEY') ?: getenv('KOSMO_SECRET_KEY');
        if (is_string($configured) && $configured !== '') {
            return hash('sha256', $configured, binary: true);
        }

        $path = $this->keyPath();
        if (! is_file($path)) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            file_put_contents($path, base64_encode(random_bytes(32)));
            @chmod($path, 0600);
        }

        $raw = trim((string) file_get_contents($path));
        $decoded = base64_decode($raw, true);

        return is_string($decoded) && strlen($decoded) >= 32
            ? substr($decoded, 0, 32)
            : hash('sha256', $raw, binary: true);
    }

    private function keyPath(): string
    {
        $home = getenv('HOME');
        $base = is_string($home) && $home !== '' ? $home : (getcwd() ?: '.');

        return rtrim($base, DIRECTORY_SEPARATOR).'/.kosmokrator/credentials.key';
    }
}
