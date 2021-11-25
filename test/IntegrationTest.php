<?php

declare(strict_types=1);

namespace SlamFlysystem\Test;

use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SlamFlysystem\Gzip\GzipProxyAdapter;
use SlamFlysystem\V1Encrypt\V1EncryptProxyAdapter;
use SlamFlysystem\Zip\ZipProxyAdapter;

/**
 * @covers \SlamFlysystem\Gzip\GzipStreamFilter::filter
 * @covers \SlamFlysystem\Gzip\GzipStreamFilter::register
 * @covers \SlamFlysystem\V1Encrypt\V1EncryptStreamFilter::filter
 * @covers \SlamFlysystem\V1Encrypt\V1EncryptStreamFilter::register
 * @covers \SlamFlysystem\Zip\ZipStreamFilter::filter
 * @covers \SlamFlysystem\Zip\ZipStreamFilter::register
 *
 * @internal
 */
final class IntegrationTest extends AbstractFilesystemAdapterTestCase
{
    public function adapter(): FilesystemAdapter
    {
        if (null === $this->customAdapter) {
            $this->customAdapter =
                new ZipProxyAdapter(
                    new GzipProxyAdapter(
                        new V1EncryptProxyAdapter(
                            new LocalFilesystemAdapter($this->remoteMock),
                            V1EncryptProxyAdapter::generateKey()
                        )
                    )
                )
            ;
        }

        return $this->customAdapter;
    }

    /**
     * @param callable(string): FilesystemAdapter $adapterFactory
     * @test
     *
     * @dataProvider provideNestedRegressionFiles
     */
    public function regression(?callable $adapterFactory = null): void
    {
        static::assertNotNull($adapterFactory);
        $adapter = $adapterFactory(__DIR__);
        static::assertSame('foooooooooooooooooooooo', $adapter->read('/file.txt'));
    }

    public function provideNestedRegressionFiles(): array
    {
        $key = 'RjWFkMrJS4Jd5TDdhYJNAWdfSEL5nptu4KQHgkeKGI0=';

        return [
            'file.txt.zip.gz.v1encrypted' => [
                static function (string $remoteMock) use ($key): FilesystemAdapter {
                    return new ZipProxyAdapter(
                        new GzipProxyAdapter(
                            new V1EncryptProxyAdapter(
                                new LocalFilesystemAdapter($remoteMock),
                                $key
                            )
                        )
                    );
                },
            ],
            'file.txt.v1encrypted.zip.gz' => [
                static function (string $remoteMock) use ($key): FilesystemAdapter {
                    return new V1EncryptProxyAdapter(
                        new ZipProxyAdapter(
                            new GzipProxyAdapter(
                                new LocalFilesystemAdapter($remoteMock)
                            )
                        ),
                        $key
                    );
                },
            ],
            'file.txt.gz.v1encrypted.zip' => [
                static function (string $remoteMock) use ($key): FilesystemAdapter {
                    return new GzipProxyAdapter(
                        new V1EncryptProxyAdapter(
                            new ZipProxyAdapter(
                                new LocalFilesystemAdapter($remoteMock)
                            ),
                            $key
                        )
                    );
                },
            ],
        ];
    }

    protected function getRemoteFileSize(): int
    {
        return 190;
    }
}
