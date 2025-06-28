import sys
import json
import numpy as np
import librosa
import hashlib

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
        # Cargar el audio completo para el análisis de BPM y tonalidad
        audio, sr = librosa.load(audio_path, sr=None, mono=True)

        # --- 1. Extraer BPM ---
        tempo = librosa.beat.beat_track(y=audio, sr=sr)[0]
        bpm_final = None
        
        numeric_tempo = None
        if isinstance(tempo, np.ndarray):
            if tempo.size > 0:
                numeric_tempo = float(np.mean(tempo))
        elif tempo is not None:
                numeric_tempo = float(tempo)
        
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
        print(json.dumps({"error": f"Error en librosa al analizar el archivo: {repr(e)}"}), file=sys.stderr)
        sys.exit(1)

def generar_hash_perceptual(audio_path):
    """
    Genera un hash perceptual de los primeros 5 segundos de un archivo de audio.
    """
    try:
        # Cargar solo los primeros 5 segundos del audio
        audio, sr = librosa.load(audio_path, sr=None, mono=True, duration=5.0)

        # Generar MFCCs (Mel-Frequency Cepstral Coefficients)
        mfccs = librosa.feature.mfcc(y=audio, sr=sr, n_mfcc=13)
        
        # Para crear un hash consistente, tomamos la media de cada coeficiente a lo largo del tiempo
        # y redondeamos a un número fijo de decimales para reducir variaciones menores.
        mean_mfccs = np.mean(mfccs, axis=1)
        
        # --- INICIO DE LA CORRECCIÓN ---
        rounded_mfccs = np.round(mean_mfccs, decimals=2) # Error corregido: era mean_efccs
        # --- FIN DE LA CORRECCIÓN ---
        
        # Convertir el array de numpy a un string y luego a bytes para el hash
        mfccs_string = "".join(map(str, rounded_mfccs))
        
        # Calcular el hash SHA256
        hash_sha256 = hashlib.sha256(mfccs_string.encode()).hexdigest()
        
        print(json.dumps({"audio_hash": hash_sha256}))

    except Exception as e:
        print(json.dumps({"error": f"Error en librosa al generar el hash: {repr(e)}"}), file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print(json.dumps({"error": "Uso: python audio.py <comando> <ruta_del_archivo>"}), file=sys.stderr)
        print(json.dumps({"error": "Comandos disponibles: analyze, hash"}), file=sys.stderr)
        sys.exit(1)

    comando = sys.argv[1]
    ruta_archivo = sys.argv[2]

    if comando == "analyze":
        analizar_audio(ruta_archivo)
    elif comando == "hash":
        generar_hash_perceptual(ruta_archivo)
    else:
        print(json.dumps({"error": f"Comando '{comando}' no reconocido."}), file=sys.stderr)
        sys.exit(1)