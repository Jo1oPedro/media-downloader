<?php

namespace App\DiscordBot\Commands;

use Discord\Parts\User\User;

class DownloadCommand implements Command
{
    public function __construct(
        private string $urlToDownload,
        private ?User $author = null,
        private ?string $mediaId = null,
        private $onProgress = "",
        private string $format = "",
    ) {}
    
    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getMediaId(): ?String
    {
        return $this->mediaId;
    }

    public function setFormat(string $format)
    {
        $this->format = $format;
    }

    public function getFormat(): string
    {
        return $this->format;
    }
    
    public function getUrlToDownload(): string
    {
        return $this->urlToDownload;
    }

    public function getOnProgress(): callable
    {
        if(!is_callable($this->onProgress)) {
            return function () {};
        }
        return $this->onProgress;
    }
}