<?php

declare(strict_types=1);

namespace Tetthys\Pie\Infra\Rabbit;

use Tetthys\Pie\Contracts\QueueConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Minimal pull-style consumer (classic queue by default).
 * - Durable direct exchange & queue.
 * - Binding key == routing key (exact match). 
 *   https://www.rabbitmq.com/tutorials/tutorial-four-python
 */
final class RabbitConsumer implements QueueConsumerInterface
{
    private \PhpAmqpLib\Connection\AMQPStreamConnection $conn;
    private \PhpAmqpLib\Channel\AMQPChannel $ch;
    private string $queue;

    public function __construct(
        RabbitConnection $cfg,
        string $exchange,
        string $queue,
        string $routingKey,
        int $prefetch = 100,
        bool $quorum = false
    ) {
        $this->conn = $cfg->connect();
        $this->ch   = $cfg->channelWithPrefetch($this->conn, $prefetch);

        $this->ch->exchange_declare($exchange, 'direct', false, true, false);
        $args = $quorum ? ['x-queue-type' => ['S', 'quorum']] : [];
        $this->queue = $queue;
        $this->ch->queue_declare($queue, false, true, false, false, false, $args);
        $this->ch->queue_bind($queue, $exchange, $routingKey);
    }

    public function getOne(): ?array
    {
        /** @var AMQPMessage|null $m */
        $m = $this->ch->basic_get($this->queue, false);
        if (!$m) return null;

        $body = $m->getBody();
        $this->ch->basic_ack($m->getDeliveryTag());
        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['identity'], $data['uuid'])) return null;
        return $data;
    }

    public function __destruct()
    {
        try {
            $this->ch->close();
            $this->conn->close();
        } catch (\Throwable) {
        }
    }
}
