<?php

namespace App\DiscordBot\Media;

use Symfony\Component\Process\Process;
use YoutubeDl\Process\DefaultProcessBuilder;
use YoutubeDl\Process\ProcessBuilderInterface;

class YtDlpProcessBuilder implements ProcessBuilderInterface
{
    private DefaultProcessBuilder $inner;

    public function __construct()
    {
        $this->inner = new DefaultProcessBuilder();
    }

    public function build(?string $binPath, ?string $pythonPath, array $arguments = []): Process
    {
        array_unshift($arguments, '--js-runtimes=nodejs');

        return $this->inner->build($binPath, $pythonPath, $arguments);
    }
}
