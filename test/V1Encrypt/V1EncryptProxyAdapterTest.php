<?php

declare(strict_types=1);

namespace SlamFlysystem\Test\V1Encrypt;

use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SlamFlysystem\Test\AbstractFilesystemAdapterTestCase;
use SlamFlysystem\V1Encrypt\V1EncryptProxyAdapter;

/**
 * @covers \SlamFlysystem\V1Encrypt\V1EncryptProxyAdapter
 * @covers \SlamFlysystem\V1Encrypt\V1EncryptStreamFilter::register
 *
 * @internal
 */
final class V1EncryptProxyAdapterTest extends AbstractFilesystemAdapterTestCase
{
    private ?string $key = null;

    public function adapter(): FilesystemAdapter
    {
        if (null === $this->customAdapter) {
            $this->customAdapter = new V1EncryptProxyAdapter(
                new LocalFilesystemAdapter($this->remoteMock),
                $this->key ?? V1EncryptProxyAdapter::generateKey()
            );
        }

        return $this->customAdapter;
    }

    /**
     * @test
     */
    public function accept_long_enough_password_only(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new V1EncryptProxyAdapter(
            $this->createMock(FilesystemAdapter::class),
            base64_encode(random_bytes(8))
        );
    }

    /**
     * @test
     */
    public function generated_keys_differ(): void
    {
        static::assertNotSame(
            V1EncryptProxyAdapter::generateKey(),
            V1EncryptProxyAdapter::generateKey()
        );
    }

    /**
     * @test
     */
    public function generate_long_enough_key(): void
    {
        static::assertGreaterThan(43, \strlen(V1EncryptProxyAdapter::generateKey()));
    }

    /**
     * @test
     */
    public function regression(): void
    {
        $this->key = 'RjWFkMrJS4Jd5TDdhYJNAWdfSEL5nptu4KQHgkeKGI0=';
        $adapter = $this->adapter();
        $originalPlain = 'foobar';
        $remoteFilename = $this->remoteMock.'/file.txt'.V1EncryptProxyAdapter::getRemoteFileExtension();

        // To recreate assets, uncomment following lines
        // $adapter->write('/file.txt', $originalPlain, new Config());
        // var_dump(base64_encode(file_get_contents($remoteFilename))); exit;

        $content = base64_decode('Vywdliv8f210C5a3LVrgqL9h9ClZBmauNew22ZhigFVHtWFeEDIHDcB5EmLfgXQ=', true);
        file_put_contents($remoteFilename, $content);

        static::assertSame($originalPlain, $adapter->read('/file.txt'));
    }

    protected function getRemoteFileSize(): int
    {
        return 49;
    }
}
