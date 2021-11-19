<?php

declare(strict_types=1);

namespace SlamFlysystem\Test;

use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SlamFlysystem\Gzip\GzipProxyAdapter;
use SlamFlysystem\V1Encrypt\V1EncryptProxyAdapter;
use SlamFlysystem\Zip\ZipProxyAdapter;

/**
 * @covers \SlamFlysystem\Gzip\GzipStreamFilter::register
 * @covers \SlamFlysystem\V1Encrypt\V1EncryptStreamFilter::register
 * @covers \SlamFlysystem\Zip\ZipStreamFilter::register
 *
 * @internal
 */
final class IntegrationTest extends AbstractFilesystemAdapterTestCase
{
    private string $key;

    public function adapter(): FilesystemAdapter
    {
        if (null === $this->customAdapter) {
            $this->customAdapter =
                new ZipProxyAdapter(
                    new GzipProxyAdapter(
                        new V1EncryptProxyAdapter(
                            new LocalFilesystemAdapter($this->remoteMock),
                            $this->key ?? V1EncryptProxyAdapter::generateKey()
                        )
                    )
                )
            ;
        }

        return $this->customAdapter;
    }

    /**
     * @test
     */
    public function regression(): void
    {
        $this->key = 'RjWFkMrJS4Jd5TDdhYJNAWdfSEL5nptu4KQHgkeKGI0=';
        $adapter = $this->adapter();
        $originalPlain = 'foobar';
        $remoteFilename = $this->remoteMock.'/file.txt'
            .ZipProxyAdapter::getRemoteFileExtension()
            .GzipProxyAdapter::getRemoteFileExtension()
            .V1EncryptProxyAdapter::getRemoteFileExtension()
        ;

        // To recreate assets, uncomment following lines
        // $adapter->write('/file.txt', $originalPlain, new Config());
        // var_dump(base64_encode(file_get_contents($remoteFilename))); exit;

        $content = base64_decode(
            '9bK82i6BnXsA6Oi1uBX1VZI1WT9VR/DAzz3yEjFQXPBfIGSYsN+kZG8Y0qE0Iy8lpxNH17/Ixcxmw9nNt2YzxDYufFW4Bq36nBedAwFntY'
            .'bwsr/+b0D2vnTzDEaV8ttBmO1gE0rEYuVxPUkLccZtOmAFcozrJtbO22V82bDKouAPeXmArED61ylwzV1lsFy70of6SH1rheZQ+6pW2d/kA'
            .'WiJwX/tKzx+jS1iv65oBYb86/y5vXOjG3WBWCA=',
            true
        );
        file_put_contents($remoteFilename, $content);

        static::assertSame($originalPlain, $adapter->read('/file.txt'));
    }

    protected function getRemoteFileSize(): int
    {
        return 190;
    }
}
