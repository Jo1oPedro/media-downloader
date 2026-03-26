<?php

use App\DiscordBot\Commands\DownloadCommand;
use App\DiscordBot\Media\Download;
use App\DiscordBot\Queue\Singleton\AmqpConnection;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\User\User;
use Discord\WebSockets\Intents;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

require_once __DIR__ . "/../vendor/autoload.php";

$dotEnv = Dotenv\Dotenv::createImmutable(__DIR__ . "/..");
$dotEnv->load();

$config = require_once __DIR__ . "/../config.php";

$youtubeDl = new Download();
$s3 = new S3Client($config["aws"]);

$ampqConnection = AmqpConnection::getInstance();

$channel = $ampqConnection->channel();

$channel->queue_declare(
    "media_upload",
    false,
    true,
    false,
    false
);

$callback = function (AMQPMessage $message) use ($youtubeDl, $s3) {
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

    $file = $youtubeDl->download($downloadCommand);
    $name = md5(uniqid());
    $key = "uploads/{$data['format']}/{$name}";

    try {
        $s3->putObject([
            "Bucket" => $_ENV['AWS_BUCKET'],
            "Key" => $key,
            "Body" => fopen($file["path"], "rb"),
            "ACL" => "public-read",
        ]);
    } catch (S3Exception $exception) {
        $message->ack();
        unlink($file["path"]);
        return;
    }

    $cmd = $s3->getCommand('GetObject', [
        "Bucket" => $_ENV['AWS_BUCKET'],
        "Key" => $key,
        "ResponseContentDisposition" => 'attachment; filename="' . $data['format'] . '-' . $name . '"'
    ]);

    $request = $s3->createPresignedRequest($cmd, '+30 minutes');

    $url = (string) $request->getUri();

    $endpoint = $_ENV["EXTERNAL_API_DOMAIN"] . "/api/media/{$data['media_id']}/status";

    $payload = json_encode([
        "media_id" => $data["media_id"],
        "url" => $url,
        "status" => "success"
    ]);

    $message->ack();
    unlink($file["path"]);

    $ch = curl_init($endpoint);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-type: application/json",
        "Content-Length: " . strlen($payload),
        "Authorization: Bearer " . $_ENV['EXTERNAL_API_TOKEN']
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new \Exception("Erro ao enviar PATCH: " . $error);
    }

    curl_close($ch);
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