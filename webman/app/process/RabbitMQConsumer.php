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
    private ?AMQPStreamConnection $connection = null;
    private ?\PhpAmqpLib\Channel\AMQPChannel $channel = null;
    private ?ProcesadorService $procesadorService = null;
    private ?SwordService $swordService = null;
    private ?array $config = null;
    /**
     * @var int|null El ID del temporizador de reconexión.
     */
    private ?int $reconnectTimer = null;

    public function onWorkerStart()
    {
        $this->config = config('casiel.rabbitmq');
        $this->conectar();
    }

    public function conectar()
    {
        casielLog('Intentando conectar a RabbitMQ...');
        try {
            $this->connection = new AMQPStreamConnection(
                $this->config['host'],
                $this->config['port'],
                $this->config['user'],
                $this->config['password'],
                $this->config['vhost']
            );
            $this->channel = $this->connection->channel();
            casielLog('Conexión con RabbitMQ establecida con éxito.');

            if ($this->reconnectTimer) {
                Timer::del($this->reconnectTimer);
                $this->reconnectTimer = null;
            }

            $this->configurarConsumidor();
        } catch (Throwable $e) {
            casielLog("No se pudo conectar a RabbitMQ: " . $e->getMessage(), [], 'error');
            $this->intentarReconexion();
        }
    }

    private function intentarReconexion()
    {
        if ($this->reconnectTimer) {
            return;
        }
        casielLog('Se programará un reintento de conexión en 30 segundos.');
        $this->reconnectTimer = Timer::add(30, function () {
            $this->conectar();
        }, [], false);
    }

    private function configurarConsumidor()
    {
        try {
            $dlxExchange = $this->config['dlx_exchange'];
            $dlqQueue = $this->config['dlq_queue'];
            $mainQueue = $this->config['queue'];

            $this->channel->exchange_declare($dlxExchange, 'direct', false, true, false);
            $this->channel->queue_declare($dlqQueue, false, true, false, false);
            $this->channel->queue_bind($dlqQueue, $dlxExchange, $mainQueue);

            $queue_args = new \PhpAmqpLib\Wire\AMQPTable([
                'x-dead-letter-exchange' => $dlxExchange,
                'x-dead-letter-routing-key' => $mainQueue
            ]);
            $this->channel->queue_declare($mainQueue, false, true, false, false, false, $queue_args);

            $this->channel->basic_qos(null, 1, null);

            $this->swordService = new SwordService(config('api.sword.api_url'), config('api.sword.api_key'));
            $geminiService = new GeminiService(config('api.gemini.api_key'), config('api.gemini.model_id'));
            $audioUtil = new AudioUtil();
            $swordBaseUrl = config('api.sword.base_url');
            $this->procesadorService = new ProcesadorService($this->swordService, $geminiService, $audioUtil, $swordBaseUrl);

            $this->channel->basic_consume($mainQueue, '', false, false, false, false, [$this, 'procesarMensaje']);

            Timer::add(1, function () {
                if ($this->connection && $this->connection->isConnected()) {
                    try {
                        $this->channel->wait(null, true, 0);
                    } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                        // Timeout esperado en llamada no bloqueante.
                    } catch (\PhpAmqpLib\Exception\AMQPConnectionClosedException | \PhpAmqpLib\Exception\AMQPChannelClosedException $e) {
                        casielLog("La conexión o el canal de RabbitMQ se cerraron. Intentando reconectar...", [], 'warning');
                        $this->cerrarConexion();
                        $this->intentarReconexion();
                    } catch (Throwable $e) {
                        casielLog("Error inesperado en el canal de RabbitMQ: " . $e->getMessage(), [], 'error');
                        $this->cerrarConexion();
                        $this->intentarReconexion();
                    }
                }
            });
        } catch (Throwable $e) {
            casielLog("Error al configurar el consumidor de RabbitMQ: " . $e->getMessage(), [], 'error');
            $this->intentarReconexion();
        }
    }

    public function procesarMensaje(AMQPMessage $msg)
    {
        casielLog('Mensaje recibido de RabbitMQ.', ['body' => $msg->body]);
        $payload = json_decode($msg->body, true);
        $idSample = $payload['id_sample'] ?? null;
        $deliveryTag = $msg->delivery_info['delivery_tag'];

        if (!$idSample) {
            casielLog('Mensaje inválido, no contiene id_sample. Descartando.', [], 'warning');
            $this->channel->basic_ack($deliveryTag);
            return;
        }

        try {
            $sample = $this->swordService->obtenerSamplePorId($idSample);
            if (!$sample) {
                throw new \Exception("No se encontró el sample con ID: $idSample. El mensaje será descartado.");
            }

            $this->procesadorService->procesarSample($sample);

            casielLog("Sample ID: $idSample procesado con éxito via RabbitMQ.");
            $this->channel->basic_ack($deliveryTag);
        } catch (Throwable $e) {
            casielLog("Fallo al procesar sample ID: $idSample via RabbitMQ: " . $e->getMessage(), [], 'error');
            $this->channel->basic_reject($deliveryTag, false);
        }
    }

    private function cerrarConexion()
    {
        try {
            // --- INICIO DE LA CORRECCIÓN ---
            // Se elimina la comprobación 'is_closed' porque no existe.
            // La propia función ->close() de la librería ya es segura.
            // Se envuelve en un 'if' para asegurar que la variable no sea null.
            if ($this->channel) {
                $this->channel->close();
            }
            if ($this->connection && $this->connection->isConnected()) {
                $this->connection->close();
            }
            // --- FIN DE LA CORRECCIÓN ---
        } catch (Throwable $e) {
            casielLog("Error al cerrar la conexión de RabbitMQ (puede ignorarse si ya estaba cerrada): " . get_class($e) . " - " . $e->getMessage(), [], 'info');
        } finally {
            $this->channel = null;
            $this->connection = null;
        }
    }

    public function onWorkerStop()
    {
        casielLog("Deteniendo consumidor de RabbitMQ.");
        $this->cerrarConexion();
    }
}
