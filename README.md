# D‑ID AI Provider (di_ai_provider)

A Drupal provider for the **AI module** and **AI Automator** that lets you generate talking‑head videos from **text** or **audio** using the D‑ID API. It’s modeled on the ElevenLabs provider you shared, so it follows the same file structure and plugin conventions.

## Install
1. Place the module in `web/modules/custom/di_ai_provider` (or contrib as you like).
2. `composer install` (ensures the autoloader sees the new namespace).
3. Enable: `drush en di_ai_provider` or from Extend UI.
4. Configure at **Configuration → AI → D‑ID Provider**.



