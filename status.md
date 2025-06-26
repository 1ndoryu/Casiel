# Proyecto: Casiel

Casiel es un servicio de backend construido sobre Webman/Workerman. Su función principal es procesar `samples` de audio. Recibe una notificación, descarga un audio, lo analiza para extraer metadatos técnicos (BPM, tonalidad), genera una versión ligera y utiliza la IA de Google Gemini para obtener metadatos creativos (tags, género, descripción, etc.). Sus responsabilidades clave son exclusivamente 3, actualizar la metadata de los contenidos con audio, actualizar el nombre del archivo y regresar una version ligera.

Nota: Sword v2 se construye junto a Casiel, por lo que si sword requiere cambios, se puede indicar para aplicarlos, puede que requiera cambios Sword para adaptarse. 

# REGLAS 

1.  **Simplificación Extrema:** El código debe ser simple, legible y profesional. Utilizar siempre la menor cantidad de código posible y nunca repetirlo (principio DRY).
2.  **Estándares de Código:** Todo el código se escribe en **inglés** usando la convención `snake_case`. Los nombres de variables y funciones deben ser autoexplicativos pero cortos. La única excepción son los logs y los mensajes de consola para depuración, que deben estar en español y ser claros.
3.  **Funcionalidad y Desacoplada:** Este servicio ahora hará pocas responsabilidades y tiene que ser agnostico al cms lo mas posible, actualizar la metadata de los audios y cambiar su nombre a uno descriptivo ejemplo *kick bass kamples_5481.mp3*, adicionalmente regresa la url de la version ligera. 
4.  **Pruebas obligatorias progresivas** Usar pestphp para los test, los test no deben depender de que exista o no contenido en Sword, pero los test deben probar la conexión, crear el contenido en sword, gestionarlo, etc. Las pruebas tienen que ser lo más completa posible, empezar por lo basico y progresar poco a poco.
5.  **Comentarios Mínimos:** El código debe ser tan claro que no necesite explicaciones. Si se requiere un comentario, será conciso, en inglés y para explicar el "porqué" de una decisión compleja, no el "qué" hace el código.
6.  **Documentación Viva:** La documentación principal es el archivo `README.md`, que debe mantenerse conciso y actualizado. Si la documentación se vuelve excesivamente compleja, es una señal de que el proyecto se ha desviado de su objetivo de simplicidad.
7.  **Flujo de Tareas Claro:** Al final de cada iteración, se entregará exclusivamente la sección "Flujo de Tareas y Lluvia de Ideas" actualizada, simplificando siempre las descripciones de las tareas completadas.
8.  **Logs por Canal:** Cada funcionalidad principal debe tener su propio canal de logs en un archivo separado. Existirá un log `master.log` que capture toda la actividad. El nivel de log mínimo será configurable desde el archivo `.env`. Los logs deben durar maximo 15 días.
9.  **Arquitectura Limpia:** La estructura de archivos debe ser organizada y granular. Evitar archivos excesivamente grandes y con múltiples responsabilidades.
10. **Entrega de Código Completa:** Al modificar código, se debe proporcionar siempre el método o archivo completo, evitando fragmentos o código omitido para garantizar la integridad y facilidad de implementación.
11. **Sugerencia de Herramientas:** Se valorará y podrá adoptar cualquier herramienta externa que simplifique el desarrollo, reduzca la cantidad de código y acelere el progreso.


---

#

#

#

#

#

# TAREAS COMPLETADAS

[ ] NINGUNA; ESTAMOS EMPEZANDO.

# LLUVIA DE IDEAS

[ ] No se por donde empezar.