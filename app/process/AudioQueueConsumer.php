<?php

namespace app\process;

use Workerman\Worker;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Class AudioQueueConsumer
 * Consumes audio processing jobs from a RabbitMQ queue.
 */
class AudioQueueConsumer
{
    /**
     * This method is called when the process starts.
     * @param Worker $worker
     */
    public function onWorkerStart(Worker $worker)
    {
        casiel_log('audio_processor', 'Iniciando consumidor de cola de audio...');

        try {
            $connection = new AMQPStreamConnection(
                getenv('RABBITMQ_HOST'),
                getenv('RABBITMQ_PORT'),
                getenv('RABBITMQ_USER'),
                getenv('RABBITMQ_PASS'),
                getenv('RABBITMQ_VHOST')
            );

            $channel = $connection->channel();
            $queueName = getenv('RABBITMQ_QUEUE_CASIEL');

            $channel->queue_declare($queueName, false, true, false, false);
            casiel_log('audio_processor', "Escuchando en la cola: '{$queueName}'");

            $callback = function (AMQPMessage $msg) {
                casiel_log('audio_processor', "Mensaje recibido de la cola.", ['body' => $msg->body]);

                // TODO: Implement the full audio processing logic here.
                // 1. Decode message ($data = json_decode($msg->body, true);)
                // 2. Call SwordApiService to get audio URL
                // 3. Download audio file
                // 4. Call AudioAnalysisService (ffmpeg, audio.py)
                // 5. Call GeminiService
                // 6. Upload light version via SwordApiService
                // 7. Update content metadata via SwordApiService
                // 8. Acknowledge the message

                // For now, we just acknowledge the message
                $msg->ack();
            };

            $channel->basic_consume($queueName, '', false, false, false, false, $callback);

            // Loop to keep the connection alive
            while ($channel->is_consuming()) {
                $channel->wait();
            }

            $channel->close();
            $connection->close();
        } catch (Throwable $e) {
            casiel_log('audio_processor', 'No se pudo conectar o consumir de RabbitMQ.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error');
            // Wait 10 seconds before trying to reconnect
            sleep(10);
            // This will cause the process to restart by the main worker manager, attempting reconnection.
            // Be careful in production, might need a more robust reconnection strategy.
        }
    }
}
