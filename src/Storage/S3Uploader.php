<?php

namespace App\DiscordBot\Storage;

use Aws\S3\S3Client;

class S3Uploader
{
    public function __construct(
        private S3Client $s3,
        private string $bucket,
    ) {}

    public function upload(string $key, string $filePath): void
    {
        $this->s3->putObject([
            "Bucket" => $this->bucket,
            "Key" => $key,
            "Body" => fopen($filePath, "rb"),
            "ACL" => "public-read",
        ]);
    }

    public function getPresignedUrl(string $key, string $expiry = '+30 minutes', array $extraParams = []): string
    {
        $cmd = $this->s3->getCommand('GetObject', array_merge([
            "Bucket" => $this->bucket,
            "Key" => $key,
        ], $extraParams));

        $request = $this->s3->createPresignedRequest($cmd, $expiry);

        return (string) $request->getUri();
    }
}
