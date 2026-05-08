# Changelog

## 0.5.0

- Add LLM aggregate classification so one request can classify multiple articles for one model profile
- Add LLM multi-rule classification so one article can be evaluated against multiple AutoLabel prompts in one request
- Support combined article/rule matrix responses that return each article's matched tag list
- Keep LLM aggregate batches usable without `curl_multi`; embedding batch concurrency still requires PHP curl multi support
- Update `batch_size` semantics for LLM profiles to mean the aggregate article window
- Add JSON-mode and free-form LLM request options for OpenAI-compatible and provider-specific controls
- Preserve the per-profile Thinking mode control and strip `<think>...</think>` reasoning blocks before JSON parsing
- Raise the LLM-friendly default timeout and avoid applying the short interactive timeout cap to LLM aggregate requests

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
