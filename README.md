# FreshRSS AutoLabel

[English](./README.md) | [中文](./docs/README.zh-CN.md) | [Français](./docs/README.fr.md)

`AutoLabel` is a FreshRSS system extension that automatically adds existing FreshRSS tags to entries by using either:

- LLM classification
- Zero-shot embedding similarity

The extension is designed around a mixed permission model:

- Administrators create and manage model profiles
- Users create and manage their own AutoLabel rules from approved profiles

## Features

- Administrator-managed model profiles
- User-managed AutoLabel rules
- LLM classification with OpenAI, Anthropic, Gemini, and Ollama
- Embedding classification with OpenAI, Gemini, and Ollama
- Multiple AutoLabels per user
- Multiple target tags per AutoLabel
- Asynchronous queue processing for new entries and backfill jobs
- Concurrent queue windows per profile when PHP `curl_multi` is available
- Built-in UI translations for English, Simplified Chinese, and French

## Architecture

- Extension type: `system`
- Admin scope:
  - Create model profiles
  - Define provider, model, mode, batching window, and request defaults
- User scope:
  - Create AutoLabels from enabled profiles
  - Select one or more existing FreshRSS tags
  - Configure prompt or embedding anchors/instruction
- Queue scope:
  - New entries are queued on insert
  - Queue processing runs during FreshRSS user maintenance
  - Optional dedicated queue worker is available for independent scheduling

## Supported Providers

| Provider | LLM | Embeddings |
| --- | --- | --- |
| OpenAI | Yes | Yes |
| Anthropic | Yes | No |
| Gemini | Yes | Yes |
| Ollama | Yes | Yes |

Notes:

- Anthropic profiles are restricted to LLM mode.
- Concurrent queue windows require PHP `curl` with `curl_multi`.
- When concurrency is unavailable, the UI shows a warning and queue concurrency is disabled.

## Installation

1. Copy this repository into your FreshRSS `extensions/` directory.
2. Deploy it under this directory name:

```text
xExtension-AutoLabel
```

3. Enable `AutoLabel` from the FreshRSS extensions page.
4. Open the `AutoLabel` dashboard.

## Queue Processing

AutoLabel uses an asynchronous queue for both new entries and backfill jobs.

- Automatic processing:
  - Runs through the FreshRSS `FreshrssUserMaintenance` hook
- Manual processing:
  - Available from the AutoLabel dashboard
- Optional dedicated worker:
  - Admins can schedule the queue worker separately if they want more predictable throughput

The profile field named `batch_size` is a **concurrency window**, not a serial batch counter. A value of `5` means AutoLabel tries to process up to five article requests at the same time for the same profile, then waits for that window to finish before starting the next one.

## Permissions

- Anonymous users cannot access the AutoLabel dashboard.
- Administrators can see:
  - Model profile management
  - Queue worker URL
  - All shared configuration areas
- Logged-in non-admin users can see:
  - Their own AutoLabel rules
  - Dry run, backfill, queue, and diagnostics areas
- Logged-in non-admin users cannot see or edit administrator model profiles.

## Release Package

The release package is meant to be installed directly into FreshRSS and should unpack to:

```text
xExtension-AutoLabel/
```

Release packaging and audit helpers are included in:

- [`scripts/release-audit.sh`](./scripts/release-audit.sh)
- [`scripts/package-release.sh`](./scripts/package-release.sh)

## Security Review

Before publishing, review:

- [`SECURITY_REVIEW.md`](./SECURITY_REVIEW.md)
- [`RELEASE.md`](./RELEASE.md)

The release audit is intended to catch:

- hard-coded secrets
- instance-specific URLs
- local machine artifacts
- tokenized admin-only worker URLs
- accidental packaging of non-extension files

## Troubleshooting

- If queue throughput is low, verify that FreshRSS maintenance is actually running.
- If concurrent windows are unavailable, check whether PHP `curl_multi` is installed.
- If embedding requests fail on Ollama, review profile timeout, `content_max_chars`, `embedding_num_ctx`, and provider logs together.
- If tags do not appear, make sure the target tags already exist in FreshRSS.

## Project Files

- Extension metadata: [`metadata.json`](./metadata.json)
- Entry point: [`extension.php`](./extension.php)
- Main logic: [`lib/bootstrap.php`](./lib/bootstrap.php)
- Controller: [`Controllers/autolabelController.php`](./Controllers/autolabelController.php)

