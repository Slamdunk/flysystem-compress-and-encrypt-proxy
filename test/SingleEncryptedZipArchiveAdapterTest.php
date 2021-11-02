<?php

declare(strict_types=1);

namespace SlamFlysystemSingleEncryptedZipArchiveTest;

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SlamFlysystemSingleEncryptedZipArchive\SingleEncryptedZipArchiveAdapter;

/**
 * @covers \SlamFlysystemSingleEncryptedZipArchive\SingleEncryptedZipArchiveAdapter
 *
 * @internal
 */
final class SingleEncryptedZipArchiveAdapterTest extends FilesystemAdapterTestCase
{
    private const REMOTE_MOCK = __DIR__.'/assets/remote-mock';
    private const LOCAL_WORKDIR = __DIR__.'/assets/local-workdir';

    protected function setUp(): void
    {
        reset_function_mocks();
        delete_directory(static::REMOTE_MOCK);
        delete_directory(static::LOCAL_WORKDIR);
    }

    protected function tearDown(): void
    {
        reset_function_mocks();
        delete_directory(static::REMOTE_MOCK);
        delete_directory(static::LOCAL_WORKDIR);
    }

    /**
     * @test
     */
    public function generated_keys_differ(): void
    {
        static::assertNotSame(
            SingleEncryptedZipArchiveAdapter::generateKey(),
            SingleEncryptedZipArchiveAdapter::generateKey()
        );
    }

    /**
     * @test
     */
    public function generate_long_enough_key(): void
    {
        static::assertSame(44, \strlen(SingleEncryptedZipArchiveAdapter::generateKey()));
    }

    /**
     * @test
     */
    public function fetching_unknown_mime_type_of_a_file(): void
    {
        static::markTestIncomplete();
    }

    /**
     * @test
     */
    public function deleting_a_directory(): void
    {
        $remoteMock = $this->createMock(FilesystemAdapter::class);
        $adapter = new SingleEncryptedZipArchiveAdapter(
            $remoteMock,
            SingleEncryptedZipArchiveAdapter::generateKey(),
            new LocalFilesystemAdapter(self::LOCAL_WORKDIR)
        );

        $path = uniqid('path_');
        $remoteMock
            ->expects(static::once())
            ->method('deleteDirectory')
            ->with(static::identicalTo($path))
        ;

        $adapter->deleteDirectory($path);
    }

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        return new SingleEncryptedZipArchiveAdapter(
            new LocalFilesystemAdapter(self::REMOTE_MOCK),
            SingleEncryptedZipArchiveAdapter::generateKey(),
            new LocalFilesystemAdapter(self::LOCAL_WORKDIR)
        );
    }
}
