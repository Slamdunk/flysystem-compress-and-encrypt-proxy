<?php

declare(strict_types=1);

namespace SlamFlysystem\Test\Gzip;

use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SlamFlysystem\Gzip\GzipProxyAdapter;
use SlamFlysystem\Test\AbstractFilesystemAdapterTestCase;

/**
 * @covers \SlamFlysystem\Gzip\GzipProxyAdapter
 * @covers \SlamFlysystem\Gzip\GzipStreamFilter::register
 *
 * @internal
 */
final class GzipProxyAdapterTest extends AbstractFilesystemAdapterTestCase
{
    public function adapter(): FilesystemAdapter
    {
        if (null === $this->customAdapter) {
            $this->customAdapter = new GzipProxyAdapter(
                new LocalFilesystemAdapter($this->remoteMock),
            );
        }

        return $this->customAdapter;
    }

    /**
     * @test
     */
    public function regression(): void
    {
        $adapter = $this->adapter();
        $originalPlain = 'foobar';
        $remoteFilename = $this->remoteMock.'/file.txt'.GzipProxyAdapter::getRemoteFileExtension();

        // To recreate assets, uncomment following lines
        // $adapter->write('/file.txt', $originalPlain, new Config());
        // var_dump(base64_encode(file_get_contents($remoteFilename))); exit;

        $content = base64_decode('H4sICP61l2EAA2ZpbGUudHh0AEvLz09KLAIAlR/2ngYAAAA=', true);
        file_put_contents($remoteFilename, $content);

        static::assertSame($originalPlain, $adapter->read('/file.txt'));
    }

    protected function getRemoteFileSize(): int
    {
        return 37;
    }
}
