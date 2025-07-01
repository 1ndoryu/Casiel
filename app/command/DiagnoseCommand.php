<?php

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

class DiagnoseCommand extends Command
{
    protected static $defaultName = 'diagnose';
    protected static $defaultDescription = 'Runs a diagnostic check on all external services and dependencies.';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>--- Iniciando Diagnóstico de Casiel ---</info>');
        $allOk = true;

        // 1. Check .env variables
        $output->write('1. Variables de entorno (.env)... ');
        $envVars = ['RABBITMQ_HOST', 'SWORD_API_URL', 'GEMINI_API_KEY', 'PYTHON_COMMAND', 'FFMPEG_PATH'];
        $missingVars = [];
        foreach ($envVars as $var) {
            if (empty(getenv($var))) {
                $missingVars[] = $var;
            }
        }
        if (empty($missingVars)) {
            $output->writeln('<fg=green>OK</>');
        } else {
            $output->writeln('<fg=red>FAIL</>');
            $output->writeln('<error>  - Variables faltantes: ' . implode(', ', $missingVars) . '</error>');
            $allOk = false;
        }

        // 2. Check Python command
        $output->write('2. Comando de Python... ');
        try {
            $pythonCommand = getenv('PYTHON_COMMAND') ?: 'python3';
            $process = new Process([$pythonCommand, '--version']);
            $process->run();
            if ($process->isSuccessful()) {
                $version = trim($process->getOutput()) . trim($process->getErrorOutput());
                $output->writeln('<fg=green>OK</> (' . $version . ')');
            } else {
                $output->writeln('<fg=red>FAIL</>');
                $output->writeln('<error>  - ' . $process->getErrorOutput() . '</error>');
                $allOk = false;
            }
        } catch (Throwable $e) {
            $output->writeln('<fg=red>FAIL</>');
            $output->writeln('<error>  - ' . $e->getMessage() . '</error>');
            $allOk = false;
        }

        // 3. Check FFmpeg command
        $output->write('3. Comando de FFmpeg... ');
        try {
            $ffmpegPath = getenv('FFMPEG_PATH') ?: 'ffmpeg';
            $process = new Process([$ffmpegPath, '-version']);
            $process->run();
            if ($process->isSuccessful()) {
                $output->writeln('<fg=green>OK</>');
            } else {
                $output->writeln('<fg=red>FAIL</>');
                $output->writeln('<error>  - ' . $process->getErrorOutput() . '</error>');
                $allOk = false;
            }
        } catch (Throwable $e) {
            $output->writeln('<fg=red>FAIL</>');
            $output->writeln('<error>  - ' . $e->getMessage() . '</error>');
            $allOk = false;
        }

        // 4. Check RabbitMQ connection
        $output->write('4. Conexión con RabbitMQ... ');
        try {
            $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection(
                getenv('RABBITMQ_HOST'),
                getenv('RABBITMQ_PORT'),
                getenv('RABBITMQ_USER'),
                getenv('RABBITMQ_PASS'),
                getenv('RABBITMQ_VHOST')
            );
            $connection->close();
            $output->writeln('<fg=green>OK</>');
        } catch (Throwable $e) {
            $output->writeln('<fg=red>FAIL</>');
            $output->writeln('<error>  - ' . $e->getMessage() . '</error>');
            $allOk = false;
        }

        $output->writeln('5. Autenticación con Sword API... <fg=yellow>OMITIDO</> (Implementación asíncrona)');
        $output->writeln('6. Verificación de API Key de Gemini... <fg=yellow>OMITIDO</> (Implementación asíncrona)');

        $output->writeln('');
        if ($allOk) {
            $output->writeln('<info>--- Diagnóstico completado. Todos los chequeos básicos pasaron. ---</info>');
        } else {
            $output->writeln('<error>--- Diagnóstico completado. Se encontraron problemas de configuración. ---</error>');
        }

        return $allOk ? Command::SUCCESS : Command::FAILURE;
    }
}
