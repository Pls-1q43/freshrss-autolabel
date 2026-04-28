# Changelog

## 0.2.0

- Add per-profile "Thinking mode" setting for LLM profiles (auto / disabled / enabled) to control reasoning behaviour on models such as qwen3, deepseek-r1, and gpt-oss
- Send native `think` flag to Ollama and append `/think` or `/no_think` hints to the system prompt for broader compatibility
- Always strip `<think>...</think>` reasoning blocks from model responses so JSON parsing stays reliable regardless of the selected mode

## 0.1.1

- Improve automatic maintenance and cron queue draining so background runs continue processing until the queue is effectively empty
- Add author links to the dashboard header and rewrite the public English, Chinese, and French documentation
- Switch the public project license to GPL-3.0

## 0.1.0

- Initial public release of the FreshRSS AutoLabel extension
- Admin-managed model profiles
- User-managed AutoLabel rules
- LLM and embedding-based classification
- Asynchronous queue processing and backfill
- English, Simplified Chinese, and French documentation
