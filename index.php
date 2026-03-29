<?php

use App\DiscordBot\Commands\DownloadCommand;
use App\DiscordBot\Media\Download;
use App\DiscordBot\Queue\Singleton\AmqpConnection;
use Aws\S3\S3Client;
use Discord\Builders\Components\Option;
use Discord\Builders\Components\StringSelect;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;
use PhpAmqpLib\Message\AMQPMessage;

require_once "vendor/autoload.php";

$dotEnv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotEnv->load();

$config = require_once __DIR__ . "/config.php";

$ampqConnection = AmqpConnection::getInstance();

$channel = $ampqConnection->channel();
$channel->queue_declare(
    "discord_bot_medias",
    false,
    true,
    false,
    false
);

$s3 = new S3Client($config["aws"]);

$discord = new Discord([
    'token' => $_ENV['DISCORD_TOKEN'],
    'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT
]);

$youtubeDl = new Download(/*"C:\\yt-dlp\\yt-dlp.exe"*/);

$pendingDownloads = [];

$discord->on('ready', function (Discord $discord) use ($youtubeDl, &$pendingDownloads) {
    $discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) use ($youtubeDl, &$pendingDownloads) {
        if(str_contains($message->content, "!download")) {
            $url = trim(str_replace("!download", "", $message->content));

            $downloadCommand = new DownloadCommand($url, $message->author);

            $origMsgId = $message->id;
            $pendingDownloads[$origMsgId] = $downloadCommand;

            $select = StringSelect::new()
                ->setCustomId("download_format:{$origMsgId}")
                ->setPlaceholder('Escolha o formato…')
                ->addOption(Option::new('MP3', 'mp3')
                ->setDescription('Só o áudio'))
                ->addOption(Option::new('MP4', 'mp4')
                    ->setDescription('Áudio + vídeo'));

            $messageBuilder = MessageBuilder::new();
            $messageBuilder
                ->setContent("Você pediu para baixar:\n{$url}")
                ->addComponent($select);

            $message->channel->sendMessage($messageBuilder);
        }
    });
});

$discord->on(Event::INTERACTION_CREATE, function (Interaction $interaction) use ($discord, $youtubeDl, &$pendingDownloads, $s3, $channel) {
    $cid = $interaction->data->custom_id;

    if(!str_starts_with($cid, "download_format:")) {
        return;
    }

    [, $origMsgId] = explode(":", $cid, 2);

    /** @var DownloadCommand $downloadCommand */
    $downloadCommand = $pendingDownloads[$origMsgId] ?? null;

    if(!$downloadCommand) {
        return $interaction->respondWithMessage("❌ Não encontrei a URL (talvez tenha expirado?).");
    }

    $downloadCommand->setFormat($interaction->data->values[0]);

    $interaction
        ->acknowledge()
        ->then(function () use ($discord, $interaction, $youtubeDl, $downloadCommand, $s3, $channel) {
            $payload = [
                "channel_id" => $interaction->channel_id,
                "download_url" => $downloadCommand->getUrlToDownload(),
                "format" => $downloadCommand->getFormat(),
                "author_id" => $downloadCommand->getAuthor()->id
            ];

            $msg = new AMQPMessage(json_encode($payload));
            $channel->basic_publish($msg, "", "discord_bot_medias");

            $interaction->sendFollowUpMessage(
                MessageBuilder::new()
                    ->setContent("✅ Seu download está sendo realizado :)"),
                true
            );
        });

    unset($pendingDownloads[$origMsgId]);
});

$discord->run();