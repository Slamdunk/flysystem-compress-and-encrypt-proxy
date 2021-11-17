<?php

declare(strict_types=1);

namespace SlamCompressAndEncryptProxy\Test;

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use SlamCompressAndEncryptProxy\AbstractProxyAdapter;

/**
 * @covers \SlamCompressAndEncryptProxy\AbstractProxyAdapter
 *
 * @internal
 */
final class AbstractProxyAdapterTest extends FilesystemAdapterTestCase
{
    private ?AbstractProxyAdapter $customAdapter = null;
    private LocalFilesystemAdapter $localAdapter;
    private string $remoteMock;

    protected function setUp(): void
    {
        $testToken = (int) getenv('TEST_TOKEN');
        $this->remoteMock = __DIR__.'/assets/'.$testToken.'_remote-mock';
        reset_function_mocks();
        delete_directory($this->remoteMock);
    }

    protected function tearDown(): void
    {
        reset_function_mocks();
        delete_directory($this->remoteMock);
    }

    public function adapter(): FilesystemAdapter
    {
        if (null === $this->customAdapter) {
            $this->localAdapter = new LocalFilesystemAdapter($this->remoteMock);
            $this->customAdapter = new class($this->localAdapter) extends AbstractProxyAdapter {
                public static function getRemoteFileExtension(): string
                {
                    return '.foo';
                }

                /**
                 * {@inheritDoc}
                 */
                public function writeStream(string $path, $contents, Config $config): void
                {
                    $this->getRemoteAdapter()->writeStream($this->getRemotePath($path), $contents, $config);
                }

                /**
                 * {@inheritDoc}
                 */
                public function readStream(string $path)
                {
                    return $this->getRemoteAdapter()->readStream($this->getRemotePath($path));
                }
            };
        }

        return $this->customAdapter;
    }

    /**
     * @test
     */
    public function only_list_files_with_specific_extension(): void
    {
        $adapter = $this->adapter();
        $this->localAdapter->write('file1.txt', uniqid(), new Config());
        $adapter->write('file2.txt', uniqid(), new Config());
        $this->localAdapter->write('file3.txt', uniqid(), new Config());
        $adapter->write('file4.txt', uniqid(), new Config());

        $expectedLocal = [
            'file1.txt',
            'file2.txt.foo',
            'file3.txt',
            'file4.txt.foo',
        ];
        $actualLocal = [];
        foreach ($this->localAdapter->listContents('/', true) as $storage) {
            $actualLocal[] = $storage->path();
        }
        sort($actualLocal);

        static::assertSame($expectedLocal, $actualLocal);

        $expectedCustom = [
            'file2.txt',
            'file4.txt',
        ];
        $actualCustom = [];
        foreach ($adapter->listContents('/', true) as $storage) {
            $actualCustom[] = $storage->path();
        }
        sort($actualCustom);

        static::assertSame($expectedCustom, $actualCustom);
    }

    /**
     * @test
     */
    public function fetching_unknown_mime_type_of_a_file(): void
    {
        static::markTestSkipped('It\'s up to the developer choosing if relying or not to this functionality');
    }

    /**
     * @test
     */
    public function deleting_a_directory(): void
    {
        $filename = uniqid('file_');
        $adapter = $this->adapter();
        $adapter->write($filename, uniqid(), new Config());

        static::assertTrue($adapter->fileExists($filename));

        $adapter->deleteDirectory('/');

        static::assertFalse($adapter->fileExists($filename));
    }

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        throw new RuntimeException('Only non-static adapter creation allowed');
    }
}
