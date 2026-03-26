<?php

return [
    "aws" => [
        'version' => 'latest',
        'region' => $_ENV['AWS_REGION'],
        'credentials' => [
            'key' => $_ENV['AWS_KEY'],
            'secret' => $_ENV['AWS_SECRET'],
        ]
    ],
    "queue" => [
        [
            "discord_bot" => [
                "host" => $_ENV['QUEUE_HOST'],
                "port" => $_ENV['QUEUE_PORT'],
                "user" => $_ENV['QUEUE_USER'],
                "password" => $_ENV['QUEUE_PASSWORD']
            ]
        ]
    ]
];