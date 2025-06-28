# 🎶 Casiel: The AI-Powered Audio Co-Pilot

[](https://php.net)
[](https://www.workerman.net)
[](https://www.google.com/search?q=LICENSE)

Welcome to **Casiel**, your intelligent backend service designed to automatically analyze, enrich, and process your audio files. Think of Casiel as an automated studio assistant that works 24/7 to make your audio content smarter and more accessible.

Casiel listens for new audio uploads, downloads them, and uses a combination of technical analysis and cutting-edge AI to generate a rich set of metadata. It then seamlessly integrates this information back into your primary system or CMS, saving you countless hours of manual work.

## ✨ Key Features

  * **⚙️ Fully Automated Workflow:** A "fire-and-forget" system. Once a new audio file is flagged, Casiel handles the entire process from start to finish.
  * **🎼 Technical Metadata Extraction:** Automatically detects crucial audio properties like **BPM (Beats Per Minute)** and **musical key/scale** using the powerful `librosa` library.
  * **🤖 AI-Powered Creative Analysis:** Leverages the **Google Gemini AI** to generate insightful and creative metadata, including:
      * Descriptive tags (e.g., "melodic", "dark", "808")
      * Musical genres (e.g., "Hip Hop", "Electronic")
      * Evoked emotions (e.g., "energetic", "sad", "chill")
      * Instrument detection
      * Artist "vibes"
      * SEO-friendly descriptions and titles.
  * **🚀 Lightweight Version Generation:** Creates a web-friendly, low-bitrate (96kbps) MP3 preview of the original audio, perfect for fast-loading players.
  * **🔗 Seamless CMS Integration:** Designed to be agnostic and connect with any content management system (currently configured for **Sword v2**). It updates content with the new metadata and can even suggest a better, more descriptive filename.
  * **🪟 Cross-Platform:** Built to run consistently on both **Windows** for development and **Linux** for production.

## ⚙️ How It Works: A High-Level Look

Casiel operates as a robust, asynchronous pipeline. The journey of an audio file looks like this:

1.  **Event Trigger** ➡️ A message is sent to a **🐰 RabbitMQ** queue, telling Casiel that a new audio file is ready for processing.
2.  **Job Received** ➡️ Casiel's consumer process picks up the job from the queue.
3.  **Download** 📥 ➡️ Casiel fetches the original audio file from its source URL.
4.  **Technical Analysis** 🎼 ➡️ A Python script is executed to extract technical data (BPM, key).
5.  **Creative Analysis** 🧠 ➡️ The audio is sent to the **Google Gemini API** for deep, creative metadata generation.
6.  **Optimization** 🎧 ➡️ **FFmpeg** is used to create a lightweight MP3 preview of the audio.
7.  **Integration** 💾 ➡️ Casiel communicates with the CMS API to:
      * Upload the new lightweight version.
      * Update the original content entry with all the new metadata.
      * Rename the file/slug to something more descriptive.
8.  **Completion** ✅ ➡️ Temporary files are cleaned up, and the job is marked as complete.

If any step fails, the system automatically attempts to retry the job before setting it aside for manual review.

## 🛠️ Technology Stack

Casiel is built with modern, high-performance tools:

| Technology | Icon | Purpose |
| :--- | :-: | :--- |
| **PHP 8.2+ (Workerman)** |  | Core application logic, running on a high-concurrency socket server. |
| **RabbitMQ** |  | Asynchronous message queuing to manage processing jobs. |
| **Python** |  | Running `librosa` and `numpy` for technical audio analysis. |
| **Google Gemini** |  | State-of-the-art AI for creative metadata generation. |
| **FFmpeg** |  | The industry standard for audio/video conversion and processing. |
| **Docker** |  | For containerized, reproducible deployments. |

## 🚀 Getting Started

To get Casiel up and running, you'll need the following prerequisites installed on your system.

### **Prerequisites**

  * PHP 8.1+
  * Composer
  * Python 3.x with Pip
  * FFmpeg (must be accessible from your system's PATH)
  * Git

### **Installation**

1.  **Clone the repository:**

    ```bash
    git clone https://github.com/1ndoryu/Casiel
    cd casiel
    ```

2.  **Install PHP dependencies:**

    ```bash
    composer install
    ```

3.  **Install Python dependencies:**

    ```bash
    pip install -r requirements.txt
    ```

4.  **Configure your environment:**

      * Copy the example environment file: `cp .env.example .env`
      * Edit the `.env` file with your credentials for **RabbitMQ**, **Sword API**, and **Google Gemini AI**.

5.  **Start the server:**

      * **On Linux/Mac:**
        ```bash
        php start.php start
        ```
      * **On Windows:**
        ```bash
        # Simply double-click the `windows.bat` file or run it from your terminal.
        windows.bat
        ```

    Casiel's internal processes, including the RabbitMQ consumer, will now be running in the background.

## 🔧 Configuration

All system configuration is handled through the `.env` file. Here are the most important variables you'll need to set:

| Variable | Description |
| :--- | :--- |
| `RABBITMQ_*` | Connection details for your RabbitMQ server. |
| `SWORD_API_URL` | The base URL for your content management system's API. |
| `SWORD_API_USER` | The username for authenticating with the Sword API. |
| `SWORD_API_PASSWORD` | The password for the Sword API user. |
| `GEMINI_API_KEY` | Your API key for the Google Gemini service. |
| `PYTHON_COMMAND` | The command to execute Python (`python` on Windows, `python3` on Linux). |
| `FFMPEG_PATH` | The full path to the `ffmpeg` executable. Defaults to `ffmpeg`, but on Windows you might need `C:\\ffmpeg\\bin\\ffmpeg.exe`. |

## ❤️ Contributing

We believe in the power of community\! If you've found a bug, have an idea for a new feature, or want to improve the project, please feel free to:

  * Open an issue on GitHub to report bugs or suggest features.
  * Fork the repository and submit a pull request with your changes.

Let's make audio processing simpler and smarter, together\!

