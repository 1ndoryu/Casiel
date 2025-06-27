# Proyecto: Casiel

Casiel es un servicio de backend construido sobre Webman/Workerman. Su función principal es procesar audios. Recibe una notificación, descarga un audio, lo analiza para extraer metadatos técnicos (BPM, tonalidad), genera una versión ligera y utiliza la IA de Google Gemini para obtener metadatos creativos (tags, género, descripción, etc.). Sus responsabilidades clave son exclusivamente 3, actualizar la metadata de los contenidos con audio, actualizar el nombre del archivo y regresar una version ligera.

Nota: Sword v2 se construye junto a Casiel, por lo que si sword requiere cambios, se puede indicar para aplicarlos, puede que requiera cambios Sword para adaptarse. Casiel no usa base de datos, ni almacena contenido, esa debe ser responsabilidad del cms. He adelantado un script llamado audio.py que hace algunas cositas, y un env con datos utiles. Tambien deje un ejemplo de como use gemini en un proyecto anterior.

Otra nota: tiene que ser compatible con windows (estamos en windows), y con linux.

---

## Arquitectura

El sistema funciona de forma asíncrona y está diseñado para ser robusto y escalable.

1.  **Recepción del Trabajo**: Un cliente (como Sword CMS) publica un mensaje en una cola de **RabbitMQ** cuando un nuevo audio necesita ser procesado. El mensaje contiene el ID del contenido a procesar.
2.  **Consumo del Mensaje**: El proceso `AudioQueueConsumer` de Casiel, que corre de forma continua, recoge el mensaje de la cola.
3.  **Orquestación de Servicios**: El consumidor orquesta una serie de llamadas a diferentes servicios de forma secuencial y asíncrona:
    -   **`SwordApiService`**: Se conecta al CMS para obtener la URL del archivo de audio original.
    -   **Descarga**: Descarga el archivo de audio a un directorio temporal local.
    -   **`AudioAnalysisService`**: Ejecuta un script de Python (`audio.py`) que usa `librosa` para extraer metadatos técnicos como BPM y tonalidad.
    -   **`GeminiService`**: Envía el audio a la API de Google Gemini para obtener metadatos creativos (género, tags, descripción, un nombre de archivo sugerido, etc.).
    -   **Generación de Versión Ligera**: `AudioAnalysisService` utiliza `ffmpeg` para crear una versión del audio en MP3 a 96kbps.
    -   **Subida y Actualización**: `SwordApiService` sube la nueva versión ligera al CMS y luego actualiza el registro original del contenido con toda la metadata (técnica y creativa) y el nuevo nombre/slug.
4.  **Manejo de Fallos**: Si cualquier paso falla, el sistema utiliza una estrategia de reintentos con una cola de espera en RabbitMQ. Si el mensaje falla repetidamente, se mueve a una "Dead-Letter Queue" final para inspección manual.
5.  **Limpieza**: Al finalizar el proceso (ya sea con éxito o fallo), todos los archivos temporales creados localmente son eliminados.

Para una explicación detallada de la estrategia de RabbitMQ, consulta el archivo `docs/rabbitmq_setup.md`.

## Dependencias Externas

Para que Casiel funcione correctamente, el entorno (ya sea local o en un contenedor Docker) debe tener las siguientes dependencias instaladas y accesibles en el PATH del sistema:

-   **`php`**: Versión 8.1 o superior.
-   **`composer`**: Para gestionar las dependencias de PHP.
-   **`python3`**: Para ejecutar el script de análisis de audio.
-   **`pip3`**: Para instalar las dependencias de Python (`librosa`, `numpy`).
-   **`ffmpeg`**: Herramienta esencial para la manipulación de audio, usada para generar la versión ligera.

## Variables de Entorno

El sistema se configura a través del archivo `.env`. A continuación se describen las variables clave:

| Variable              | Descripción                                                            | Ejemplo               |
| --------------------- | ---------------------------------------------------------------------- | --------------------- |
| `RABBITMQ_HOST`       | Host del servidor RabbitMQ.                                            | `localhost`           |
| `RABBITMQ_PORT`       | Puerto del servidor RabbitMQ.                                          | `5672`                |
| `RABBITMQ_USER`       | Usuario para la conexión con RabbitMQ.                                 | `user`                |
| `RABBITMQ_PASS`       | Contraseña para la conexión con RabbitMQ.                              | `password`            |
| `RABBITMQ_VHOST`      | Virtual Host a utilizar en RabbitMQ.                                   | `/`                   |
| `RABBITMQ_WORK_QUEUE` | Nombre de la cola de la que Casiel consumirá los trabajos.             | `kamples_queue`       |
| `SWORD_API_URL`       | URL base de la API del CMS Sword.                                      | `http://swordphp.com` |
| `SWORD_API_USER`      | Usuario para autenticarse en la API de Sword.                          | `wan`                 |
| `SWORD_API_PASSWORD`  | Contraseña para la API de Sword.                                       | `uFpHLR9FXqVCFy9`     |
| `GEMINI_API_KEY`      | Tu clave de API para Google Gemini.                                    | `AIzaSy...`           |
| `GEMINI_MODEL_ID`     | El modelo específico de Gemini a utilizar (compatible con audio).      | `gemini-1.5-flash`    |
| `LOG_LEVEL`           | Nivel mínimo de logs a registrar (debug, info, warning, error).        | `debug`               |
| `LOG_MAX_FILES`       | Número máximo de archivos de log a rotar antes de borrar los antiguos. | `15`                  |
