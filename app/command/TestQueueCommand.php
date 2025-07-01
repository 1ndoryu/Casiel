<?php

namespace app\command;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class TestQueueCommand extends Command
{
    protected static $defaultName = 'test:queue';
    protected static $defaultDescription = 'Publishes a test job to the audio processing queue.';

    protected function configure()
    {
        $this->addArgument('content_id', InputArgument::REQUIRED, 'The content ID to process.');
        $this->addArgument('media_id', InputArgument::REQUIRED, 'The media ID of the audio file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $contentId = (int)$input->getArgument('content_id');
        $mediaId = (int)$input->getArgument('media_id');

        $output->writeln("<info>Encolando trabajo de prueba para content_id: {$contentId}, media_id: {$mediaId}</info>");

        $payload = json_encode([
            'data' => [
                'content_id' => $contentId,
                'media_id' => $mediaId
            ]
        ]);

        try {
            $connection = new AMQPStreamConnection(
                getenv('RABBITMQ_HOST'),
                getenv('RABBITMQ_PORT'),
                getenv('RABBITMQ_USER'),
                getenv('RABBITMQ_PASS'),
                getenv('RABBITMQ_VHOST')
            );
            $channel = $connection->channel();

            $exchange = 'casiel_main_exchange';
            $routingKey = 'casiel.process';

            $channel->exchange_declare($exchange, 'direct', false, true, false);

            $message = new AMQPMessage($payload, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
            ]);

            $channel->basic_publish($message, $exchange, $routingKey);

            $output->writeln("<fg=green>Éxito:</> Mensaje publicado en el exchange '{$exchange}' con la clave de enrutamiento '{$routingKey}'.");

            $channel->close();
            $connection->close();

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln("<error>Error al publicar en RabbitMQ:</error>");
            $output->writeln($e->getMessage());
            return Command::FAILURE;
        }
    }
}
