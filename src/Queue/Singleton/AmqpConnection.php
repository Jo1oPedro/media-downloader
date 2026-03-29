<?php

namespace App\DiscordBot\Queue\Singleton;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;

class AmqpConnection
{
    private static ?AbstractConnection $instance = null;
    private function __construct() {}

    public static function getInstance(): AbstractConnection
    {
        if (self::$instance === null) {
            $amqpConfig = new AMQPConnectionConfig();
            $amqpConfig->setHost($_ENV['QUEUE_HOST']);
            $amqpConfig->setPort($_ENV['QUEUE_PORT']);
            $amqpConfig->setUser($_ENV['QUEUE_USER']);
            $amqpConfig->setPassword($_ENV['QUEUE_PASSWORD']);

            self::$instance = AMQPConnectionFactory::create($amqpConfig);
        }

        return self::$instance;
    }
}