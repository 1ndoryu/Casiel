import sys
import json
import numpy as np
import librosa

def estimar_tonalidad(audio, sr):
    """
    Estima la tonalidad (nota raíz y escala) de una pista de audio.
    Es una simplificación y puede no ser perfecta, pero es un buen punto de partida.
    """
    try:
        chroma = librosa.feature.chroma_stft(y=audio, sr=sr)
        
        # Correlacionar con plantillas de escalas mayor y menor
        major_profile = np.array([1, 0, 1, 0, 1, 1, 0, 1, 0, 1, 0, 1])
        minor_profile = np.array([1, 0, 1, 1, 0, 1, 0, 1, 1, 0, 1, 0])
        
        # Normalizar perfiles
        major_profile = major_profile / np.linalg.norm(major_profile)
        minor_profile = minor_profile / np.linalg.norm(minor_profile)

        correlations_major = []
        correlations_minor = []

        # Calcular correlación para las 12 posibles tonalidades
        for i in range(12):
            chroma_shifted = np.roll(chroma, -i, axis=0)
            chroma_mean = np.mean(chroma_shifted, axis=1)
            
            correlations_major.append(np.corrcoef(chroma_mean, major_profile)[0, 1])
            correlations_minor.append(np.corrcoef(chroma_mean, minor_profile)[0, 1])
            
        # Encontrar la mejor correlación
        best_major_idx = np.argmax(correlations_major)
        best_minor_idx = np.argmax(correlations_minor)
        
        notas = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B']
        
        if correlations_major[best_major_idx] > correlations_minor[best_minor_idx]:
            return notas[best_major_idx], "major"
        else:
            return notas[best_minor_idx], "minor"

    except Exception:
        # Si el análisis de tonalidad falla, devolvemos null
        return None, None

def analizar_audio(audio_path):
    try:
        # Cargar el audio con librosa
        audio, sr = librosa.load(audio_path, sr=None, mono=True)

        # --- 1. Extraer BPM (Lógica explícita y segura para el linter) ---
        tempo = librosa.beat.beat_track(y=audio, sr=sr)[0]
        bpm_final = None
        
        # Paso A: Obtener un valor numérico.
        numeric_tempo = None
        if isinstance(tempo, np.ndarray):
            if tempo.size > 0:
                # np.mean devuelve un np.float, lo convertimos a float de python.
                numeric_tempo = float(np.mean(tempo))
        elif tempo is not None:
             # Si no es array, es un número. Aún así lo convertimos a float para ser consistentes.
            numeric_tempo = float(tempo)
        
        # Paso B: Validar que el float sea utilizable (no sea None, ni NaN)
        if numeric_tempo is not None and not np.isnan(numeric_tempo):
            bpm_final = int(round(numeric_tempo))

        # --- 2. Estimar Tonalidad ---
        tonalidad, escala = estimar_tonalidad(audio, sr)

        resultados = {
            "bpm": bpm_final,
            "tonalidad": tonalidad,
            "escala": escala
        }
        
        print(json.dumps(resultados))

    except Exception as e:
        print(json.dumps({"error": f"Error en librosa al procesar el archivo: {str(e)}"}), file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    if len(sys.argv) > 1:
        analizar_audio(sys.argv[1])
    else:
        print(json.dumps({"error": "Uso: python audio.py <ruta_del_archivo>"}), file=sys.stderr)
        sys.exit(1)