<?php

use App\DiscordBot\Commands\DownloadCommand;
use App\DiscordBot\Http\ExternalApiClient;
use App\DiscordBot\Media\Download;
use App\DiscordBot\Queue\Singleton\AmqpConnection;
use App\DiscordBot\Storage\S3Uploader;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\User\User;
use Discord\WebSockets\Intents;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

require_once "../vendor/autoload.php";

$dotEnv = Dotenv\Dotenv::createImmutable(__DIR__ . "/..");
$dotEnv->load();

$config = require_once __DIR__ . "/../config.php";

$discord = new Discord([
    'token'   => $_ENV['DISCORD_TOKEN'],
    'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS
]);

$youtubeDl = new Download();
$s3Uploader = new S3Uploader(new S3Client($config["aws"]), $_ENV['AWS_BUCKET']);
$apiClient = new ExternalApiClient($_ENV['EXTERNAL_API_DOMAIN'], $_ENV['EXTERNAL_API_TOKEN']);

$discord->on('ready', function(Discord $discord) use($youtubeDl, $s3Uploader, $apiClient) {
    $ampqConnection = AmqpConnection::getInstance();

    $channel = $ampqConnection->channel();

    $channel->queue_declare(
        "discord_bot_medias",
        false,
        true,
        false,
        false
    );

    $callback = function (AMQPMessage $message) use ($discord, $youtubeDl, $s3Uploader, $apiClient) {
        $data = json_decode($message->getBody(),true);

        if(!$data || ! isset($data['channel_id'], $data['download_url'])) {
            $message->ack();
            return;
        }

        $downloadCommand = new DownloadCommand(
            author: new User($discord, ["id" => $data['author_id']]),
            urlToDownload: $data['download_url'],
            format: $data["format"]
        );

        $file = $youtubeDl->download($downloadCommand);
        $name = md5(uniqid());
        $key = "uploads/{$data['format']}/{$name}";
        $channelId = $data['channel_id'];
        $discordChannel = $discord->getChannel($channelId);

        if(!$discordChannel) {
            $message->ack();
            return;
        }

        try {
            $s3Uploader->upload($key, $file["path"]);
        } catch (S3Exception $exception) {
            $message->ack();
            unlink($file["path"]);
            $discordChannel->sendMessage("❌ Ocorreu um erro ao realizar o seu download: <@{$data['author_id']}>");
            return;
        }

        $url = $s3Uploader->getPresignedUrl($key);

        if($file["size"] / 1000 > 8000) {
            $discordChannel->sendMessage("✅ Download concluído: <@{$data['author_id']}>! Aqui está seu link (válido por 30 min):\n{$url}")
                ->then(function() use ($message, $file) {
                    $message->ack();
                    unlink($file["path"]);
                });

            return;
        }

        $discordChannel->sendMessage(
            MessageBuilder::new()
                ->setContent("Download concluído <@{$data['author_id']}>")
                ->addFile($file["path"], basename($file["path"]))
        )->then(function() use ($file, $message, $url, $data, $apiClient) {
            $message->ack();
            unlink($file["path"]);

            $apiClient->post("/api/discord/media", [
                "user_discord_id" => $data["author_id"],
                "s3Url" => $url,
                "media_format" => $data["format"],
                "original_url" => $data['download_url'],
            ]);
        });
    };

    $channel->basic_consume(
        "discord_bot_medias",
        "",
        false,
        false,
        false,
        false,
        $callback,
    );

    $loop = $discord->getLoop();
    $loop->addPeriodicTimer(0.1, function() use($channel) {
       try {
           $channel->wait(null, true, 0.001);
       } catch (AMQPTimeoutException $e) {

       }
    });
});

$discord->run();