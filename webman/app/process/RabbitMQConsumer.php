<?php

namespace app\process;

use Workerman\Timer;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use app\services\SwordService;
use app\services\GeminiService;
use app\services\ProcesadorService;
use app\utils\AudioUtil;
use Throwable;

/**
 * Proceso consumidor de RabbitMQ para procesar samples de forma asíncrona.
 */
class RabbitMQConsumer
{
    private $connection;
    private $channel;
    private ?ProcesadorService $procesadorService = null;
    private ?SwordService $swordService = null;

    public function onWorkerStart()
    {
        // Esperar un momento para que otros servicios (como la red) estén listos
        Timer::add(1, function () {
            casielLog('Iniciando consumidor de RabbitMQ...');
            try {
                $config = config('casiel.rabbitmq');
                $this->connection = new AMQPStreamConnection($config['host'], $config['port'], $config['user'], $config['password'], $config['vhost']);
                $this->channel = $this->connection->channel();

                $this->channel->queue_declare($config['queue'], false, true, false, false);
                $this->channel->basic_qos(null, 1, null); // Procesar un mensaje a la vez

                // Inicializar servicios necesarios para el procesamiento
                $this->swordService = new SwordService(config('api.sword.api_url'), config('api.sword.api_key'));
                $geminiService = new GeminiService(config('api.gemini.api_key'), config('api.gemini.model_id'));
                $audioUtil = new AudioUtil();
                $swordBaseUrl = config('api.sword.base_url');
                $this->procesadorService = new ProcesadorService($this->swordService, $geminiService, $audioUtil, $swordBaseUrl);

                $this->channel->basic_consume($config['queue'], '', false, false, false, false, [$this, 'procesarMensaje']);

                // Mantener la conexión viva y procesar callbacks
                // aquii dice ndefined method 'is_consuming'.intelephense(P1013)
                while ($this->channel->is_consuming()) {
                    $this->channel->wait();
                }
            } catch (Throwable $e) {
                casielLog("Error fatal en RabbitMQConsumer: " . $e->getMessage(), [], 'error');
                // Podríamos añadir un timer para reintentar la conexión
            }
        }, [], false);
    }

    public function procesarMensaje(AMQPMessage $msg)
    {
        casielLog('Mensaje recibido de RabbitMQ.', ['body' => $msg->body]);
        $payload = json_decode($msg->body, true);
        $idSample = $payload['id_sample'] ?? null;

        if (!$idSample) {
            casielLog('Mensaje inválido, no contiene id_sample. Descartando.', [], 'warning');
            //aqui dice Undefined method 'ack'.intelephense(P1013)
            $msg->ack(); // Acknowledge para removerlo de la cola
            return;
        }

        try {
            $sample = $this->swordService->obtenerSamplePorId($idSample); // Necesitarás crear esta función en SwordService
            if (!$sample) {
                throw new \Exception("No se encontró el sample con ID: $idSample recibido de RabbitMQ.");
            }

            $this->procesadorService->procesarSample($sample);

            casielLog("Sample ID: $idSample procesado con éxito via RabbitMQ.");
            //Undefined method 'ack'.intelephense(P1013)
            $msg->ack(); // Mensaje procesado correctamente

        } catch (Throwable $e) {
            casielLog("Fallo al procesar sample ID: $idSample via RabbitMQ: " . $e->getMessage(), [], 'error');
            // No hacemos ack() para que el mensaje pueda ser reintentado o movido a una dead-letter-queue.
        }
    }
}
