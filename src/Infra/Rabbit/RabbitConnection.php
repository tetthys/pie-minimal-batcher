<?php

declare(strict_types=1);

namespace Tetthys\Pie\Infra\Rabbit;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;

/**
 * Creates AMQP 0-9-1 connections/channels (RabbitMQ 4.1 compatible).
 * - Supports prefetch via basic.qos (consumer prefetch). See docs. 
 *   https://www.rabbitmq.com/docs/consumer-prefetch
 */
final class RabbitConnection
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $pass,
        private readonly string $vhost = '/pie'
    ) {}
    public function connect(): AMQPStreamConnection
    {
        return new AMQPStreamConnection($this->host, $this->port, $this->user, $this->pass, $this->vhost);
    }

    public function channelWithPrefetch(AMQPStreamConnection $c, int $prefetch): AMQPChannel
    {
        $ch = $c->channel();
        $ch->basic_qos(null, $prefetch, null);
        return $ch;
    }
}
