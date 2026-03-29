<?php

namespace App\DiscordBot\Media;

use App\DiscordBot\Commands\DownloadCommand;
use YoutubeDl\Options;
use YoutubeDl\YoutubeDl;

class Download
{
    private static Download|null $instance = null;
    private YoutubeDl $youtubeDl;
    private Options $options;

    public function __construct(
        string $binPath = ""
    ) {
        $this->youtubeDl = new YoutubeDl();
        //$this->youtubeDl->setBinPath($binPath);
        $this->youtubeDl->debug(function (string $type, string $buffer): void {
            // $type will be either Process::OUT or Process::ERR
            echo strtoupper($type) . ': ' . $buffer;
        });
        $this->options = Options::create()
            ->cookies(__DIR__ . "/../../cookies.txt")
            ->downloadPath(__DIR__ . "/../../medias");
    }

    public function download(DownloadCommand $downloadCommand) {
        $this->youtubeDl->onProgress($downloadCommand->getOnProgress());

        $title = md5(uniqid());

        $options = $this->options;

        if($downloadCommand->getFormat() === "mp4") {
            $options = $options
                ->output("$title.%(ext)s")
                ->url($downloadCommand->getUrlToDownload())
                ->playlistEnd(1);
        }

        if($downloadCommand->getFormat() === "mp3") {
            $options = $options
                ->extractAudio(true)
                ->audioFormat('mp3')
                ->audioQuality('0')
                ->output("$title.%(ext)s")
                ->url($downloadCommand->getUrlToDownload())
                ->playlistEnd(1);
        }

        $collection = $this->youtubeDl->download($options);

        $firstVideo = $collection->getVideos()[0];

        $sizeBytes = filesize($firstVideo->getFileName());

        return [
            "path" => $firstVideo->getFileName(),
            "size" => $sizeBytes,
            "name" => $firstVideo->getFilename()
        ];
    }
}