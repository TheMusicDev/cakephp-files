<?php
declare(strict_types=1);

namespace TheMusicDev\Files\Test\Stub;

use ArrayIterator;
use Aws\Command;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use GuzzleHttp\Psr7\Utils;

/**
 * In-memory S3ClientInterface fake for backend tests — only what
 * AwsS3V3Adapter actually calls is real; everything else is a no-op.
 * Not a general-purpose S3 fake: grow it only when the adapter seam grows.
 */
class FakeS3Client implements S3ClientInterface
{
    /**
     * @var array<string, string> bucket + key => body
     */
    public array $objects = [];

    /**
     * @return list<array{op: string, args: array<string, mixed>}>
     */
    public array $commands = [];

    public function __call($name, $args)
    {
        throw new AwsException('FakeS3Client: ' . $name . ' is not implemented.', new Command($name, []));
    }

    public function getCommand($name, array $args = []): CommandInterface
    {
        return new Command($name, $args);
    }

    public function execute(CommandInterface $command): Result
    {
        $name = $command->getName();
        $this->commands[] = ['op' => $name, 'args' => $command->toArray()];
        $key = ($command['Bucket'] ?? '') . '/' . ($command['Key'] ?? '');

        if ($name === 'PutObject') {
            $this->objects[$key] = (string)$command['Body'];

            return new Result();
        }
        if ($name === 'DeleteObject') {
            unset($this->objects[$key]);

            return new Result();
        }
        if ($name === 'GetObject') {
            if (!isset($this->objects[$key])) {
                throw new AwsException('FakeS3Client: object not found.', $command);
            }

            return new Result(['Body' => Utils::streamFor($this->objects[$key])]);
        }

        throw new AwsException("FakeS3Client: cannot execute {$name}.", $command);
    }

    public function doesObjectExistV2($bucket, $key, $includeDeleteMarkers = false, array $options = []): bool
    {
        return isset($this->objects[$bucket . '/' . $key]);
    }

    public function upload($bucket, $key, $body, $acl = 'private', array $options = []): Result
    {
        return $this->execute($this->getCommand('PutObject', ['Bucket' => $bucket, 'Key' => $key, 'Body' => $body]));
    }

    public function doesObjectExist($bucket, $key, array $options = []): bool
    {
        return $this->doesObjectExistV2($bucket, $key, $options);
    }

    public function deleteMatchingObjects($bucket, $prefix = null, $regex = null, array $options = [])
    {
        foreach (array_keys($this->objects) as $k) {
            if (str_starts_with($k, $bucket . '/' . $prefix)) {
                unset($this->objects[$k]);
            }
        }
    }

    public function deleteMatchingObjectsAsync($bucket, $prefix = null, $regex = null, array $options = [])
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function getObjectUrl($bucket, $key): string
    {
        return 's3://' . $bucket . '/' . $key;
    }

    public function getRegion(): string
    {
        return 'us-east-1';
    }

    public function getEndpoint()
    {
        return $this;
    }

    public function getConfig($option = null)
    {
        return null;
    }

    public function getCredentials()
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function getApi()
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function getHandlerList()
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function getIterator($name, array $args = [])
    {
        return new ArrayIterator();
    }

    public function getPaginator($name, array $args = [])
    {
        return iterator_to_array(new ArrayIterator());
    }

    public function getCommandAsync($name, array $args = [])
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function executeAsync(CommandInterface $command)
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function waitUntil($name, array $args = [])
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function getWaiter($name, array $args = [])
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function createPresignedRequest(CommandInterface $command, $expires, array $presignedRequestOptions = [])
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function copy($fromBucket, $fromKey, $destBucket, $destKey, $acl = 'private', array $options = [])
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function copyAsync($fromBucket, $fromKey, $destBucket, $destKey, $acl = 'private', array $options = [])
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function uploadAsync($bucket, $key, $body, $acl = 'private', array $options = [])
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function downloadBucket($directory, $bucket, $keyPrefix = '', array $options = [])
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function downloadBucketAsync($directory, $bucket, $keyPrefix = '', array $options = [])
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function uploadDirectory($directory, $bucket, $keyPrefix = '', array $options = [])
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function uploadDirectoryAsync($directory, $bucket, $keyPrefix = '', array $options = [])
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function registerStreamWrapper()
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function registerStreamWrapperV2()
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function determineBucketRegion($bucket)
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function determineBucketRegionAsync($bucket)
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function doesBucketExist($bucket)
    {
        throw new AwsException('not implemented', new Command('none', []));
    }

    public function doesBucketExistV2($bucket, $accept403 = false): bool
    {
        throw new AwsException('not implemented', new Command('none', []));
    }
}
