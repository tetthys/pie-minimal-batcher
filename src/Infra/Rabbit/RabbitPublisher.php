<?php

declare(strict_types=1);

namespace Tetthys\Pie\Infra\Rabbit;

use Tetthys\Pie\Contracts\QueuePublisherInterface;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publisher with Publisher Confirms.
 * - Declares a durable direct exchange.
 * - Publishes persistent JSON messages.
 * Docs: direct exchange routing (exact match), confirms. 
 * https://www.rabbitmq.com/docs/exchanges
 * https://www.rabbitmq.com/docs/confirms
 */
final class RabbitPublisher implements QueuePublisherInterface
{
    private \PhpAmqpLib\Connection\AMQPStreamConnection $conn;
    private \PhpAmqpLib\Channel\AMQPChannel $ch;
    private string $exchange;

    public function __construct(RabbitConnection $cfg, string $exchange = 'payout.direct')
    {
        $this->exchange = $exchange;
        $this->conn = $cfg->connect();
        $this->ch = $this->conn->channel();
        $this->ch->exchange_declare($this->exchange, 'direct', false, true, false);
        $this->ch->confirm_select(); // publisher confirms
    }

    public function publish(array $msg, string $routingKey): void
    {
        // Basic input hardening: size guard + safe JSON encode.
        $body = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($body) > 64 * 1024) throw new \RuntimeException('message too large');
        $am = new AMQPMessage($body, ['content_type' => 'application/json', 'delivery_mode' => 2]);
        $this->ch->basic_publish($am, $this->exchange, $routingKey);
        $this->ch->wait_for_pending_acks_returns(); // sync confirms
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
