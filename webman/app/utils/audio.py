import sys
import json
import essentia.standard as es
from essentia import EssentiaException

def analizar_audio(audio_path):
  try:
    # Cargar el audio
    loader = es.MonoLoader(filename=audio_path)
    audio = loader()

    # Extraer BPM
    rhythm_extractor = es.RhythmExtractor2013(method="multifeature")
    bpm, _, _, _, _ = rhythm_extractor(audio)

    # Extraer Tonalidad
    key_extractor = es.KeyExtractor()
    key, scale, strength = key_extractor(audio)

    # Crear diccionario con resultados. Redondeamos el BPM.
    resultados = {
      "bpm": round(bpm),
      "tonalidad": key,
      "escala": scale,
      "precision_tonalidad": strength
    }
   
    # Devolver el resultado como una cadena JSON en la salida estándar
    print(json.dumps(resultados))

  except EssentiaException as e:
    # Enviar errores a la salida de error estándar y salir con código de error
    print(f"Error de Essentia al procesar {audio_path}: {e}", file=sys.stderr)
    sys.exit(1)
  except Exception as e:
    print(f"Error inesperado en script Python: {e}", file=sys.stderr)
    sys.exit(1)

if __name__ == "__main__":
  if len(sys.argv) > 1:
    analizar_audio(sys.argv[1])
  else:
    print("Uso: python audio.py <ruta_del_archivo_de_audio>", file=sys.stderr)
    sys.exit(1)