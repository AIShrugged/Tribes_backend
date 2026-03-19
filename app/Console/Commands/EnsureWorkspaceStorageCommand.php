<?php

namespace App\Console\Commands;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Illuminate\Console\Command;

class EnsureWorkspaceStorageCommand extends Command
{
    protected $signature = 'workspaces:ensure-storage {--retries=20} {--sleep=2}';

    protected $description = 'Ensure the configured workspace object-storage bucket exists.';

    public function handle(): int
    {
        if ((string) config('workspaces.disk', 's3') !== 's3') {
            $this->info('Workspace storage uses a non-S3 disk; skipping bucket bootstrap.');

            return self::SUCCESS;
        }

        $bucket = (string) config('filesystems.disks.s3.bucket');
        if ($bucket === '') {
            $this->warn('AWS_BUCKET is not configured; skipping workspace storage bootstrap.');

            return self::SUCCESS;
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => (string) config('filesystems.disks.s3.region', 'us-east-1'),
            'endpoint' => config('filesystems.disks.s3.endpoint'),
            'use_path_style_endpoint' => (bool) config('filesystems.disks.s3.use_path_style_endpoint', false),
            'credentials' => [
                'key' => (string) config('filesystems.disks.s3.key'),
                'secret' => (string) config('filesystems.disks.s3.secret'),
            ],
        ]);

        $retries = max(1, (int) $this->option('retries'));
        $sleepSeconds = max(1, (int) $this->option('sleep'));

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            try {
                $client->headBucket(['Bucket' => $bucket]);
                $this->info("Workspace bucket [{$bucket}] already exists.");

                return self::SUCCESS;
            } catch (AwsException $exception) {
                if ($this->isBucketMissing($exception)) {
                    $client->createBucket(['Bucket' => $bucket]);
                    $this->info("Workspace bucket [{$bucket}] created.");

                    return self::SUCCESS;
                }

                if ($attempt === $retries) {
                    $this->error("Unable to ensure workspace bucket [{$bucket}]: ".$exception->getAwsErrorMessage());

                    return self::FAILURE;
                }

                sleep($sleepSeconds);
            }
        }

        return self::FAILURE;
    }

    private function isBucketMissing(AwsException $exception): bool
    {
        $statusCode = (int) $exception->getStatusCode();
        $errorCode = (string) $exception->getAwsErrorCode();

        return in_array($statusCode, [404, 400], true)
            || in_array($errorCode, ['NotFound', 'NoSuchBucket'], true);
    }
}
