<?php
declare(strict_types=1);

namespace TheMusicDev\Files\Test\TestCase\Lib;

use Aws\S3\S3Client;
use Cake\Core\Configure;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use TheMusicDev\Files\Lib\FileStore;
use TheMusicDev\Files\Test\TestCase\FilesTestCase;

/**
 * Opt-in integration test against a REAL S3-compatible server (RustFS,
 * MinIO, AWS, …). Skipped unless S3_TEST_ENDPOINT is set, so normal runs
 * and CI stay hermetic (they use FakeS3Client instead).
 *
 * Run manually:
 *
 *   docker run --rm -d --name rustfs-test -p 9009:9000 \
 *     -e RUSTFS_ACCESS_KEY=files-test -e RUSTFS_SECRET_KEY=files-test-secret \
 *     rustfs/rustfs:latest /data
 *   S3_TEST_ENDPOINT=http://127.0.0.1:9009 \
 *     vendor/bin/phpunit --group s3-integration
 *   docker rm -f rustfs-test
 *
 * Override S3_TEST_KEY / S3_TEST_SECRET / S3_TEST_BUCKET / S3_TEST_REGION
 * to point the group at any other endpoint.
 */
#[Group('s3-integration')]
final class FileStoreS3IntegrationTest extends FilesTestCase
{
    private string $bucket;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('S3_TEST_ENDPOINT') === false || getenv('S3_TEST_ENDPOINT') === '') {
            $this->markTestSkipped('Opt-in: set S3_TEST_ENDPOINT to run against a real S3-compatible server.');
        }
        $this->bucket = (string)(getenv('S3_TEST_BUCKET') ?: 'files-test-bucket');

        Configure::write('Files.backend', 's3');
        Configure::write('Files.backends.s3', [
            'class' => AwsS3V3Adapter::class,
            'bucket' => $this->bucket,
            'prefix' => 'test/',
            'region' => (string)(getenv('S3_TEST_REGION') ?: 'us-east-1'),
            'key' => (string)(getenv('S3_TEST_KEY') ?: 'files-test'),
            'secret' => (string)(getenv('S3_TEST_SECRET') ?: 'files-test-secret'),
            'endpoint' => (string)getenv('S3_TEST_ENDPOINT'),
            'pathStyle' => getenv('S3_TEST_PATH_STYLE') !== '0',
        ]);

        // RustFS et al. ship with empty storage — the test bucket must exist.
        $this->client()->createBucket(['Bucket' => $this->bucket]);
    }

    public function testALifecycleAgainstTheRealServer(): void
    {
        $store = new FileStore();

        $row = $store->put($this->upload("%PDF-1.4\n%\xe2\xe3\xcf\xd3\nreal s3 round trip\n"), 'uploads.misc');
        $this->assertTrue($store->exists($row));
        $this->assertSame("%PDF-1.4\n%\xe2\xe3\xcf\xd3\nreal s3 round trip\n", $store->read($row));

        $store->delete($row); // trash keeps the binary
        $this->assertTrue($store->filesystem()->has((string)$row->get('path')));

        /** @var \TheMusicDev\Files\Model\Entity\StoredFile $trashed */
        $trashed = $store->storedFiles()->find('trashed')->where(['id' => $row->get('id')])->firstOrFail();
        $store->purge($trashed);
        $this->assertFalse($store->filesystem()->has((string)$row->get('path')));
        $this->assertNull($store->storedFiles()->find()->where(['id' => $row->get('id')])->first());
    }

    public function testTheConfiguredS3BackendIsReallyUsed(): void
    {
        $filesystem = (new FileStore())->filesystem();

        $this->assertInstanceOf(
            AwsS3V3Adapter::class,
            (new ReflectionClass($filesystem))->getProperty('adapter')->getValue($filesystem),
        );
    }

    /** The same client FileStore builds from config, for test setup only. */
    private function client(): S3Client
    {
        $definition = (array)Configure::read('Files.backends.s3');

        return new S3Client([
            'region' => $definition['region'],
            'credentials' => ['key' => $definition['key'], 'secret' => $definition['secret']],
            'endpoint' => $definition['endpoint'],
            'use_path_style_endpoint' => true,
        ]);
    }
}
