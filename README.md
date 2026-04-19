# FreshRSS AutoLabel

[![Release](https://img.shields.io/github/v/release/Pls-1q43/freshrss-autolabel)](https://github.com/Pls-1q43/freshrss-autolabel/releases)
[![License](https://img.shields.io/github/license/Pls-1q43/freshrss-autolabel)](./LICENSE)
[![FreshRSS](https://img.shields.io/badge/FreshRSS-extension-1f7aec)](https://github.com/Pls-1q43/freshrss-autolabel)
[![Docs](https://img.shields.io/badge/docs-English%20%7C%20%E4%B8%AD%E6%96%87%20%7C%20Fran%C3%A7ais-0f766e)](https://github.com/Pls-1q43/freshrss-autolabel)

[English](./README.md) | [中文](./docs/README.zh-CN.md) | [Français](./docs/README.fr.md)

Automatically label FreshRSS entries with either LLM classification or embedding-based zero-shot matching.

Maintained by [Pls](https://1q43.blog).  
Project page, usage notes, and updates: [github.com/Pls-1q43/freshrss-autolabel](https://github.com/Pls-1q43/freshrss-autolabel)

## What It Does

AutoLabel lets administrators publish approved model profiles and lets each user build personal labeling rules on top of those profiles.

- Admin-managed model profiles
- User-managed AutoLabel rules
- LLM mode and embedding mode
- Multiple existing FreshRSS target tags per rule
- Asynchronous queue processing for new entries and backfill jobs
- Optional concurrent queue windows when PHP `curl_multi` is available

## Supported Providers

| Provider | LLM | Embeddings |
| --- | --- | --- |
| OpenAI | Yes | Yes |
| Anthropic | Yes | No |
| Gemini | Yes | Yes |
| Ollama | Yes | Yes |

Notes:

- Anthropic is restricted to LLM mode.
- Embedding target tags must already exist in FreshRSS.
- Queue concurrency depends on PHP `curl_multi`.

## Recommended Ollama Embedding Setup

For zero-shot classification with Ollama, a good default starting point is:

- Model: `qwen3-embedding:0.6b`
- Max content length: `1500`
- `Embedding num_ctx`: `2000`
- Instruct / instruction: write it in English
- Similarity threshold: `0.65`

This setup is especially suitable for lightweight local embedding classification.

## Installation

### Option 1: Download the release package

1. Download the latest release from [GitHub Releases](https://github.com/Pls-1q43/freshrss-autolabel/releases).
2. Extract it into your FreshRSS `extensions/` directory.
3. Make sure the deployed directory is named:

```text
xExtension-AutoLabel
```

4. Enable `AutoLabel` from the FreshRSS extensions page.

### Option 2: Clone manually

```bash
cd /path/to/FreshRSS/extensions
git clone https://github.com/Pls-1q43/freshrss-autolabel.git xExtension-AutoLabel
```

Then enable the extension from FreshRSS.

## Configuration Model

### Administrator side

Administrators manage:

- provider
- model
- mode (`LLM` or `Embedding`)
- base URL
- API key
- timeout
- max content length
- concurrency window (`batch_size`)
- embedding dimensions
- embedding `num_ctx`
- default instruction

`batch_size` means **concurrency window size**, not a serial batch count.

### User side

Users manage:

- AutoLabel rule name
- target tags
- selected profile
- rule mode
- prompt
- embedding anchors
- similarity threshold
- instruction

## Queue Processing

AutoLabel uses an asynchronous queue for:

- newly inserted entries
- backfill jobs

Queue consumption can happen through:

- FreshRSS `FreshrssUserMaintenance`
- the manual queue action in the dashboard
- an optional dedicated queue worker for administrators

## Permissions

- Anonymous users cannot access the AutoLabel dashboard.
- Administrators can see model profile management and the queue worker URL.
- Logged-in non-admin users can manage their own rules, queue, dry runs, backfill, and diagnostics.
- Logged-in non-admin users cannot access admin profile management.

## Troubleshooting

- Queue keeps growing:
  - confirm FreshRSS maintenance is actually running
- No visible concurrency:
  - confirm PHP has `curl_multi`
- Ollama embedding timeouts:
  - review `content_max_chars`, `timeout_seconds`, `embedding_num_ctx`, and Ollama logs together
- Tags are not applied:
  - confirm the target tags already exist in FreshRSS

## License

This project is distributed under **GNU GPL 3.0**.  
See [LICENSE](./LICENSE).
