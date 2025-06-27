<?php

use app\process\AudioQueueConsumer;
use app\services\AudioAnalysisService;
use app\services\GeminiService;
use app\services\SwordApiService;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Workerman\Timer;

// Establece una función de ayuda global para simular la creación de archivos.
function touch_file(string $path): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    touch($path);
}

test('el consumidor procesa exitosamente un mensaje', function () {
    // -- 1. CONFIGURACIÓN --
    echo "\n--- INICIANDO TEST DE INTEGRACIÓN DEL CONSUMIDOR ---\n";

    // Variables de prueba
    $contentId = 123;
    $tempDir = runtime_path() . '/tmp/test_processing';
    $originalFile = "{$tempDir}/{$contentId}_original.mp3";
    $lightFile = "{$tempDir}/{$contentId}_light.mp3";

    // Limpieza inicial por si el test anterior falló
    if (is_dir($tempDir)) {
        system("rm -rf " . escapeshellarg($tempDir));
    }
    mkdir($tempDir, 0777, true);

    // Mocks de los servicios
    /** @var SwordApiService&MockInterface $mockSwordApi */
    $mockSwordApi = Mockery::mock(SwordApiService::class);
    /** @var AudioAnalysisService&MockInterface $mockAudioAnalysis */
    $mockAudioAnalysis = Mockery::mock(AudioAnalysisService::class);
    /** @var GeminiService&MockInterface $mockGemini */
    $mockGemini = Mockery::mock(GeminiService::class);
    /** @var AMQPChannel&MockInterface $mockChannel */
    $mockChannel = Mockery::mock(AMQPChannel::class);

    // Mock del mensaje de RabbitMQ
    /** @var AMQPMessage&MockInterface $mockMessage */
    // SOLUCIÓN: El error BadMethodCallException ocurría porque se creaba un "partial mock"
    // y se pasaban argumentos al constructor, lo que llamaba al método `setBody` sin una expectativa de Mockery.
    // La solución es crear un mock completo y establecer sus propiedades y expectativas de forma manual.
    // Así evitamos la ejecución del constructor original y tenemos control total sobre el objeto.
    $messageBody = json_encode(['data' => ['id' => $contentId]]);
    $mockMessage = Mockery::mock(AMQPMessage::class);
    $mockMessage->body = $messageBody;
    $mockMessage->delivery_info['channel'] = $mockChannel;
    $mockMessage->delivery_info['delivery_tag'] = 'test_delivery_tag';
    // Para el flujo de éxito, la lógica de reintentos llamará a `has()` y debe devolver false.
    $mockMessage->shouldReceive('has')->with('application_headers')->andReturn(false);


    // Datos falsos que retornarán los servicios
    $fakeMediaDetails = ['path' => 'uploads/audio/original.mp3', 'metadata' => ['original_name' => 'test_track.mp3']];
    $fakeTechData = ['bpm' => 128, 'tonalidad' => 'A', 'escala' => 'minor'];
    $fakeCreativeData = ['nombre_archivo_base' => 'dark synthwave loop', 'tags' => ['synthwave', 'dark', '80s'], 'genero' => ['electronic', 'synthwave']];
    $fakeLightVersionData = ['path' => 'uploads/media/light_version.mp3'];

    // -- 2. EXPECTATIVAS (El guion de la película) --

    echo "PASO 1: Configurando expectativa -> Se deben pedir los detalles del medio a Sword.\n";
    $mockSwordApi->shouldReceive('getMediaDetails')
        ->once()
        ->with($contentId, Mockery::any(), Mockery::any())
        ->andReturnUsing(function ($id, $onSuccess, $onError) use ($fakeMediaDetails, $originalFile) {
            echo "  -> OK: SwordApiService::getMediaDetails llamado. Simulando descarga del audio.\n";
            // Simular la descarga del archivo que ocurriría después.
            touch_file($originalFile);
            expect(file_exists($originalFile))->toBeTrue();
            $onSuccess($fakeMediaDetails);
        });

    echo "PASO 2: Configurando expectativa -> Se debe analizar el audio para obtener datos técnicos.\n";
    $mockAudioAnalysis->shouldReceive('analyze')
        ->once()
        ->with($originalFile)
        ->andReturnUsing(function($path) use ($fakeTechData) {
            echo "  -> OK: AudioAnalysisService::analyze llamado. Devolviendo datos técnicos: " . json_encode($fakeTechData) . "\n";
            return $fakeTechData;
        });


    echo "PASO 3: Configurando expectativa -> Se debe pedir a Gemini los datos creativos.\n";
    $mockGemini->shouldReceive('analyzeAudio')
        ->once()
        ->with($originalFile, Mockery::any(), Mockery::any(), Mockery::any())
        ->andReturnUsing(function ($path, $context, $onSuccess, $onError) use ($fakeCreativeData) {
            echo "  -> OK: GeminiService::analyzeAudio llamado.\n";
            echo "     Contexto enviado a Gemini: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
            echo "     Respuesta simulada de Gemini: " . json_encode($fakeCreativeData, JSON_PRETTY_PRINT) . "\n";
            $onSuccess($fakeCreativeData);
        });

    echo "PASO 4: Configurando expectativa -> Se debe generar la versión ligera.\n";
    $mockAudioAnalysis->shouldReceive('generateLightweightVersion')
        ->once()
        ->with($originalFile, $lightFile)
        ->andReturnUsing(function () use ($lightFile) {
            echo "  -> OK: AudioAnalysisService::generateLightweightVersion llamado. Simulando creación del archivo ligero.\n";
            touch_file($lightFile); // Simular creación del archivo
            expect(file_exists($lightFile))->toBeTrue();
            return true;
        });

    echo "PASO 5: Configurando expectativa -> Se debe subir la versión ligera a Sword.\n";
    $mockSwordApi->shouldReceive('uploadMedia')
        ->once()
        ->with($lightFile, Mockery::any(), Mockery::any())
        ->andReturnUsing(function ($path, $onSuccess, $onError) use ($fakeLightVersionData) {
            echo "  -> OK: SwordApiService::uploadMedia llamado. Devolviendo URL de la versión ligera.\n";
            $onSuccess($fakeLightVersionData);
        });

    echo "PASO 6: Configurando expectativa -> Se debe actualizar el contenido final en Sword.\n";
    $mockSwordApi->shouldReceive('updateContent')
        ->once()
        ->with($contentId, Mockery::on(function ($finalData) use ($fakeTechData, $fakeCreativeData, $fakeLightVersionData) {
            echo "  -> OK: SwordApiService::updateContent llamado.\n";
            echo "     Payload final enviado a Sword: " . json_encode($finalData, JSON_PRETTY_PRINT) . "\n";
            expect($finalData['content_data']['bpm'])->toBe($fakeTechData['bpm']);
            expect($finalData['content_data']['tags'])->toBe($fakeCreativeData['tags']);
            expect($finalData['content_data']['light_version_url'])->toBe($fakeLightVersionData['path']);
            expect($finalData['slug'])->toStartWith(str_replace(' ', '_', $fakeCreativeData['nombre_archivo_base']));
            return true;
        }), Mockery::any(), Mockery::any())
        ->andReturnUsing(function ($id, $data, $onSuccess, $onError) {
            echo "  -> OK: Contenido actualizado en Sword. Llamando a onSuccess final.\n";
            $onSuccess();
        });

    echo "PASO 7: Configurando expectativa -> Se debe confirmar (ACK) el mensaje a RabbitMQ.\n";
    $mockMessage->shouldReceive('ack')->once()->andReturnUsing(function() {
        echo "  -> OK: Mensaje confirmado (ack) en la cola.\n";
    });

    // -- 3. ACCIÓN --
    echo "--- EJECUTANDO AudioQueueConsumer::processMessage ---\n";
    // Usamos una clase anónima para poder llamar al método protegido `processMessage` directamente en el test.
    $consumer = new class($mockSwordApi, $mockAudioAnalysis, $mockGemini) extends AudioQueueConsumer
    {
        public function processMessage(AMQPMessage $msg, $channel): void
        {
            parent::processMessage($msg, $channel);
        }
    };

    $consumer->processMessage($mockMessage, $mockChannel);

    // -- 4. ASERCIONES FINALES Y LIMPIEZA --

    // Workerman funciona con un bucle de eventos, necesitamos esperar un instante para que los timers de limpieza se ejecuten.
    Timer::add(1.1, function () use ($originalFile, $lightFile, $tempDir) {
        echo "--- VERIFICACIÓN POST-EJECUCIÓN ---\n";
        echo "Verificando que los archivos temporales han sido eliminados.\n";
        expect(file_exists($originalFile))->toBeFalse("El archivo original NO fue eliminado.");
        expect(file_exists($lightFile))->toBeFalse("El archivo ligero NO fue eliminado.");

        // Limpieza final del directorio de prueba
        if (is_dir($tempDir)) {
            system("rm -rf " . escapeshellarg($tempDir));
        }

        Mockery::close();
        echo "--- TEST COMPLETADO EXITOSAMENTE ---\n";
    }, null, false);
});