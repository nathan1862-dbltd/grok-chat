# Grok PHP

A small PHP chat interface for the xAI API, with optional Cloudflare AI Gateway routing.

## Screenshot

![Screenshot](img/screenshot.webp)

## Features

- Text and JPEG/PNG image inputs
- Session-based chat history
- Markdown rendering with raw HTML disabled
- Direct xAI API access or optional Cloudflare AI Gateway routing
- Mobile-friendly responsive design
- Docker and Render deployment support

## Requirements

- PHP 8.0 or higher
- PHP cURL, Fileinfo, and Session extensions
- A valid [xAI API key](https://console.x.ai/)

## Local setup

1. Clone this repository and enter it:

   ```bash
   git clone https://github.com/365cent/grok-chat.git
   cd grok-chat
   ```

2. Create `.env` with your xAI API key:

   ```ini
   XAI_API_KEY=your-xai-api-key
   XAI_MODEL=grok-4.3
   ```

3. Start the development server:

   ```bash
   php -S localhost:8000
   ```

4. Open <http://localhost:8000>.

The app calls xAI directly when only `XAI_API_KEY` is configured.

## Optional Cloudflare AI Gateway

To route requests through an existing [Cloudflare AI Gateway](https://developers.cloudflare.com/ai-gateway/providers/grok/), add these values:

```ini
CLOUDFLARE_ACCOUNT_ID=your-cloudflare-account-id
CLOUDFLARE_GATEWAY_ID=ai
```

Set `CLOUDFLARE_GATEWAY_ID` to the gateway name shown in Cloudflare. It defaults to `ai` to remain compatible with this project's original deployment URL. The legacy variable names `api-key`, `cf-account-id`, and `cf-gateway-id` remain supported for existing deployments.

## Docker

```bash
docker build -t grok-chat .
docker run --rm -p 8000:8000 -e XAI_API_KEY=your-xai-api-key grok-chat
```

Then open <http://localhost:8000>.

## Render deployment

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/365cent/grok-chat)

Set `XAI_API_KEY` when prompted. Cloudflare values are optional; leave `CLOUDFLARE_ACCOUNT_ID` empty to call xAI directly.

## Thanks

- [xAI API](https://x.ai/)
- [Cloudflare AI Gateway](https://developers.cloudflare.com/ai-gateway/)
- [Render](https://render.com/)
