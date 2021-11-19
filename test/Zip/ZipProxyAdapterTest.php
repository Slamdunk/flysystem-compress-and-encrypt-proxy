<?php

declare(strict_types=1);

namespace SlamFlysystem\Test\Zip;

use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SlamFlysystem\Test\AbstractFilesystemAdapterTestCase;
use SlamFlysystem\Zip\ZipProxyAdapter;
use ZipArchive;

/**
 * @covers \SlamFlysystem\Zip\ZipProxyAdapter
 * @covers \SlamFlysystem\Zip\ZipStreamFilter::register
 *
 * @internal
 */
final class ZipProxyAdapterTest extends AbstractFilesystemAdapterTestCase
{
    public function adapter(): FilesystemAdapter
    {
        if (null === $this->customAdapter) {
            $this->customAdapter = new ZipProxyAdapter(
                $this->remoteAdapter = new LocalFilesystemAdapter($this->remoteMock),
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
        $remoteFilename = $this->remoteMock.'/file.txt'.ZipProxyAdapter::getRemoteFileExtension();

        // To recreate assets, uncomment following lines
        // $adapter->write('/file.txt', $originalPlain, new Config());
        // var_dump(base64_encode(file_get_contents($remoteFilename))); exit;

        $content = base64_decode(
            'UEsDBC0ACAAIAH10c1MAAAAA//////////8JABQAL2ZpbGUudHh0AQAQAAAAAAAAAAAAAAAAAAAAAABLy89PSiwCAFBLBwiVH/aeCAAAAA'
            .'AAAAAGAAAAAAAAAFBLAQIDBi0ACAAIAH10c1OVH/aeCAAAAAYAAAAJAAAAAAAAAAAAIAAAAAAAAAAvZmlsZS50eHRQSwYGLAAAAAAAAAADB'
            .'i0AAAAAAAAAAAABAAAAAAAAAAEAAAAAAAAANwAAAAAAAABbAAAAAAAAAFBLBgcAAAAAkgAAAAAAAAABAAAAUEsFBgAAAAABAAEANwAAAFsA'
            .'AAAAAA==',
            true
        );
        file_put_contents($remoteFilename, $content);

        static::assertSame($originalPlain, $adapter->read('/file.txt'));
    }

    /**
     * @test
     */
    public function save_current_time_within_zip(): void
    {
        // ZipArchive doesn't respect PHP timezone, so we need
        // to set a day leap to get the test pass
        $start = time() - 86400;
        $this->adapter()->write('file.txt', 'foobar', new Config());
        $end = time() + 86400;

        $remoteFilename = $this->remoteMock.'/file.txt'.ZipProxyAdapter::getRemoteFileExtension();

        static::assertFileExists($remoteFilename);

        $zipArchive = new ZipArchive();
        $zipArchive->open($remoteFilename, ZipArchive::RDONLY);

        static::assertSame(1, $zipArchive->numFiles);

        $stat = $zipArchive->statIndex(0);

        static::assertIsArray($stat);
        static::assertArrayHasKey('mtime', $stat);
        static::assertIsInt($stat['mtime']);

        $mtime = $stat['mtime'];
        unset($stat['mtime']);

        $expected = [
            'name' => 'file.txt',
            'index' => 0,
            'crc' => 2666930069,
            'size' => 6,
            'comp_size' => 8,
            'comp_method' => 8,
            'encryption_method' => 0,
        ];

        static::assertSame($expected, $stat);

        static::assertGreaterThanOrEqual($start, $mtime);
        static::assertLessThanOrEqual($end, $mtime);
    }

    protected function getRemoteFileSize(): int
    {
        return 244;
    }
}
