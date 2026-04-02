<?php

use App\DiscordBot\Commands\DownloadCommand;
use App\DiscordBot\Http\ExternalApiClient;
use App\DiscordBot\Media\Download;
use App\DiscordBot\Queue\Singleton\AmqpConnection;
use App\DiscordBot\Storage\S3Uploader;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

require_once __DIR__ . "/../vendor/autoload.php";

$dotEnv = Dotenv\Dotenv::createImmutable(__DIR__ . "/..");
$dotEnv->load();

$config = require_once __DIR__ . "/../config.php";

$youtubeDl = new Download();
$s3Uploader = new S3Uploader(new S3Client($config["aws"]), $_ENV['AWS_BUCKET']);
$apiClient = new ExternalApiClient($_ENV['EXTERNAL_API_DOMAIN'], $_ENV['EXTERNAL_API_TOKEN']);

$ampqConnection = AmqpConnection::getInstance();

$channel = $ampqConnection->channel();

$channel->queue_declare(
    "media_upload",
    false,
    true,
    false,
    false
);

$callback = function (AMQPMessage $message) use ($youtubeDl, $s3Uploader, $apiClient) {
    $data = json_decode($message->getBody(),true);

    if(!$data) {
        $message->ack();
        return;
    }

    $downloadCommand = new DownloadCommand(
        mediaId: $data['media_id'],
        urlToDownload: $data['download_url'],
        format: $data["format"]
    );

    try {
        $file = $youtubeDl->download($downloadCommand);
    } catch (\RuntimeException $e) {
        echo "Download failed: " . $e->getMessage() . "\n";

        $apiClient->patch("/api/media/{$data['media_id']}", [
            "media_id" => $data["media_id"],
            "url" => null,
            "status" => "failed"
        ]);

        $message->ack();
        return;
    }

    $name = md5(uniqid());
    $key = "uploads/{$data['format']}/{$name}.{$data['format']}";

    echo "Download completed. Uploading to S3: {$key}\n";

    try {
        $s3Uploader->upload($key, $file["path"]);
    } catch (\Throwable|S3Exception $exception) {
        echo "S3 upload failed: " . $exception->getMessage() . "\n";
        $message->ack();
        unlink($file["path"]);
        return;
    }

    $url = $s3Uploader->getPresignedUrl($key, '+30 minutes', [
        "ResponseContentDisposition" => 'attachment; filename="' . $data['format'] . '-' . $name .  '.' . $data['format'] .'"'
    ]);

    $message->ack();
    unlink($file["path"]);

    echo "Uploading to S3 completed. Sending PATCH to API\n";

    $apiClient->patch("/api/media/{$data['media_id']}", [
        "media_id" => $data["media_id"],
        "url" => $url,
        "status" => "success"
    ]);
};

$channel->basic_consume(
    "media_upload",
    "",
    false,
    false,
    false,
    false,
    $callback,
);

while($channel->is_consuming()) {
    try {
        $channel->wait(null, true);
    } catch (AMQPTimeoutException $exception) {
        continue;
    }
}