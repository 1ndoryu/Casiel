<?php

namespace app\services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;
use Workerman\Timer;

class RabbitMqService
{
    private ?AMQPStreamConnection $connection = null;
    private ?\PhpAmqpLib\Channel\AMQPChannel $channel = null;
    private ?int $processingTimerId = null;

    /**
     * Starts listening for messages and sets up a health check timer.
     *
     * @param callable $onMessage The callback to execute when a message is received.
     */
    public function startListening(callable $onMessage): void
    {
        $this->connectAndConsume($onMessage);

        // Health check timer to reconnect if the connection ever drops.
        Timer::add(15, function() use ($onMessage) {
            if (!$this->connection || !$this->connection->isConnected()) {
                casiel_log('audio_processor', 'RabbitMQ connection lost, attempting to reconnect...', [], 'warning');
                $this->closeConnection(); // Ensure everything is clean before reconnecting
                $this->connectAndConsume($onMessage);
            }
        });
    }

    private function connectAndConsume(callable $onMessage): void
    {
        try {
            casiel_log('audio_processor', 'Attempting to connect to RabbitMQ...');
            $this->connection = new AMQPStreamConnection(
                getenv('RABBITMQ_HOST'), getenv('RABBITMQ_PORT'), getenv('RABBITMQ_USER'),
                getenv('RABBITMQ_PASS'), getenv('RABBITMQ_VHOST'),
                false, 'AMQPLAIN', [], 'en_US', 3.0, 3.0, null, false, 60 // Increased heartbeat
            );
            $this->channel = $this->connection->channel();
            $this->setupQueues($this->channel);

            $this->channel->basic_consume(
                getenv('RABBITMQ_WORK_QUEUE'), '', false, false, false, false,
                fn(AMQPMessage $msg) => $onMessage($msg, $this->channel)
            );

            // SOLUCIÓN: Usar un temporizador de sondeo en lugar de manipulación directa del socket.
            // Esto es mucho más compatible entre plataformas (Windows/Linux).
            $this->processingTimerId = Timer::add(0.1, function () {
                try {
                    // Espera no bloqueante para procesar mensajes.
                    // El timeout es bajo para no bloquear el loop de Workerman.
                    $this->channel->wait(null, true, 0.1);
                } catch (AMQPTimeoutException $e) {
                    // Esto es normal y esperado, significa que no había mensajes.
                } catch (Throwable $e) {
                    casiel_log('audio_processor', 'Error during RabbitMQ wait loop: ' . $e->getMessage(), [], 'error');
                    $this->closeConnection(); // Cierra la conexión para que el health check la reabra.
                }
            });

            casiel_log('audio_processor', 'Successfully connected. Listening for messages via timer polling.');

        } catch (Throwable $e) {
            casiel_log('audio_processor', 'Failed to connect to RabbitMQ: ' . $e->getMessage(), [], 'error');
            $this->closeConnection();
        }
    }

    private function setupQueues($channel): void
    {
        $channel->exchange_declare('casiel_main_exchange', 'direct', false, true, false);
        $channel->exchange_declare('casiel_dlx', 'direct', false, true, false);
        $channel->queue_declare('casiel_audio_dlq', false, true, false, false);
        $channel->queue_bind('casiel_audio_dlq', 'casiel_dlx', 'casiel.dlq.final');

        $channel->queue_declare('casiel_audio_retry_queue', false, true, false, false, new AMQPTable([
            'x-message-ttl' => 60000, 'x-dead-letter-exchange' => 'casiel_main_exchange',
            'x-dead-letter-routing-key' => 'casiel.process'
        ]));
        $channel->queue_bind('casiel_audio_retry_queue', 'casiel_dlx', 'casiel.dlq.retry');

        $channel->queue_declare(getenv('RABBITMQ_WORK_QUEUE'), false, true, false, false, new AMQPTable([
            'x-dead-letter-exchange' => 'casiel_dlx', 'x-dead-letter-routing-key' => 'casiel.dlq.retry'
        ]));
        $channel->queue_bind(getenv('RABBITMQ_WORK_QUEUE'), 'casiel_main_exchange', 'casiel.process');
    }

    private function closeConnection(): void
    {
        try {
            // SOLUCIÓN: Eliminar el temporizador de sondeo si existe.
            if ($this->processingTimerId) {
                Timer::del($this->processingTimerId);
                $this->processingTimerId = null;
            }
            if ($this->channel && $this->channel->is_open()) $this->channel->close();
            if ($this->connection && $this->connection->isConnected()) $this->connection->close();
        } catch (Throwable $e) {
            casiel_log('audio_processor', 'Error closing RabbitMQ connection: ' . $e->getMessage(), [], 'warning');
        } finally {
            $this->channel = null;
            $this->connection = null;
        }
    }
}