<?php

declare(strict_types=1);

namespace SlamCompressAndEncryptProxy\Test;

use InvalidArgumentException;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use SlamCompressAndEncryptProxy\EncryptAdapter;
use SlamCompressAndEncryptProxy\GzipAdapter;

/**
 * @covers \SlamCompressAndEncryptProxy\EncryptAdapter
 * @covers \SlamCompressAndEncryptProxy\EncryptStreamFilter::register
 * @covers \SlamCompressAndEncryptProxy\GzipAdapter
 * @covers \SlamCompressAndEncryptProxy\GzipStreamFilter::register
 *
 * @internal
 */
final class CompressAndEncryptAdapterTest extends FilesystemAdapterTestCase
{
    private ?FilesystemAdapter $customAdapter = null;
    private ?string $key = null;
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
            $this->customAdapter = new GzipAdapter(
                new EncryptAdapter(
                    new LocalFilesystemAdapter($this->remoteMock),
                    $this->key ?? EncryptAdapter::generateKey()
                )
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

        new EncryptAdapter(
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
            EncryptAdapter::generateKey(),
            EncryptAdapter::generateKey()
        );
    }

    /**
     * @test
     */
    public function generate_long_enough_key(): void
    {
        static::assertGreaterThan(43, \strlen(EncryptAdapter::generateKey()));
    }

    /**
     * @test
     */
    public function fetching_file_size(): void
    {
        $adapter = $this->adapter();
        $this->givenWeHaveAnExistingFile('path.txt', 'contents');

        $attributes = $adapter->fileSize('path.txt');
        static::assertInstanceOf(FileAttributes::class, $attributes);
        static::assertSame(84, $attributes->fileSize());
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
    public function fetching_the_mime_type_of_an_svg_file(): void
    {
        static::markTestSkipped('It\'s up to the developer choosing if relying or not to this functionality');
    }

    /**
     * Delete this test once https://github.com/thephpleague/flysystem/pull/1375 is released.
     *
     * @test
     */
    public function writing_a_file_with_a_stream(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $writeStream = stream_with_contents('contents');
            self::assertIsResource($writeStream);

            $adapter->writeStream('path.txt', $writeStream, new Config());
            fclose($writeStream);
            $fileExists = $adapter->fileExists('path.txt');

            $this->assertTrue($fileExists);
        });
    }

    /**
     * Delete this test once https://github.com/thephpleague/flysystem/pull/1375 is released.
     *
     * @test
     */
    public function writing_a_file_with_an_empty_stream(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $writeStream = stream_with_contents('');
            self::assertIsResource($writeStream);

            $adapter->writeStream('path.txt', $writeStream, new Config());
            fclose($writeStream);
            $fileExists = $adapter->fileExists('path.txt');

            $this->assertTrue($fileExists);

            $contents = $adapter->read('path.txt');
            $this->assertSame('', $contents);
        });
    }

    /**
     * @test
     */
    public function writing_a_file_writes_a_compressed_and_encrypted_file(): void
    {
        $adapter = $this->adapter();

        $contents = uniqid('contents_');
        $adapter->write('/file.txt', $contents, new Config());

        static::assertFileDoesNotExist($this->remoteMock.'/file.txt');
        static::assertStringNotEqualsFile(
            $this->remoteMock.'/file.txt'.GzipAdapter::getRemoteFileExtension().EncryptAdapter::getRemoteFileExtension(),
            $contents
        );
    }

    /**
     * @test
     */
    public function regression(): void
    {
        $this->key = 'RjWFkMrJS4Jd5TDdhYJNAWdfSEL5nptu4KQHgkeKGI0=';
        $adapter = $this->adapter();
        $originalPlain = 'foobar';
        $remoteFilename = $this->remoteMock.'/file.txt'.GzipAdapter::getRemoteFileExtension().EncryptAdapter::getRemoteFileExtension();

        // To recreate assets, uncomment following lines
        // $adapter->write('/file.txt', $originalPlain, new Config());
        // var_dump(base64_encode(file_get_contents($remoteFilename))); exit;

        $content = base64_decode('LiSeaSq90VHp8dl5fGUjGRC6rdfUP3RR1TxL3sJmjNIgk8dpcAj2sNmqNMfU9JYW1iimCXb1AQmPqxAPFYG8r22wjTNgZfG+W55nmSDg33I6zQ==', true);
        file_put_contents($remoteFilename, $content);

        static::assertSame($originalPlain, $adapter->read('/file.txt'));
    }

    /**
     * @test
     */
    public function reading_multiple_files(): void
    {
        $adapter = $this->adapter();
        $this->givenWeHaveAnExistingFile('path1.txt', '123');
        $this->givenWeHaveAnExistingFile('path2.txt', '456');

        static::assertSame('123', $adapter->read('path1.txt'));
        static::assertSame('456', $adapter->read('path2.txt'));
    }

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        throw new RuntimeException('Only non-static adapter creation allowed');
    }
}
