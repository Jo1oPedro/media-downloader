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

require_once "../vendor/autoload.php";

$dotEnv = Dotenv\Dotenv::createImmutable(__DIR__ . "/..");
$dotEnv->load();

$config = require_once __DIR__ . "/../config.php";

$discord = new Discord([
    'token'   => $_ENV['DISCORD_TOKEN'],
    'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS
]);

$youtubeDl = new Download();
$s3 = new S3Client($config["aws"]);

$discord->on('ready', function(Discord $discord) use($youtubeDl, $s3) {
    $ampqConnection = AmqpConnection::getInstance();

    $channel = $ampqConnection->channel();

    $channel->queue_declare(
        "discord_bot_medias",
        false,
        true,
        false,
        false
    );

    $callback = function (AMQPMessage $message) use ($discord, $youtubeDl, $s3) {
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
            $s3->putObject([
                "Bucket" => $_ENV['AWS_BUCKET'],
                "Key" => $key,
                "Body" => fopen($file["path"], "rb"),
                "ACL" => "public-read",
            ]);
        } catch (S3Exception $exception) {
            file_put_contents("dale.txt", print_r($exception->getMessage(), true));
            $discordChannel->sendMessage("❌ Ocorreu um erro ao realizar o seu download: <@{$data['author_id']}>")
                ->then(function() use ($message, $file) {
                    $message->ack();
                    unlink($file["path"]);
                });
            return;
        }

        if($file["size"] / 1000 > 8000) {
            $cmd = $s3->getCommand('GetObject', [
                "Bucket" => $_ENV['AWS_BUCKET'],
                "Key" => $key,
            ]);

            $request = $s3->createPresignedRequest($cmd, '+30 minutes');

            $url = (string) $request->getUri();

            $discordChannel->sendMessage("✅ Download concluído: <@{$data['author_id']}>! Aqui está seu link (válido por 30 min):\n{$url}")
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
        )->then(function() use ($file, $message) {
            $message->ack();
            unlink($file["path"]);
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