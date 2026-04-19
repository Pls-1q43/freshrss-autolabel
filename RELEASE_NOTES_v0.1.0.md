# FreshRSS AutoLabel v0.1.0

## Highlights

- Administrator-managed model profiles for LLM and embedding workflows
- User-managed AutoLabel rules with multiple target tags
- Asynchronous queue processing for new entries and backfill jobs
- Provider support for OpenAI, Anthropic, Gemini, and Ollama
- Built-in English, Simplified Chinese, and French documentation

## Supported Modes

- LLM classification:
  - OpenAI
  - Anthropic
  - Gemini
  - Ollama
- Embedding classification:
  - OpenAI
  - Gemini
  - Ollama

## Installation

1. Download the release archive
2. Extract it into your FreshRSS `extensions/` directory
3. Make sure the deployed directory is named:

```text
xExtension-AutoLabel
```

4. Enable the extension in FreshRSS

## Operational Notes

- New entries and backfill jobs are processed through an asynchronous queue
- Automatic queue consumption depends on FreshRSS maintenance
- A dedicated queue worker can also be scheduled separately by administrators
- `batch_size` means **concurrency window size**, not a serial processing count

## Documentation

- English: `README.md`
- 中文: `docs/README.zh-CN.md`
- Français: `docs/README.fr.md`

## Known Notes

- Concurrent queue windows require PHP `curl_multi`
- Anthropic profiles are limited to LLM mode
- Target tags must already exist in FreshRSS

