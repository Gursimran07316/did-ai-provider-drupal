# D‑ID AI Provider (di_ai_provider)

A Drupal provider for the **AI module** and **AI Automator** that lets you generate talking‑head videos from **text** or **audio** using the D‑ID API. It’s modeled on the ElevenLabs provider you shared, so it follows the same file structure and plugin conventions.

## Install
1. Place the module in `web/modules/custom/di_ai_provider` (or contrib as you like).
2. `composer install` (ensures the autoloader sees the new namespace).
3. Enable: `drush en di_ai_provider` or from Extend UI.
4. Configure at **Configuration → AI → D‑ID Provider**.

## Configure
- API key: from your D‑ID dashboard (Basic auth). 
- Default avatar image: a public URL to the face image for lip‑sync.
- Optional voice id: if you want to pair with ElevenLabs for TTS.
- Driver: `talking_photo` or `studio` (depends on your D‑ID plan).

## AI Automator
Two capabilities are registered:
- `text_to_video(text, source_image?, voice_id?, driver?) → {url, id}`
- `audio_to_video(audio_url, source_image?, driver?) → {url, id}`

Create a workflow in **AI → Automator** and pick the D‑ID provider + the desired operation. Pass input fields as needed (or rely on defaults from settings).

## Notes
- Endpoints differ by D‑ID plan; adjust `DidApiService::postRender()` if your plan uses a different path.
- If your AI module exposes a different provider base class, update `DiAiProvider` to extend the correct class (see the ElevenLabs provider in your codebase for the exact import).
- If you use the **Key** module for secrets, swap the plain text API key for a key reference.