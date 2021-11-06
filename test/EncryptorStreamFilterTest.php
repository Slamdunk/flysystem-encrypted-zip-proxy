<?php

declare(strict_types=1);

namespace SlamCompressAndEncryptProxy\Test;

use PHPUnit\Framework\TestCase;
use SlamCompressAndEncryptProxy\EncryptorStreamFilter;

/**
 * @covers \SlamCompressAndEncryptProxy\EncryptorStreamFilter
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
            static::assertNotFalse(stream_filter_append($cipherStream, $additionalOutStream));
        }
        EncryptorStreamFilter::appendEncryption($cipherStream, $key);

        $cipher = stream_get_contents($cipherStream);
        fclose($cipherStream);
        static::assertNotSame($originalPlain, $cipher);

        $plainStream = $this->streamFromContents($cipher);
        EncryptorStreamFilter::appendDecryption($plainStream, $key);
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
    public function consecutive_filtering(): void
    {
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        EncryptorStreamFilter::register();

        $cipherStream1 = $this->streamFromContents('123');
        EncryptorStreamFilter::appendEncryption($cipherStream1, $key);

        $cipherStream2 = $this->streamFromContents('456');
        EncryptorStreamFilter::appendEncryption($cipherStream2, $key);

        $cipher1 = stream_get_contents($cipherStream1);
        $cipher2 = stream_get_contents($cipherStream2);

        fclose($cipherStream1);
        fclose($cipherStream2);

        $plainStream1 = $this->streamFromContents($cipher1);
        EncryptorStreamFilter::appendDecryption($plainStream1, $key);

        $plainStream2 = $this->streamFromContents($cipher2);
        EncryptorStreamFilter::appendDecryption($plainStream2, $key);

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
        $cipher = base64_decode('PMRzbW/xSj1WPnXp0DknCZvmM1Lv1XCYNbQH5wHozLpULVaGnoq7kVOuhg5Guew=', true);

        EncryptorStreamFilter::register();

        $plainStream = $this->streamFromContents($cipher);
        EncryptorStreamFilter::appendDecryption($plainStream, $key);

        $plain = stream_get_contents($plainStream);

        fclose($plainStream);

        static::assertSame('foobar', $plain);
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
