<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Passport\Passport;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** @var array{private: string, public: string}|null */
    private static ?array $passportKeys = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists(Passport::class)) {
            Passport::$validateKeyPermissions = false;

            $keys = self::passportKeys();
            config([
                'passport.private_key' => $keys['private'],
                'passport.public_key' => $keys['public'],
            ]);
        }
    }

    /**
     * @return array{private: string, public: string}
     */
    private static function passportKeys(): array
    {
        if (self::$passportKeys !== null) {
            return self::$passportKeys;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new RuntimeException('Unable to generate test Passport keys.');
        }

        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);

        if (! is_string($privateKey) || ! is_array($details) || ! isset($details['key'])) {
            throw new RuntimeException('Unable to export test Passport keys.');
        }

        return self::$passportKeys = [
            'private' => $privateKey,
            'public' => $details['key'],
        ];
    }
}
