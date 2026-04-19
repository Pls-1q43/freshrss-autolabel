<?php

declare(strict_types=1);

final class AutoLabelViewState {
	/** @var array<string,mixed> */
	private static array $state = [];

	/**
	 * @param array<string,mixed> $state
	 */
	public static function replace(array $state): void {
		self::$state = $state;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		return self::$state;
	}
}

final class AutoLabelSystemProfileRepository {
	public const DEFAULT_TIMEOUT_SECONDS = 15;
	public const DEFAULT_CONTENT_MAX_CHARS = 6000;
	public const DEFAULT_BATCH_SIZE = 25;
	public const MAX_BATCH_SIZE = 200;
	public const MAX_EMBEDDING_DIMENSIONS = 65536;
	public const MAX_EMBEDDING_NUM_CTX = 1048576;

	/** @var list<string> */
	private const PROVIDERS = ['openai', 'anthropic', 'gemini', 'ollama'];
	/** @var list<string> */
	private const MODES = ['llm', 'embedding'];

	/** @var AutoLabelExtension */
	private $extension;

	public function __construct(AutoLabelExtension $extension) {
		$this->extension = $extension;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function all(): array {
		$profiles = $this->extension->profilesConfiguration();
		if (!is_array($profiles)) {
			return [];
		}

		$normalized = [];
		foreach ($profiles as $profile) {
			if (is_array($profile)) {
				$normalized[] = $this->normalizeStoredProfile($profile);
			}
		}

		usort($normalized, static fn (array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));
		return $normalized;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function enabled(): array {
		return array_values(array_filter(
			$this->all(),
			static fn (array $profile): bool => (bool)$profile['enabled']
		));
	}

	public function find(string $id): ?array {
		foreach ($this->all() as $profile) {
			if ($profile['id'] === $id) {
				return $profile;
			}
		}

		return null;
	}

	public function defaultProfile(): array {
		return [
			'id' => '',
			'name' => '',
			'provider' => 'openai',
			'model' => '',
			'base_url' => self::defaultBaseUrlForProvider('openai'),
			'api_key' => '',
			'enabled' => true,
			'profile_mode' => 'llm',
			'supports_llm' => true,
			'supports_embedding' => false,
			'timeout_seconds' => self::DEFAULT_TIMEOUT_SECONDS,
			'content_max_chars' => self::DEFAULT_CONTENT_MAX_CHARS,
			'batch_size' => self::DEFAULT_BATCH_SIZE,
			'embedding_dimensions' => 0,
			'embedding_num_ctx' => 0,
			'default_instruction' => '',
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function saveFromPayload(array $payload): array {
		$profiles = $this->all();
		$existingProfile = null;
		$requestedId = trim((string)($payload['id'] ?? ''));
		foreach ($profiles as $profile) {
			if ($requestedId !== '' && $profile['id'] === $requestedId) {
				$existingProfile = $profile;
				break;
			}
		}
		if (is_array($existingProfile) && trim((string)($payload['api_key'] ?? '')) === '') {
			$payload['api_key'] = $existingProfile['api_key'];
		}
		$normalized = $this->normalizeIncomingProfile($payload);
		$found = false;

		foreach ($profiles as $index => $profile) {
			if ($profile['id'] === $normalized['id']) {
				$profiles[$index] = $normalized;
				$found = true;
				break;
			}
		}

		if (!$found) {
			$profiles[] = $normalized;
		}

		$this->extension->saveProfilesConfiguration($profiles);
		return $normalized;
	}

	public function delete(string $id): void {
		$profiles = array_values(array_filter(
			$this->all(),
			static fn (array $profile): bool => $profile['id'] !== $id
		));
		$this->extension->saveProfilesConfiguration($profiles);
	}

	public function setEnabled(string $id, bool $enabled): void {
		$profiles = $this->all();
		foreach ($profiles as $index => $profile) {
			if ($profile['id'] === $id) {
				$profile['enabled'] = $enabled;
				$profiles[$index] = $profile;
				$this->extension->saveProfilesConfiguration($profiles);
				return;
			}
		}

		throw new RuntimeException('Unknown profile.');
	}

	/**
	 * @return list<string>
	 */
	public function providers(): array {
		return self::PROVIDERS;
	}

	/**
	 * @return list<string>
	 */
	public function modes(): array {
		return self::MODES;
	}

	public static function defaultBaseUrlForProvider(string $provider): string {
		switch ($provider) {
			case 'openai':
				return 'https://api.openai.com';
			case 'anthropic':
				return 'https://api.anthropic.com';
			case 'gemini':
				return 'https://generativelanguage.googleapis.com';
			case 'ollama':
				return 'http://127.0.0.1:11434';
			default:
				return '';
		}
	}

	public static function normalizeBatchSize(int $batchSize): int {
		return max(1, min(self::MAX_BATCH_SIZE, $batchSize));
	}

	/**
	 * @param array<string,mixed> $profile
	 * @return array<string,mixed>
	 */
	private function normalizeStoredProfile(array $profile): array {
		$profile = array_merge($this->defaultProfile(), $profile);
		$profile['id'] = is_string($profile['id']) && $profile['id'] !== '' ? $profile['id'] : 'profile_' . bin2hex(random_bytes(6));
		$profile['provider'] = in_array($profile['provider'], self::PROVIDERS, true) ? $profile['provider'] : 'openai';
		$profile['profile_mode'] = $this->normalizeProfileMode($profile);
		$profile['enabled'] = (bool)$profile['enabled'];
		$profile['supports_llm'] = $profile['profile_mode'] === 'llm';
		$profile['supports_embedding'] = $profile['profile_mode'] === 'embedding';
		$profile['timeout_seconds'] = max(3, min(120, (int)$profile['timeout_seconds']));
		$profile['content_max_chars'] = max(500, min(20000, (int)$profile['content_max_chars']));
		$profile['batch_size'] = self::normalizeBatchSize((int)$profile['batch_size']);
		$profile['embedding_dimensions'] = max(0, min(self::MAX_EMBEDDING_DIMENSIONS, (int)$profile['embedding_dimensions']));
		$profile['embedding_num_ctx'] = max(0, min(self::MAX_EMBEDDING_NUM_CTX, (int)$profile['embedding_num_ctx']));
		$profile['base_url'] = trim((string)$profile['base_url']);
		if ($profile['base_url'] === '') {
			$profile['base_url'] = self::defaultBaseUrlForProvider($profile['provider']);
		}
		$profile['name'] = trim((string)$profile['name']);
		$profile['model'] = trim((string)$profile['model']);
		$profile['api_key'] = trim((string)$profile['api_key']);
		$profile['default_instruction'] = trim((string)$profile['default_instruction']);

		return $profile;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function normalizeIncomingProfile(array $payload): array {
		$profile = $this->normalizeStoredProfile($payload);
		if ($profile['name'] === '') {
			throw new InvalidArgumentException('Profile name is required.');
		}
		if ($profile['model'] === '') {
			throw new InvalidArgumentException('Model is required.');
		}
		if ($profile['provider'] === 'anthropic' && $profile['supports_embedding']) {
			throw new InvalidArgumentException('Anthropic profiles can only use LLM mode.');
		}

		return $profile;
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	private function normalizeProfileMode(array $profile): string {
		$requestedMode = trim((string)($profile['profile_mode'] ?? ''));
		if (in_array($requestedMode, self::MODES, true)) {
			return $requestedMode;
		}

		$supportsLlm = !empty($profile['supports_llm']);
		$supportsEmbedding = !empty($profile['supports_embedding']);
		if ($supportsEmbedding && !$supportsLlm) {
			return 'embedding';
		}
		if ($supportsLlm && !$supportsEmbedding) {
			return 'llm';
		}

		$model = strtolower(trim((string)($profile['model'] ?? '')));
		if ($supportsEmbedding && str_contains($model, 'embed')) {
			return 'embedding';
		}

		return 'llm';
	}
}

final class AutoLabelRuntimeBatchGate {
	/** @var array<string,int> */
	private static array $countsByProfile = [];
	/** @var array<string,array<string,bool>> */
	private static array $seenEntryKeysByProfile = [];

	/**
	 * @param array<string,mixed> $profile
	 */
	public static function claim(array $profile, FreshRSS_Entry $entry): bool {
		$profileKey = self::profileKey($profile);
		$entryKey = self::entryKey($entry);
		if (isset(self::$seenEntryKeysByProfile[$profileKey][$entryKey])) {
			return true;
		}

		if (!self::hasCapacity($profile)) {
			return false;
		}

		if (!isset(self::$seenEntryKeysByProfile[$profileKey])) {
			self::$seenEntryKeysByProfile[$profileKey] = [];
		}
		self::$seenEntryKeysByProfile[$profileKey][$entryKey] = true;
		self::$countsByProfile[$profileKey] = (self::$countsByProfile[$profileKey] ?? 0) + 1;
		return true;
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	public static function hasCapacity(array $profile): bool {
		$profileKey = self::profileKey($profile);
		return (self::$countsByProfile[$profileKey] ?? 0) < self::limitForProfile($profile);
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	private static function profileKey(array $profile): string {
		$profileId = trim((string)($profile['id'] ?? ''));
		if ($profileId !== '') {
			return $profileId;
		}

		return hash('sha256', implode('|', [
			(string)($profile['provider'] ?? ''),
			(string)($profile['model'] ?? ''),
			(string)($profile['base_url'] ?? ''),
		]));
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	private static function limitForProfile(array $profile): int {
		return AutoLabelSystemProfileRepository::normalizeBatchSize((int)($profile['batch_size'] ?? AutoLabelSystemProfileRepository::DEFAULT_BATCH_SIZE));
	}

	private static function entryKey(FreshRSS_Entry $entry): string {
		if (method_exists($entry, 'id') && (int)$entry->id() > 0) {
			return 'id:' . (string)$entry->id();
		}

		if (method_exists($entry, 'guid')) {
			$guid = trim((string)$entry->guid());
			if ($guid !== '') {
				return 'guid:' . $guid;
			}
		}

		$link = method_exists($entry, 'link') ? (string)$entry->link(true) : '';
		$title = method_exists($entry, 'title') ? (string)$entry->title() : '';
		$date = method_exists($entry, 'date') ? (string)$entry->date(true) : '';
		return hash('sha256', implode('|', [$link, $title, $date]));
	}
}

final class AutoLabelProfileCapabilityResolver {
	/**
	 * @param array<string,mixed> $profile
	 * @return list<string>
	 */
	public function modesForProfile(array $profile): array {
		$modes = [];
		if (!empty($profile['supports_llm'])) {
			$modes[] = 'llm';
		}
		if (!empty($profile['supports_embedding'])) {
			$modes[] = 'embedding';
		}
		return $modes;
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	public function supportsMode(array $profile, string $mode): bool {
		return in_array($mode, $this->modesForProfile($profile), true);
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	public function supportsInstruction(array $profile, string $mode): bool {
		return $mode === 'embedding' && $this->supportsMode($profile, 'embedding');
	}
}

final class AutoLabelUserRuleRepository {
	public const DEFAULT_THRESHOLD = 0.75;

	/** @var AutoLabelExtension */
	private $extension;
	/** @var AutoLabelSystemProfileRepository */
	private $profiles;
	/** @var AutoLabelProfileCapabilityResolver */
	private $capabilities;

	public function __construct(
		AutoLabelExtension $extension,
		AutoLabelSystemProfileRepository $profiles,
		AutoLabelProfileCapabilityResolver $capabilities
	) {
		$this->extension = $extension;
		$this->profiles = $profiles;
		$this->capabilities = $capabilities;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function all(): array {
		$rules = $this->extension->rulesConfiguration();
		if (!is_array($rules)) {
			return [];
		}

		$normalized = [];
		foreach ($rules as $rule) {
			if (is_array($rule)) {
				$normalized[] = $this->normalizeStoredRule($rule);
			}
		}

		usort($normalized, static fn (array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));
		return $normalized;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function enabled(): array {
		return array_values(array_filter(
			$this->all(),
			static fn (array $rule): bool => (bool)$rule['enabled']
		));
	}

	public function find(string $id): ?array {
		foreach ($this->all() as $rule) {
			if ($rule['id'] === $id) {
				return $rule;
			}
		}
		return null;
	}

	public function defaultRule(): array {
		return [
			'id' => '',
			'name' => '',
			'enabled' => true,
			'target_tags' => [],
			'profile_id' => '',
			'mode' => 'llm',
			'llm_prompt' => '',
			'embedding_anchor_texts' => [],
			'embedding_threshold' => self::DEFAULT_THRESHOLD,
			'embedding_instruction' => '',
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function saveFromPayload(array $payload): array {
		$rules = $this->all();
		$normalized = $this->normalizeIncomingRule($payload);
		$found = false;

		foreach ($rules as $index => $rule) {
			if ($rule['id'] === $normalized['id']) {
				$rules[$index] = $normalized;
				$found = true;
				break;
			}
		}

		if (!$found) {
			$rules[] = $normalized;
		}

		$this->extension->saveRulesConfiguration($rules);
		return $normalized;
	}

	public function delete(string $id): void {
		$rules = array_values(array_filter(
			$this->all(),
			static fn (array $rule): bool => $rule['id'] !== $id
		));
		$this->extension->saveRulesConfiguration($rules);
	}

	public function setEnabled(string $id, bool $enabled): void {
		$rules = $this->all();
		foreach ($rules as $index => $rule) {
			if ($rule['id'] === $id) {
				$rule['enabled'] = $enabled;
				$rules[$index] = $rule;
				$this->extension->saveRulesConfiguration($rules);
				return;
			}
		}

		throw new RuntimeException('Unknown rule.');
	}

	/**
	 * @param array<string,mixed> $rule
	 * @return array<string,mixed>
	 */
	private function normalizeStoredRule(array $rule): array {
		$rule = array_merge($this->defaultRule(), $rule);
		$rule['id'] = is_string($rule['id']) && $rule['id'] !== '' ? $rule['id'] : 'rule_' . bin2hex(random_bytes(6));
		$rule['name'] = trim((string)$rule['name']);
		$rule['enabled'] = (bool)$rule['enabled'];
		$rule['target_tags'] = $this->normalizeTargetTags(
			$rule['target_tags'] ?? ($rule['target_tag'] ?? [])
		);
		$rule['profile_id'] = trim((string)$rule['profile_id']);
		$rule['mode'] = $rule['mode'] === 'embedding' ? 'embedding' : 'llm';
		$rule['llm_prompt'] = trim((string)$rule['llm_prompt']);
		$rule['embedding_anchor_texts'] = $this->normalizeAnchorTexts($rule['embedding_anchor_texts']);
		$rule['embedding_threshold'] = max(0.0, min(1.0, (float)$rule['embedding_threshold']));
		$rule['embedding_instruction'] = trim((string)$rule['embedding_instruction']);

		return $rule;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function normalizeIncomingRule(array $payload): array {
		$rule = $this->normalizeStoredRule($payload);
		if (count($rule['target_tags']) === 0) {
			throw new InvalidArgumentException('At least one target tag is required.');
		}
		if ($rule['name'] === '') {
			$rule['name'] = implode(', ', $rule['target_tags']);
		}

		$profile = $this->profiles->find($rule['profile_id']);
		if ($profile === null) {
			throw new InvalidArgumentException('Please choose a valid model profile.');
		}
		if (!$this->capabilities->supportsMode($profile, $rule['mode'])) {
			throw new InvalidArgumentException('The selected profile does not support this mode.');
		}
		if ($rule['mode'] === 'embedding' && count($rule['embedding_anchor_texts']) === 0) {
			throw new InvalidArgumentException('Embedding rules require at least one anchor text.');
		}

		return $rule;
	}

	/**
	 * @param mixed $targetTags
	 * @return list<string>
	 */
	private function normalizeTargetTags($targetTags): array {
		if (is_string($targetTags)) {
			$targetTags = [$targetTags];
		}
		if (!is_array($targetTags)) {
			return [];
		}

		$normalized = [];
		foreach ($targetTags as $targetTag) {
			$targetTag = ltrim(trim((string)$targetTag), '#');
			if ($targetTag !== '') {
				$normalized[$targetTag] = $targetTag;
			}
		}

		return array_values($normalized);
	}

	/**
	 * @return list<string>
	 */
	private function normalizeAnchorTexts($anchorTexts): array {
		if (is_string($anchorTexts)) {
			$anchorTexts = preg_split('/\R/u', $anchorTexts) ?: [];
		}
		if (!is_array($anchorTexts)) {
			return [];
		}

		$normalized = [];
		foreach ($anchorTexts as $anchorText) {
			$anchorText = trim((string)$anchorText);
			if ($anchorText !== '') {
				$normalized[$anchorText] = $anchorText;
			}
		}

		return array_values($normalized);
	}
}

final class AutoLabelEntryTextExtractor {
	/**
	 * @return array{title:string,content:string,feed:string,authors:string,url:string,text:string,embedding_text:string}
	 */
	public function extractContext(FreshRSS_Entry $entry, int $maxChars): array {
		$title = trim($entry->title());
		$content = $this->normalizeText($entry->content(false));
		$authors = method_exists($entry, 'authors') ? trim((string)$entry->authors(true)) : trim($entry->author());
		$url = trim(htmlspecialchars_decode($entry->link(true), ENT_QUOTES | ENT_HTML5));
		$feedTitle = '';
		$feed = $entry->feed();
		if ($feed !== null && method_exists($feed, 'name')) {
			$feedTitle = trim((string)$feed->name());
		}

		$text = $this->buildContextText($title, $feedTitle, $authors, $url, $content, true, $maxChars);
		$embeddingText = $this->buildContextText($title, $feedTitle, $authors, $url, $content, false, $maxChars);

		return [
			'title' => $title,
			'content' => $content,
			'feed' => $feedTitle,
			'authors' => $authors,
			'url' => $url,
			'text' => $text,
			'embedding_text' => $embeddingText,
		];
	}

	private function buildContextText(
		string $title,
		string $feedTitle,
		string $authors,
		string $url,
		string $content,
		bool $includeUrl,
		int $maxChars
	): string {
		$parts = [];
		if ($title !== '') {
			$parts[] = "Title: {$title}";
		}
		if ($feedTitle !== '') {
			$parts[] = "Feed: {$feedTitle}";
		}
		if ($authors !== '') {
			$parts[] = "Authors: {$authors}";
		}
		if ($includeUrl && $url !== '') {
			$parts[] = "URL: {$url}";
		}
		if ($content !== '') {
			$parts[] = "Content:\n{$content}";
		}

		$text = trim(implode("\n\n", $parts));
		if ($text === '') {
			$text = $title;
		}

		if (mb_strlen($text, 'UTF-8') > $maxChars) {
			$text = mb_substr($text, 0, $maxChars, 'UTF-8');
		}

		return $text;
	}

	private function normalizeText(string $html): string {
		$decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = strip_tags($decoded);
		$text = preg_replace('/\R{3,}/u', "\n\n", (string)$text) ?? '';
		$text = preg_replace('/[ \t]+/u', ' ', $text) ?? '';
		return trim($text);
	}
}

final class AutoLabelEmbeddingCacheStore {
	private const CACHE_FILE = 'embedding-cache.json';

	/** @var AutoLabelExtension */
	private $extension;

	public function __construct(AutoLabelExtension $extension) {
		$this->extension = $extension;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read(): array {
		$content = $this->extension->readUserDataFile(self::CACHE_FILE);
		if (!is_string($content) || $content === '') {
			return [];
		}

		$data = json_decode($content, true);
		return is_array($data) ? $data : [];
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function write(array $data): void {
		$this->extension->writeUserDataFile(
			self::CACHE_FILE,
			(string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
	}

	/**
	 * @return list<float>|null
	 */
	public function get(string $key): ?array {
		$data = $this->read();
		$vector = $data[$key]['vector'] ?? null;
		if (!is_array($vector)) {
			return null;
		}
		return array_values(array_map(static fn ($value): float => (float)$value, $vector));
	}

	/**
	 * @param list<float> $vector
	 */
	public function set(string $key, array $vector): void {
		$data = $this->read();
		$data[$key] = [
			'updated_at' => time(),
			'vector' => array_values($vector),
		];
		$this->write($data);
	}
}

final class AutoLabelDiagnosticsStore {
	private const DIAGNOSTICS_FILE = 'diagnostics.json';
	private const MAX_RECORDS = 50;
	private const MAX_STRING_LENGTH = 2000;
	private const MAX_ARRAY_ITEMS = 25;
	private const MAX_DEPTH = 5;

	/** @var AutoLabelExtension */
	private $extension;

	public function __construct(AutoLabelExtension $extension) {
		$this->extension = $extension;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function all(): array {
		$content = $this->extension->readUserDataFile(self::DIAGNOSTICS_FILE);
		if (!is_string($content) || $content === '') {
			return [];
		}

		$data = json_decode($content, true);
		if (!is_array($data)) {
			return [];
		}

		return array_values(array_filter($data, 'is_array'));
	}

	/**
	 * @param array<string,mixed> $record
	 */
	public function append(array $record): void {
		if (!$this->extension->diagnosticsEnabled()) {
			return;
		}

		$records = $this->all();
		array_unshift($records, array_merge([
			'at' => date(DATE_ATOM),
		], $this->sanitizeValue($record, 0)));
		$records = array_slice($records, 0, self::MAX_RECORDS);
		$this->extension->writeUserDataFile(
			self::DIAGNOSTICS_FILE,
			(string)json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
	}

	public function clear(): void {
		$this->extension->deleteUserDataFile(self::DIAGNOSTICS_FILE);
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function sanitizeValue($value, int $depth) {
		if ($depth >= self::MAX_DEPTH) {
			return '[truncated depth]';
		}

		if (is_string($value)) {
			if (mb_strlen($value, 'UTF-8') <= self::MAX_STRING_LENGTH) {
				return $value;
			}

			return mb_substr($value, 0, self::MAX_STRING_LENGTH, 'UTF-8') . '… [truncated]';
		}

		if (!is_array($value)) {
			return $value;
		}

		$sanitized = [];
		$count = 0;
		foreach ($value as $key => $item) {
			if ($count >= self::MAX_ARRAY_ITEMS) {
				$sanitized['__truncated__'] = 'Additional items were truncated.';
				break;
			}

			$sanitized[$key] = $this->sanitizeValue($item, $depth + 1);
			$count++;
		}

		return $sanitized;
	}
}

final class AutoLabelQueueStore {
	private const QUEUE_FILE = 'queue.json';
	private const MANUAL_RUN_FILE = 'queue-run.json';
	private const MAX_ITEMS = 5000;

	/** @var AutoLabelExtension */
	private $extension;

	public function __construct(AutoLabelExtension $extension) {
		$this->extension = $extension;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function allItems(): array {
		$data = $this->read();
		return array_values(array_filter($data['items'] ?? [], 'is_array'));
	}

	public function version(): int {
		$data = $this->read();
		return max(0, (int)($data['version'] ?? 0));
	}

	/**
	 * @return array{pending_entries:int,pending_backfills:int,pending_backfill_entries:int,last_run:array<string,mixed>|null}
	 */
	public function snapshot(): array {
		$items = $this->allItems();
		$pendingEntries = 0;
		$pendingBackfills = 0;
		$pendingBackfillEntries = 0;
		foreach ($items as $item) {
			if (($item['type'] ?? '') === 'entry') {
				++$pendingEntries;
			} elseif (($item['type'] ?? '') === 'backfill') {
				++$pendingBackfills;
				$state = is_array($item['state'] ?? null) ? $item['state'] : [];
				$limit = max(0, (int)($state['limit'] ?? 0));
				$processed = max(0, (int)($state['processed'] ?? 0));
				$pendingBackfillEntries += max(0, $limit - $processed);
			}
		}

		$data = $this->read();
		$lastRun = is_array($data['last_run'] ?? null) ? $data['last_run'] : null;

		return [
			'pending_entries' => $pendingEntries,
			'pending_backfills' => $pendingBackfills,
			'pending_backfill_entries' => $pendingBackfillEntries,
			'last_run' => $lastRun,
		];
	}

	/**
	 * @param list<string> $ruleIds
	 */
	public function enqueueEntry(FreshRSS_Entry $entry, array $ruleIds = [], string $source = 'reception'): bool {
		$data = $this->read();
		$item = [
			'id' => 'queue_' . bin2hex(random_bytes(6)),
			'type' => 'entry',
			'source' => $source,
			'rule_ids' => $this->normalizeRuleIds($ruleIds),
			'enqueued_at' => date(DATE_ATOM),
			'attempts' => 0,
			'next_attempt_at' => 0,
			'entry' => [
				'entry_id' => method_exists($entry, 'id') ? (int)$entry->id() : 0,
				'feed_id' => method_exists($entry, 'feedId') ? (int)$entry->feedId() : 0,
				'guid' => method_exists($entry, 'guid') ? trim((string)$entry->guid()) : '',
				'link' => method_exists($entry, 'link') ? trim((string)$entry->link(true)) : '',
				'title' => method_exists($entry, 'title') ? trim((string)$entry->title()) : '',
				'date' => method_exists($entry, 'date') ? (int)$entry->date(true) : time(),
			],
		];
		$dedupeKey = $this->dedupeKey($item);
		foreach ($data['items'] as $existingItem) {
			if (!is_array($existingItem)) {
				continue;
			}
			if (($existingItem['type'] ?? '') !== 'entry') {
				continue;
			}
			if ($this->dedupeKey($existingItem) === $dedupeKey) {
				return false;
			}
		}

		array_unshift($data['items'], $item);
		$data['items'] = array_slice(array_values(array_filter($data['items'], 'is_array')), 0, self::MAX_ITEMS);
		$data['version'] = max(0, (int)($data['version'] ?? 0)) + 1;
		$this->write($data);
		return true;
	}

	/**
	 * @param list<string> $ruleIds
	 * @return array<string,mixed>
	 */
	public function enqueueBackfillJob(array $ruleIds, int $lookbackDays, int $limit): array {
		$data = $this->read();
		$item = [
			'id' => 'backfill_' . bin2hex(random_bytes(6)),
			'type' => 'backfill',
			'enqueued_at' => date(DATE_ATOM),
			'rule_ids' => $this->normalizeRuleIds($ruleIds),
			'state' => [
				'lookback_days' => max(1, min(3650, $lookbackDays)),
				'limit' => max(1, min(1000, $limit)),
				'offset' => 0,
				'processed' => 0,
				'updated' => 0,
				'matched_tags' => 0,
				'concurrent_entries' => 0,
				'fallback_entries' => 0,
			],
		];

		array_unshift($data['items'], $item);
		$data['items'] = array_slice(array_values(array_filter($data['items'], 'is_array')), 0, self::MAX_ITEMS);
		$data['version'] = max(0, (int)($data['version'] ?? 0)) + 1;
		$this->write($data);
		return $item;
	}

	/**
	 * @param list<array<string,mixed>> $items
	 * @param array<string,mixed>|null $lastRun
	 */
	public function replaceItems(array $items, ?array $lastRun = null, ?int $expectedVersion = null): bool {
		$data = $this->read();
		if ($expectedVersion !== null && max(0, (int)($data['version'] ?? 0)) !== $expectedVersion) {
			return false;
		}
		$data['items'] = array_values(array_filter($items, 'is_array'));
		if ($lastRun !== null) {
			$data['last_run'] = $lastRun;
		}
		$data['version'] = max(0, (int)($data['version'] ?? 0)) + 1;
		$this->write($data);
		return true;
	}

	public function clear(bool $resetLastRun = false): void {
		$data = $this->read();
		$data['items'] = [];
		if ($resetLastRun) {
			$data['last_run'] = null;
		}
		$data['version'] = max(0, (int)($data['version'] ?? 0)) + 1;
		$this->write($data);
		$this->clearManualRun();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function manualRun(): array {
		$snapshot = $this->snapshot();
		$content = $this->extension->readUserDataFile(self::MANUAL_RUN_FILE);
		if (!is_string($content) || $content === '') {
			return $this->normalizeManualRun([], $snapshot);
		}

		$data = json_decode($content, true);
		if (!is_array($data)) {
			return $this->normalizeManualRun([], $snapshot);
		}

		return $this->normalizeManualRun($data, $snapshot);
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	public function saveManualRun(array $state): array {
		$normalized = $this->normalizeManualRun($state, $this->snapshot());
		$this->extension->writeUserDataFile(
			self::MANUAL_RUN_FILE,
			(string)json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
		return $normalized;
	}

	/**
	 * @param array<string,mixed> $snapshot
	 * @return array<string,mixed>
	 */
	public function startManualRun(array $snapshot): array {
		return $this->saveManualRun([
			'run_id' => 'manual_' . bin2hex(random_bytes(6)),
			'status' => $this->snapshotWorkTotal($snapshot) > 0 ? 'running' : 'completed',
			'started_at' => date(DATE_ATOM),
			'updated_at' => date(DATE_ATOM),
			'initial_total' => $this->snapshotWorkTotal($snapshot),
			'last_snapshot' => $snapshot,
			'processed_total' => 0,
			'progress_percent' => $this->snapshotWorkTotal($snapshot) > 0 ? 0 : 100,
			'error' => '',
		]);
	}

	public function clearManualRun(): void {
		$this->extension->deleteUserDataFile(self::MANUAL_RUN_FILE);
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $snapshot
	 * @return array<string,mixed>
	 */
	private function normalizeManualRun(array $state, array $snapshot): array {
		$initialTotal = max(0, (int)($state['initial_total'] ?? 0));
		$currentTotal = $this->snapshotWorkTotal($snapshot);
		$status = (string)($state['status'] ?? 'idle');
		$processedTotal = max(0, $initialTotal - min($initialTotal, $currentTotal));
		$progressPercent = $initialTotal > 0
			? (int)max(0, min(100, round(($processedTotal / $initialTotal) * 100)))
			: ($currentTotal === 0 ? 100 : 0);

		return [
			'run_id' => trim((string)($state['run_id'] ?? '')),
			'status' => in_array($status, ['idle', 'running', 'completed', 'error'], true)
				? $status
				: 'idle',
			'started_at' => trim((string)($state['started_at'] ?? '')),
			'updated_at' => trim((string)($state['updated_at'] ?? '')),
			'initial_total' => $initialTotal,
			'last_snapshot' => $snapshot,
			'processed_total' => $processedTotal,
			'progress_percent' => $progressPercent,
			'error' => trim((string)($state['error'] ?? '')),
		];
	}

	/**
	 * @param array<string,mixed> $snapshot
	 */
	private function snapshotWorkTotal(array $snapshot): int {
		return max(0, (int)($snapshot['pending_entries'] ?? 0)) + max(0, (int)($snapshot['pending_backfill_entries'] ?? 0));
	}

	/**
	 * @return array{items:list<array<string,mixed>>,last_run:array<string,mixed>|null,version:int}
	 */
	private function read(): array {
		$content = $this->extension->readUserDataFile(self::QUEUE_FILE);
		if (!is_string($content) || $content === '') {
			return ['items' => [], 'last_run' => null, 'version' => 0];
		}

		$data = json_decode($content, true);
		if (!is_array($data)) {
			return ['items' => [], 'last_run' => null, 'version' => 0];
		}

		return [
			'items' => array_values(array_filter($data['items'] ?? [], 'is_array')),
			'last_run' => is_array($data['last_run'] ?? null) ? $data['last_run'] : null,
			'version' => max(0, (int)($data['version'] ?? 0)),
		];
	}

	/**
	 * @param array{items:list<array<string,mixed>>,last_run:array<string,mixed>|null} $data
	 */
	private function write(array $data): void {
		$this->extension->writeUserDataFile(
			self::QUEUE_FILE,
			(string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
	}

	/**
	 * @param list<string> $ruleIds
	 * @return list<string>
	 */
	private function normalizeRuleIds(array $ruleIds): array {
		$normalized = [];
		foreach ($ruleIds as $ruleId) {
			$ruleId = trim((string)$ruleId);
			if ($ruleId !== '') {
				$normalized[$ruleId] = $ruleId;
			}
		}
		return array_values($normalized);
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private function dedupeKey(array $item): string {
		$entry = is_array($item['entry'] ?? null) ? $item['entry'] : [];
		$ruleIds = array_values(array_filter(array_map(static fn ($ruleId): string => trim((string)$ruleId), is_array($item['rule_ids'] ?? null) ? $item['rule_ids'] : [])));
		return hash('sha256', implode('|', [
			(string)($entry['guid'] ?? ''),
			(string)($entry['link'] ?? ''),
			(string)($entry['title'] ?? ''),
			(string)($entry['date'] ?? ''),
			(string)($entry['feed_id'] ?? ''),
			implode(',', $ruleIds),
		]));
	}
}

final class AutoLabelHttpClient {
	public function supportsConcurrent(): bool {
		return function_exists('curl_init') && function_exists('curl_multi_init');
	}

	/**
	 * @param array<string,string> $headers
	 * @return array{status:int,body:string,json:mixed}
	 */
	public function postJson(string $url, array $payload, array $headers, int $timeoutSeconds): array {
		$headers['Content-Type'] = 'application/json';
		$headers['Accept'] = 'application/json';
		$encoded = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			if ($ch === false) {
				throw new RuntimeException('Failed to initialize HTTP client.');
			}

			curl_setopt_array($ch, [
				CURLOPT_POST => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
				CURLOPT_POSTFIELDS => $encoded,
				CURLOPT_TIMEOUT => $timeoutSeconds,
				CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_NOSIGNAL => true,
			]);
			if (defined('CURL_HTTP_VERSION_1_1')) {
				curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
			}

			$body = curl_exec($ch);
			if ($body === false) {
				$error = curl_error($ch);
				curl_close($ch);
				throw new RuntimeException('HTTP request failed: ' . $error);
			}

			$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);
		} else {
			$formattedHeaders = [];
			foreach ($headers as $name => $value) {
				$formattedHeaders[] = "{$name}: {$value}";
			}

			$context = stream_context_create([
				'http' => [
					'method' => 'POST',
					'header' => implode("\r\n", $formattedHeaders),
					'content' => $encoded,
					'timeout' => $timeoutSeconds,
					'ignore_errors' => true,
				],
			]);

			$body = @file_get_contents($url, false, $context);
			if ($body === false) {
				throw new RuntimeException('HTTP request failed.');
			}

			$status = 200;
			$headersLine = $http_response_header[0] ?? '';
			if (preg_match('/\s(\d{3})\s/', (string)$headersLine, $matches) === 1) {
				$status = (int)$matches[1];
			}
		}

		return $this->normalizeResponse($status, $body);
	}

	/**
	 * @param list<array{id:string,url:string,payload:array<string,mixed>,headers:array<string,string>,timeout_seconds:int}> $requests
	 * @return array<string,array{ok:bool,status?:int,body?:string,json?:mixed,error?:string,transport?:string}>
	 */
	public function postJsonConcurrent(array $requests): array {
		if (!$this->supportsConcurrent()) {
			throw new RuntimeException('Concurrent batch execution requires the PHP curl extension.');
		}
		if (count($requests) === 0) {
			return [];
		}

		$multi = curl_multi_init();
		if ($multi === false) {
			throw new RuntimeException('Failed to initialize concurrent HTTP client.');
		}

		$handles = [];
		foreach ($requests as $request) {
			$requestId = trim((string)($request['id'] ?? ''));
			if ($requestId === '') {
				continue;
			}

			$ch = curl_init((string)$request['url']);
			if ($ch === false) {
				$handles[$requestId] = ['handle' => null, 'error' => 'Failed to initialize HTTP client handle.'];
				continue;
			}

			$headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
			$headers['Content-Type'] = 'application/json';
			$headers['Accept'] = 'application/json';
			$timeoutSeconds = max(1, (int)($request['timeout_seconds'] ?? 15));
			curl_setopt_array($ch, [
				CURLOPT_POST => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
				CURLOPT_POSTFIELDS => (string)json_encode($request['payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				CURLOPT_TIMEOUT => $timeoutSeconds,
				CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_NOSIGNAL => true,
			]);

			curl_multi_add_handle($multi, $ch);
			$handles[$requestId] = [
				'handle' => $ch,
				'error' => '',
				'request' => $request,
			];
		}

		try {
			$running = 0;
			do {
				do {
					$status = curl_multi_exec($multi, $running);
				} while (defined('CURLM_CALL_MULTI_PERFORM') && $status === CURLM_CALL_MULTI_PERFORM);

				if ($status !== CURLM_OK) {
					break;
				}
				if ($running > 0) {
					$selected = curl_multi_select($multi, 1.0);
					if ($selected === -1) {
						usleep(10000);
					}
				}
			} while ($running > 0);

			$results = [];
			foreach ($handles as $requestId => $handleInfo) {
				$ch = $handleInfo['handle'] ?? null;
				if (!is_resource($ch) && !(is_object($ch) && get_class($ch) === 'CurlHandle')) {
					$results[$requestId] = [
						'ok' => false,
						'error' => (string)($handleInfo['error'] ?? 'Failed to initialize HTTP client handle.'),
						'transport' => 'concurrent',
					];
					continue;
				}

				$body = curl_multi_getcontent($ch);
				$errno = curl_errno($ch);
				$error = curl_error($ch);
				$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
				curl_multi_remove_handle($multi, $ch);
				curl_close($ch);

				if ($errno !== 0) {
					$results[$requestId] = $this->retryConcurrentFallback(
						$handleInfo['request'] ?? null,
						'HTTP request failed: ' . $error
					);
					continue;
				}
				if ($status <= 0 && (!is_string($body) || trim($body) === '')) {
					$results[$requestId] = $this->retryConcurrentFallback(
						$handleInfo['request'] ?? null,
						'HTTP request failed: empty response from provider.'
					);
					continue;
				}

				try {
					$response = $this->normalizeResponse($status, is_string($body) ? $body : '');
					$results[$requestId] = [
						'ok' => true,
						'status' => $response['status'],
						'body' => $response['body'],
						'json' => $response['json'],
						'transport' => 'concurrent',
					];
				} catch (Throwable $throwable) {
					$results[$requestId] = [
						'ok' => false,
						'error' => $throwable->getMessage(),
						'status' => $status,
						'body' => is_string($body) ? $body : '',
						'transport' => 'concurrent',
					];
				}
			}

			return $results;
		} finally {
			curl_multi_close($multi);
		}
	}

	/**
	 * @param array<string,string> $headers
	 * @return list<string>
	 */
	private function formatHeaders(array $headers): array {
		$formatted = [];
		foreach ($headers as $name => $value) {
			$formatted[] = "{$name}: {$value}";
		}
		return $formatted;
	}

	/**
	 * @return array{status:int,body:string,json:mixed}
	 */
	private function normalizeResponse(int $status, string $body): array {
		$json = json_decode($body, true);
		if ($status >= 400) {
			$message = is_array($json) ? (string)json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $body;
			throw new RuntimeException("HTTP {$status}: {$message}");
		}

		return [
			'status' => $status,
			'body' => $body,
			'json' => $json,
		];
	}

	/**
	 * @param mixed $request
	 * @return array{ok:bool,status?:int,body?:string,json?:mixed,error?:string,transport?:string}
	 */
	private function retryConcurrentFallback($request, string $fallbackError): array {
		if (!is_array($request)) {
			return [
				'ok' => false,
				'error' => $fallbackError,
				'transport' => 'fallback_failed',
			];
		}

		try {
			$response = $this->postJson(
				(string)($request['url'] ?? ''),
				is_array($request['payload'] ?? null) ? $request['payload'] : [],
				is_array($request['headers'] ?? null) ? $request['headers'] : [],
				max(1, (int)($request['timeout_seconds'] ?? 15))
			);
			return [
				'ok' => true,
				'status' => $response['status'],
				'body' => $response['body'],
				'json' => $response['json'],
				'transport' => 'fallback_retry',
			];
		} catch (Throwable $throwable) {
			return [
				'ok' => false,
				'error' => $fallbackError . ' Retry failed: ' . $throwable->getMessage(),
				'transport' => 'fallback_failed',
			];
		}
	}
}

interface AutoLabelProviderInterface {
	/**
	 * @param array<string,mixed> $profile
	 * @return array{id:string,url:string,payload:array<string,mixed>,headers:array<string,string>,timeout_seconds:int}
	 */
	public function buildTextRequest(array $profile, string $systemPrompt, string $prompt): array;

	/**
	 * @param array{status:int,body:string,json:mixed} $response
	 */
	public function parseTextResponse(array $response): string;

	/**
	 * @param array<string,mixed> $profile
	 * @return array{id:string,url:string,payload:array<string,mixed>,headers:array<string,string>,timeout_seconds:int}
	 */
	public function buildSingleEmbeddingRequest(array $profile, string $text, ?string $instruction = null): array;

	/**
	 * @param array{status:int,body:string,json:mixed} $response
	 * @return list<float>
	 */
	public function parseSingleEmbeddingResponse(array $response): array;

	/**
	 * @param array<string,mixed> $profile
	 * @return array{match:bool,reason:string,confidence:float|null,raw:string}
	 */
	public function classify(array $profile, string $prompt): array;

	/**
	 * @param array<string,mixed> $profile
	 * @param list<string> $texts
	 * @return list<list<float>>
	 */
	public function embedTexts(array $profile, array $texts, ?string $instruction = null): array;
}

abstract class AutoLabelAbstractProvider implements AutoLabelProviderInterface {
	protected const CLASSIFIER_SYSTEM_PROMPT = 'You are a strict binary classifier. Return JSON only: {"match":true|false,"confidence":0..1,"reason":"short explanation"}.';

	/** @var AutoLabelHttpClient */
	protected $http;

	public function __construct(AutoLabelHttpClient $http) {
		$this->http = $http;
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function baseUrl(array $profile, string $default): string {
		$base = trim((string)($profile['base_url'] ?? ''));
		return $base !== '' ? rtrim($base, '/') : rtrim($default, '/');
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function timeout(array $profile): int {
		return max(3, min(120, (int)($profile['timeout_seconds'] ?? AutoLabelSystemProfileRepository::DEFAULT_TIMEOUT_SECONDS)));
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function embeddingTimeout(array $profile): int {
		$timeout = $this->timeout($profile);
		return max($timeout, 60);
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function apiKey(array $profile): string {
		return trim((string)($profile['api_key'] ?? ''));
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function embeddingDimensions(array $profile): int {
		return max(0, (int)($profile['embedding_dimensions'] ?? 0));
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function embeddingNumCtx(array $profile): int {
		return max(0, (int)($profile['embedding_num_ctx'] ?? 0));
	}

	protected function appendPath(string $baseUrl, string $path): string {
		$baseUrl = rtrim($baseUrl, '/');
		if (substr($baseUrl, -3) === '/v1' && substr($path, 0, 4) === '/v1/') {
			return $baseUrl . substr($path, 3);
		}
		return $baseUrl . $path;
	}

	/**
	 * @return array{match:bool,reason:string,confidence:float|null,raw:string}
	 */
	protected function parseDecision(string $text): array {
		$raw = trim($text);
		$decoded = json_decode($raw, true);
		if (!is_array($decoded) && preg_match('/\{.*\}/s', $raw, $matches) === 1) {
			$decoded = json_decode($matches[0], true);
		}
		if (!is_array($decoded)) {
			return [
				'match' => false,
				'reason' => 'The model did not return valid JSON.',
				'confidence' => null,
				'raw' => $raw,
			];
		}

		$confidence = null;
		if (isset($decoded['confidence']) && is_numeric($decoded['confidence'])) {
			$confidence = max(0.0, min(1.0, (float)$decoded['confidence']));
		}

		return [
			'match' => (bool)($decoded['match'] ?? false),
			'reason' => trim((string)($decoded['reason'] ?? '')),
			'confidence' => $confidence,
			'raw' => $raw,
		];
	}

	/**
	 * @param list<string> $texts
	 * @return list<string>
	 */
	protected function applyInstructionPrefix(array $profile, array $texts, ?string $instruction): array {
		$instruction = trim((string)$instruction);
		if ($instruction === '') {
			$instruction = trim((string)($profile['default_instruction'] ?? ''));
		}
		if ($instruction === '') {
			return $texts;
		}

		return array_map(
			static fn (string $text): string => "Instruction: {$instruction}\n\nText:\n{$text}",
			$texts
		);
	}

	/**
	 * @param array<int,mixed> $responseOutput
	 */
	protected function extractOpenAIOutputText(array $responseOutput): string {
		$texts = [];
		foreach ($responseOutput as $item) {
			if (!is_array($item) || ($item['type'] ?? '') !== 'message') {
				continue;
			}
			$content = $item['content'] ?? [];
			if (!is_array($content)) {
				continue;
			}
			foreach ($content as $part) {
				if (is_array($part) && is_string($part['text'] ?? null)) {
					$texts[] = $part['text'];
				}
			}
		}

		return trim(implode("\n", $texts));
	}

	public function classify(array $profile, string $prompt): array {
		$request = $this->buildTextRequest($profile, self::CLASSIFIER_SYSTEM_PROMPT, $prompt);
		$response = $this->http->postJson(
			(string)$request['url'],
			$request['payload'],
			$request['headers'],
			(int)$request['timeout_seconds']
		);

		return $this->parseDecision($this->parseTextResponse($response));
	}

	public function embedTexts(array $profile, array $texts, ?string $instruction = null): array {
		$embeddings = [];
		foreach ($texts as $text) {
			$request = $this->buildSingleEmbeddingRequest($profile, (string)$text, $instruction);
			$response = $this->http->postJson(
				(string)$request['url'],
				$request['payload'],
				$request['headers'],
				(int)$request['timeout_seconds']
			);
			$embeddings[] = $this->parseSingleEmbeddingResponse($response);
		}

		return $embeddings;
	}
}

final class AutoLabelOpenAIProvider extends AutoLabelAbstractProvider {
	public function buildTextRequest(array $profile, string $systemPrompt, string $prompt): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('OpenAI profile requires an API key.');
		}

		return [
			'id' => '',
			'url' => $this->appendPath($this->baseUrl($profile, 'https://api.openai.com'), '/v1/responses'),
			'payload' => [
				'model' => (string)$profile['model'],
				'instructions' => $systemPrompt,
				'input' => [[
					'role' => 'user',
					'content' => [[
						'type' => 'input_text',
						'text' => $prompt,
					]],
				]],
				'max_output_tokens' => 300,
			],
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
			],
			'timeout_seconds' => $this->timeout($profile),
		];
	}

	public function parseTextResponse(array $response): string {
		$json = is_array($response['json']) ? $response['json'] : [];
		return is_string($json['output_text'] ?? null)
			? $json['output_text']
			: $this->extractOpenAIOutputText(is_array($json['output'] ?? null) ? $json['output'] : []);
	}

	public function buildSingleEmbeddingRequest(array $profile, string $text, ?string $instruction = null): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('OpenAI profile requires an API key.');
		}

		$preparedTexts = $this->applyInstructionPrefix($profile, [$text], $instruction);
		$payload = [
			'model' => (string)$profile['model'],
			'input' => $preparedTexts,
		];
		$dimensions = $this->embeddingDimensions($profile);
		if ($dimensions > 0) {
			$payload['dimensions'] = $dimensions;
		}

		return [
			'id' => '',
			'url' => $this->appendPath($this->baseUrl($profile, 'https://api.openai.com'), '/v1/embeddings'),
			'payload' => $payload,
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
			],
			'timeout_seconds' => $this->embeddingTimeout($profile),
		];
	}

	public function parseSingleEmbeddingResponse(array $response): array {
		$json = is_array($response['json']) ? $response['json'] : [];
		$data = is_array($json['data'] ?? null) ? $json['data'] : [];
		if (!isset($data[0]['embedding']) || !is_array($data[0]['embedding'])) {
			throw new RuntimeException('OpenAI embedding response was missing values.');
		}

		return array_values(array_map(static fn ($value): float => (float)$value, $data[0]['embedding']));
	}

	public function embedTexts(array $profile, array $texts, ?string $instruction = null): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('OpenAI profile requires an API key.');
		}

		$preparedTexts = $this->applyInstructionPrefix($profile, $texts, $instruction);
		$payload = [
			'model' => (string)$profile['model'],
			'input' => $preparedTexts,
		];
		$dimensions = $this->embeddingDimensions($profile);
		if ($dimensions > 0) {
			$payload['dimensions'] = $dimensions;
		}
		$response = $this->http->postJson(
			$this->appendPath($this->baseUrl($profile, 'https://api.openai.com'), '/v1/embeddings'),
			$payload,
			['Authorization' => 'Bearer ' . $apiKey],
			$this->embeddingTimeout($profile)
		);

		$json = is_array($response['json']) ? $response['json'] : [];
		$data = is_array($json['data'] ?? null) ? $json['data'] : [];
		$embeddings = [];
		foreach ($data as $item) {
			if (is_array($item['embedding'] ?? null)) {
				$embeddings[] = array_values(array_map(static fn ($value): float => (float)$value, $item['embedding']));
			}
		}

		return $embeddings;
	}
}

final class AutoLabelAnthropicProvider extends AutoLabelAbstractProvider {
	public function buildTextRequest(array $profile, string $systemPrompt, string $prompt): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('Anthropic profile requires an API key.');
		}

		return [
			'id' => '',
			'url' => $this->appendPath($this->baseUrl($profile, 'https://api.anthropic.com'), '/v1/messages'),
			'payload' => [
				'model' => (string)$profile['model'],
				'max_tokens' => 300,
				'system' => $systemPrompt,
				'messages' => [[
					'role' => 'user',
					'content' => $prompt,
				]],
			],
			'headers' => [
				'x-api-key' => $apiKey,
				'anthropic-version' => '2023-06-01',
			],
			'timeout_seconds' => $this->timeout($profile),
		];
	}

	public function parseTextResponse(array $response): string {
		$json = is_array($response['json']) ? $response['json'] : [];
		$content = is_array($json['content'] ?? null) ? $json['content'] : [];
		$text = '';
		foreach ($content as $block) {
			if (is_array($block) && is_string($block['text'] ?? null)) {
				$text .= $block['text'];
			}
		}
		return $text;
	}

	public function buildSingleEmbeddingRequest(array $profile, string $text, ?string $instruction = null): array {
		throw new RuntimeException('This provider does not support embeddings.');
	}

	public function parseSingleEmbeddingResponse(array $response): array {
		throw new RuntimeException('This provider does not support embeddings.');
	}
}

final class AutoLabelGeminiProvider extends AutoLabelAbstractProvider {
	public function buildTextRequest(array $profile, string $systemPrompt, string $prompt): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('Gemini profile requires an API key.');
		}

		$model = rawurlencode((string)$profile['model']);
		return [
			'id' => '',
			'url' => $this->baseUrl($profile, 'https://generativelanguage.googleapis.com') . "/v1beta/models/{$model}:generateContent?key=" . rawurlencode($apiKey),
			'payload' => [
				'systemInstruction' => [
					'parts' => [[
						'text' => $systemPrompt,
					]],
				],
				'contents' => [[
					'role' => 'user',
					'parts' => [[
						'text' => $prompt,
					]],
				]],
				'generationConfig' => [
					'responseMimeType' => 'application/json',
				],
			],
			'headers' => [],
			'timeout_seconds' => $this->timeout($profile),
		];
	}

	public function parseTextResponse(array $response): string {
		$json = is_array($response['json']) ? $response['json'] : [];
		return (string)($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
	}

	public function buildSingleEmbeddingRequest(array $profile, string $text, ?string $instruction = null): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('Gemini profile requires an API key.');
		}

		$model = rawurlencode((string)$profile['model']);
		$preparedTexts = $this->applyInstructionPrefix($profile, [$text], $instruction);
		$payload = [
			'content' => [
				'parts' => [[
					'text' => $preparedTexts[0],
				]],
			],
			'taskType' => 'SEMANTIC_SIMILARITY',
		];
		$dimensions = $this->embeddingDimensions($profile);
		if ($dimensions > 0) {
			$payload['outputDimensionality'] = $dimensions;
		}

		return [
			'id' => '',
			'url' => $this->baseUrl($profile, 'https://generativelanguage.googleapis.com') . "/v1beta/models/{$model}:embedContent?key=" . rawurlencode($apiKey),
			'payload' => $payload,
			'headers' => [],
			'timeout_seconds' => $this->embeddingTimeout($profile),
		];
	}

	public function parseSingleEmbeddingResponse(array $response): array {
		$json = is_array($response['json']) ? $response['json'] : [];
		$values = $json['embedding']['values'] ?? null;
		if (!is_array($values)) {
			throw new RuntimeException('Gemini embedding response was missing values.');
		}
		return array_values(array_map(static fn ($value): float => (float)$value, $values));
	}

	public function embedTexts(array $profile, array $texts, ?string $instruction = null): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('Gemini profile requires an API key.');
		}

		$model = rawurlencode((string)$profile['model']);
		$baseUrl = $this->baseUrl($profile, 'https://generativelanguage.googleapis.com');
		$preparedTexts = $this->applyInstructionPrefix($profile, $texts, $instruction);
		$embeddings = [];
		$dimensions = $this->embeddingDimensions($profile);
		if (count($preparedTexts) > 1) {
			$requests = [];
			foreach ($preparedTexts as $text) {
				$request = [
					'model' => 'models/' . (string)$profile['model'],
					'content' => [
						'parts' => [[
							'text' => $text,
						]],
					],
					'taskType' => 'SEMANTIC_SIMILARITY',
				];
				if ($dimensions > 0) {
					$request['outputDimensionality'] = $dimensions;
				}
				$requests[] = $request;
			}

			$response = $this->http->postJson(
				$baseUrl . "/v1beta/models/{$model}:batchEmbedContents?key=" . rawurlencode($apiKey),
				['requests' => $requests],
				[],
				$this->embeddingTimeout($profile)
			);

			$json = is_array($response['json']) ? $response['json'] : [];
			$batchEmbeddings = is_array($json['embeddings'] ?? null) ? $json['embeddings'] : [];
			foreach ($batchEmbeddings as $item) {
				$values = $item['values'] ?? null;
				if (!is_array($values)) {
					throw new RuntimeException('Gemini batch embedding response was missing values.');
				}
				$embeddings[] = array_values(array_map(static fn ($value): float => (float)$value, $values));
			}
			return $embeddings;
		}

		return parent::embedTexts($profile, $texts, $instruction);
	}
}

final class AutoLabelOllamaProvider extends AutoLabelAbstractProvider {
	public function buildTextRequest(array $profile, string $systemPrompt, string $prompt): array {
		$headers = [];
		if ($this->apiKey($profile) !== '') {
			$headers['Authorization'] = 'Bearer ' . $this->apiKey($profile);
		}

		return [
			'id' => '',
			'url' => $this->appendPath($this->baseUrl($profile, 'http://127.0.0.1:11434'), '/api/chat'),
			'payload' => [
				'model' => (string)$profile['model'],
				'messages' => [
					[
						'role' => 'system',
						'content' => $systemPrompt,
					],
					[
						'role' => 'user',
						'content' => $prompt,
					],
				],
				'stream' => false,
				'format' => 'json',
			],
			'headers' => $headers,
			'timeout_seconds' => $this->timeout($profile),
		];
	}

	public function parseTextResponse(array $response): string {
		$json = is_array($response['json']) ? $response['json'] : [];
		return (string)($json['message']['content'] ?? '');
	}

	public function buildSingleEmbeddingRequest(array $profile, string $text, ?string $instruction = null): array {
		$headers = [];
		if ($this->apiKey($profile) !== '') {
			$headers['Authorization'] = 'Bearer ' . $this->apiKey($profile);
		}

		$preparedTexts = $this->applyInstructionPrefix($profile, [$text], $instruction);
		$payload = [
			'model' => (string)$profile['model'],
			'input' => array_values($preparedTexts),
			'truncate' => true,
		];
		$dimensions = $this->embeddingDimensions($profile);
		if ($dimensions > 0) {
			$payload['dimensions'] = $dimensions;
		}
		$numCtx = $this->embeddingNumCtx($profile);
		if ($numCtx > 0) {
			$payload['options'] = ['num_ctx' => $numCtx];
		}

		return [
			'id' => '',
			'url' => $this->appendPath($this->baseUrl($profile, 'http://127.0.0.1:11434'), '/api/embed'),
			'payload' => $payload,
			'headers' => $headers,
			'timeout_seconds' => $this->embeddingTimeout($profile),
		];
	}

	public function parseSingleEmbeddingResponse(array $response): array {
		$json = is_array($response['json']) ? $response['json'] : [];
		if (is_array($json['embedding'] ?? null)) {
			return array_values(array_map(static fn ($value): float => (float)$value, $json['embedding']));
		}
		if (is_array($json['data']['embedding'] ?? null)) {
			return array_values(array_map(static fn ($value): float => (float)$value, $json['data']['embedding']));
		}
		if (is_array($json['data'][0]['embedding'] ?? null)) {
			return array_values(array_map(static fn ($value): float => (float)$value, $json['data'][0]['embedding']));
		}
		if (is_array($json['embeddings'] ?? null)) {
			$embeddings = $json['embeddings'];
			if (isset($embeddings[0]) && is_array($embeddings[0])) {
				if (is_array($embeddings[0]['embedding'] ?? null)) {
					return array_values(array_map(static fn ($value): float => (float)$value, $embeddings[0]['embedding']));
				}
				return array_values(array_map(static fn ($value): float => (float)$value, $embeddings[0]));
			}
			if (isset($embeddings[0]) && is_numeric($embeddings[0])) {
				return array_values(array_map(static fn ($value): float => (float)$value, $embeddings));
			}
		}
		$bodySnippet = trim((string)($response['body'] ?? ''));
		if (mb_strlen($bodySnippet, 'UTF-8') > 400) {
			$bodySnippet = mb_substr($bodySnippet, 0, 400, 'UTF-8') . '…';
		}
		throw new RuntimeException('Ollama embedding response was missing values. Response: ' . ($bodySnippet !== '' ? $bodySnippet : '[empty body]'));
	}

	public function embedTexts(array $profile, array $texts, ?string $instruction = null): array {
		$request = $this->buildSingleEmbeddingRequest($profile, '', $instruction);
		$preparedTexts = $this->applyInstructionPrefix($profile, $texts, $instruction);
		$request['payload']['input'] = array_values($preparedTexts);
		$response = $this->http->postJson(
			(string)$request['url'],
			$request['payload'],
			$request['headers'],
			(int)$request['timeout_seconds']
		);

		$json = is_array($response['json']) ? $response['json'] : [];
		$embeddings = [];
		if (is_array($json['embeddings'] ?? null)) {
			if (isset($json['embeddings'][0]) && is_numeric($json['embeddings'][0])) {
				$embeddings = [$json['embeddings']];
			} else {
				$embeddings = $json['embeddings'];
			}
		} elseif (is_array($json['embedding'] ?? null)) {
			$embeddings = [$json['embedding']];
		}
		$result = [];
		foreach ($embeddings as $embedding) {
			if (is_array($embedding)) {
				$result[] = array_values(array_map(static fn ($value): float => (float)$value, $embedding));
			}
		}
		return $result;
	}
}

final class AutoLabelProviderFactory {
	/** @var AutoLabelHttpClient */
	private $http;

	public function __construct(AutoLabelHttpClient $http) {
		$this->http = $http;
	}

	public function create(string $provider): AutoLabelProviderInterface {
		switch ($provider) {
			case 'openai':
				return new AutoLabelOpenAIProvider($this->http);
			case 'anthropic':
				return new AutoLabelAnthropicProvider($this->http);
			case 'gemini':
				return new AutoLabelGeminiProvider($this->http);
			case 'ollama':
				return new AutoLabelOllamaProvider($this->http);
			default:
				throw new RuntimeException('Unsupported provider: ' . $provider);
		}
	}
}

final class AutoLabelEngine {
	/** @var array<string,list<float>> */
	private array $entryEmbeddingMemo = [];
	private ?int $timeoutCapSeconds = null;
	/** @var AutoLabelHttpClient */
	private $http;
	/** @var AutoLabelSystemProfileRepository */
	private $profiles;
	/** @var AutoLabelUserRuleRepository */
	private $rules;
	/** @var AutoLabelProfileCapabilityResolver */
	private $capabilities;
	/** @var AutoLabelEntryTextExtractor */
	private $extractor;
	/** @var AutoLabelEmbeddingCacheStore */
	private $cache;
	/** @var AutoLabelDiagnosticsStore */
	private $diagnostics;
	/** @var AutoLabelProviderFactory */
	private $providers;

	public function __construct(
		AutoLabelHttpClient $http,
		AutoLabelSystemProfileRepository $profiles,
		AutoLabelUserRuleRepository $rules,
		AutoLabelProfileCapabilityResolver $capabilities,
		AutoLabelEntryTextExtractor $extractor,
		AutoLabelEmbeddingCacheStore $cache,
		AutoLabelDiagnosticsStore $diagnostics,
		AutoLabelProviderFactory $providers
	) {
		$this->http = $http;
		$this->profiles = $profiles;
		$this->rules = $rules;
		$this->capabilities = $capabilities;
		$this->extractor = $extractor;
		$this->cache = $cache;
		$this->diagnostics = $diagnostics;
		$this->providers = $providers;
	}

	public function setTimeoutCap(?int $seconds): void {
		$this->timeoutCapSeconds = $seconds !== null ? max(1, $seconds) : null;
	}

	public function supportsConcurrentWindow(): bool {
		return $this->http->supportsConcurrent();
	}

	/**
	 * @param array<string,mixed> $profile
	 * @param list<array{task_id:string,entry:FreshRSS_Entry,rules:list<array<string,mixed>>}> $tasks
	 * @return array<string,array{tags:list<string>,results:list<array<string,mixed>>,context:array<string,string>,failed_rule_ids:list<string>,transport:string}>
	 */
	public function runProfileBatch(array $profile, array $tasks): array {
		if (!$this->supportsConcurrentWindow()) {
			throw new RuntimeException('Concurrent batch execution requires the PHP curl extension.');
		}
		if (count($tasks) === 0) {
			return [];
		}

		$effectiveProfile = $this->effectiveProfile($profile);
		$contextsByTask = [];
		foreach ($tasks as $task) {
			$maxChars = (int)($effectiveProfile['content_max_chars'] ?? AutoLabelSystemProfileRepository::DEFAULT_CONTENT_MAX_CHARS);
			$contextsByTask[$task['task_id']] = $this->extractor->extractContext($task['entry'], $maxChars);
		}

		return ($effectiveProfile['profile_mode'] ?? 'llm') === 'embedding'
			? $this->runEmbeddingProfileBatch($effectiveProfile, $tasks, $contextsByTask)
			: $this->runLlmProfileBatch($effectiveProfile, $tasks, $contextsByTask);
	}

	/**
	 * @param array<string,mixed> $profile
	 * @param list<array{task_id:string,entry:FreshRSS_Entry,rules:list<array<string,mixed>>}> $tasks
	 * @param array<string,array<string,string>> $contextsByTask
	 * @return array<string,array{tags:list<string>,results:list<array<string,mixed>>,context:array<string,string>,failed_rule_ids:list<string>,transport:string}>
	 */
	private function runLlmProfileBatch(array $profile, array $tasks, array $contextsByTask): array {
		$provider = $this->providers->create((string)$profile['provider']);
		$requests = [];
		foreach ($tasks as $task) {
			$taskId = (string)$task['task_id'];
			$request = $provider->buildTextRequest(
				$profile,
				'You are a strict multi-rule classifier. Return JSON only in the form {"results":[{"rule_id":"...","match":true|false,"confidence":0..1,"reason":"short explanation"}]}. Include every rule exactly once.',
				$this->buildCombinedLlmPrompt($task['rules'], $contextsByTask[$taskId])
			);
			$request['id'] = $taskId;
			$requests[] = $request;
		}

		$responses = $this->http->postJsonConcurrent($requests);
		$resultsByTask = [];
		foreach ($tasks as $task) {
			$taskId = (string)$task['task_id'];
			$context = $this->diagnosticContext($contextsByTask[$taskId]);
			$aggregate = [
				'tags' => [],
				'results' => [],
				'context' => $context,
				'failed_rule_ids' => [],
				'transport' => 'concurrent',
			];
			$responseInfo = $responses[$taskId] ?? ['ok' => false, 'error' => 'No response was returned for this task.'];
			$aggregate['transport'] = (string)($responseInfo['transport'] ?? 'concurrent');
			if (!($responseInfo['ok'] ?? false)) {
				foreach ($task['rules'] as $rule) {
					$aggregate['results'][] = [
						'rule_id' => $rule['id'],
						'rule_name' => $rule['name'],
						'target_tags' => $rule['target_tags'],
						'mode' => 'llm',
						'matched' => false,
						'status' => 'error',
						'reason' => (string)($responseInfo['error'] ?? 'Request failed.'),
					];
					$aggregate['failed_rule_ids'][] = (string)$rule['id'];
				}
				$resultsByTask[$taskId] = $aggregate;
				continue;
			}

			$decisionMap = $this->parseCombinedLlmDecisions($provider->parseTextResponse([
				'status' => (int)($responseInfo['status'] ?? 200),
				'body' => (string)($responseInfo['body'] ?? ''),
				'json' => $responseInfo['json'] ?? null,
			]));
			foreach ($task['rules'] as $rule) {
				$ruleId = (string)$rule['id'];
				$decision = $decisionMap[$ruleId] ?? null;
				if (!is_array($decision)) {
					$aggregate['results'][] = [
						'rule_id' => $ruleId,
						'rule_name' => $rule['name'],
						'target_tags' => $rule['target_tags'],
						'mode' => 'llm',
						'matched' => false,
						'status' => 'error',
						'reason' => 'The model did not return a decision for this rule.',
					];
					$aggregate['failed_rule_ids'][] = $ruleId;
					continue;
				}

				$matched = !empty($decision['match']);
				$aggregate['results'][] = [
					'rule_id' => $ruleId,
					'rule_name' => $rule['name'],
					'target_tags' => $rule['target_tags'],
					'mode' => 'llm',
					'matched' => $matched,
					'status' => 'ok',
					'reason' => trim((string)($decision['reason'] ?? '')),
					'confidence' => isset($decision['confidence']) && is_numeric($decision['confidence'])
						? max(0.0, min(1.0, (float)$decision['confidence']))
						: null,
				];
				if ($matched) {
					foreach ($rule['target_tags'] as $targetTag) {
						$aggregate['tags'][] = (string)$targetTag;
					}
				}
			}

			$aggregate['tags'] = array_values(array_unique($aggregate['tags']));
			$aggregate['failed_rule_ids'] = array_values(array_unique($aggregate['failed_rule_ids']));
			$resultsByTask[$taskId] = $aggregate;
		}

		return $resultsByTask;
	}

	/**
	 * @param array<string,mixed> $profile
	 * @param list<array{task_id:string,entry:FreshRSS_Entry,rules:list<array<string,mixed>>}> $tasks
	 * @param array<string,array<string,string>> $contextsByTask
	 * @return array<string,array{tags:list<string>,results:list<array<string,mixed>>,context:array<string,string>,failed_rule_ids:list<string>,transport:string}>
	 */
	private function runEmbeddingProfileBatch(array $profile, array $tasks, array $contextsByTask): array {
		$provider = $this->providers->create((string)$profile['provider']);
		$aggregates = [];
		$taskRulesByInstruction = [];
		$anchorsByInstruction = [];

		foreach ($tasks as $task) {
			$taskId = (string)$task['task_id'];
			$aggregates[$taskId] = [
				'tags' => [],
				'results' => [],
				'context' => $this->diagnosticContext($contextsByTask[$taskId]),
				'failed_rule_ids' => [],
				'transport' => 'concurrent',
			];

			foreach ($task['rules'] as $rule) {
				$instruction = $this->effectiveInstruction($profile, $rule);
				if (!isset($taskRulesByInstruction[$instruction])) {
					$taskRulesByInstruction[$instruction] = [];
				}
				if (!isset($taskRulesByInstruction[$instruction][$taskId])) {
					$taskRulesByInstruction[$instruction][$taskId] = [];
				}
				$taskRulesByInstruction[$instruction][$taskId][] = $rule;
				foreach ($rule['embedding_anchor_texts'] as $anchorText) {
					$anchorsByInstruction[$instruction][(string)$anchorText] = (string)$anchorText;
				}
			}
		}

		$anchorVectorsByInstruction = [];
		foreach ($anchorsByInstruction as $instruction => $anchors) {
			$anchorVectorsByInstruction[$instruction] = [];
			$uncached = [];
			foreach ($anchors as $anchorText) {
				$cacheKey = $this->anchorCacheKey($profile, (string)$instruction, (string)$anchorText);
				$cachedVector = $this->cache->get($cacheKey);
				if ($cachedVector !== null) {
					$anchorVectorsByInstruction[$instruction][$anchorText] = $cachedVector;
				} else {
					$uncached[] = (string)$anchorText;
				}
			}

			if (count($uncached) > 0) {
				$uncachedVectors = $provider->embedTexts($profile, $uncached, (string)$instruction);
				foreach ($uncached as $index => $anchorText) {
					if (!isset($uncachedVectors[$index])) {
						throw new RuntimeException('Some anchor embeddings were not returned.');
					}
					$cacheKey = $this->anchorCacheKey($profile, (string)$instruction, (string)$anchorText);
					$this->cache->set($cacheKey, $uncachedVectors[$index]);
					$anchorVectorsByInstruction[$instruction][$anchorText] = $uncachedVectors[$index];
				}
			}
		}

		foreach ($taskRulesByInstruction as $instruction => $rulesByTask) {
			$requests = [];
			foreach ($rulesByTask as $taskId => $groupRules) {
				$request = $provider->buildSingleEmbeddingRequest(
					$profile,
					(string)($contextsByTask[$taskId]['embedding_text'] ?? $contextsByTask[$taskId]['text'] ?? ''),
					(string)$instruction
				);
				$request['id'] = (string)$taskId;
				$requests[] = $request;
			}

			$responses = $this->http->postJsonConcurrent($requests);
			foreach ($rulesByTask as $taskId => $groupRules) {
				$responseInfo = $responses[$taskId] ?? ['ok' => false, 'error' => 'No response was returned for this task.'];
				$aggregates[$taskId]['transport'] = (string)($responseInfo['transport'] ?? 'concurrent');
				if (!($responseInfo['ok'] ?? false)) {
					foreach ($groupRules as $rule) {
						$aggregates[$taskId]['results'][] = [
							'rule_id' => $rule['id'],
							'rule_name' => $rule['name'],
							'target_tags' => $rule['target_tags'],
							'mode' => 'embedding',
							'matched' => false,
							'status' => 'error',
							'reason' => (string)($responseInfo['error'] ?? 'Request failed.'),
						];
						$aggregates[$taskId]['failed_rule_ids'][] = (string)$rule['id'];
					}
					continue;
				}

				try {
					$entryVector = $provider->parseSingleEmbeddingResponse([
						'status' => (int)($responseInfo['status'] ?? 200),
						'body' => (string)($responseInfo['body'] ?? ''),
						'json' => $responseInfo['json'] ?? null,
					]);
				} catch (Throwable $throwable) {
					foreach ($groupRules as $rule) {
						$aggregates[$taskId]['results'][] = [
							'rule_id' => $rule['id'],
							'rule_name' => $rule['name'],
							'target_tags' => $rule['target_tags'],
							'mode' => 'embedding',
							'matched' => false,
							'status' => 'error',
							'reason' => $throwable->getMessage(),
						];
						$aggregates[$taskId]['failed_rule_ids'][] = (string)$rule['id'];
					}
					continue;
				}

				foreach ($groupRules as $rule) {
					$threshold = (float)$rule['embedding_threshold'];
					$bestSimilarity = -1.0;
					$bestAnchor = '';
					foreach ($rule['embedding_anchor_texts'] as $anchorText) {
						$anchorVector = $anchorVectorsByInstruction[$instruction][(string)$anchorText] ?? null;
						if (!is_array($anchorVector)) {
							continue;
						}
						$similarity = $this->cosineSimilarity($entryVector, $anchorVector);
						if ($similarity > $bestSimilarity) {
							$bestSimilarity = $similarity;
							$bestAnchor = (string)$anchorText;
						}
					}

					$matched = $bestSimilarity >= $threshold;
					$aggregates[$taskId]['results'][] = [
						'rule_id' => $rule['id'],
						'rule_name' => $rule['name'],
						'target_tags' => $rule['target_tags'],
						'mode' => 'embedding',
						'matched' => $matched,
						'status' => 'ok',
						'reason' => $bestAnchor === '' ? '' : 'Best anchor: ' . $bestAnchor,
						'confidence' => $bestSimilarity < 0 ? null : round($bestSimilarity, 4),
						'threshold' => $threshold,
					];
					if ($matched) {
						foreach ($rule['target_tags'] as $targetTag) {
							$aggregates[$taskId]['tags'][] = (string)$targetTag;
						}
					}
				}
			}
		}

		foreach ($aggregates as $taskId => $aggregate) {
			$aggregates[$taskId]['tags'] = array_values(array_unique($aggregate['tags']));
			$aggregates[$taskId]['failed_rule_ids'] = array_values(array_unique($aggregate['failed_rule_ids']));
		}

		return $aggregates;
	}

	/**
	 * @param list<array<string,mixed>>|null $rules
	 * @return array{tags:list<string>,results:list<array<string,mixed>>,context:array<string,string>}
	 */
	public function runRules(FreshRSS_Entry $entry, ?array $rules = null, bool $logDiagnostics = true): array {
		$rules = $rules ?? $this->rules->enabled();
		$results = [];
		$tags = is_array($entry->tags(false)) ? $entry->tags(false) : [];
		$profilesById = [];
		foreach ($this->profiles->all() as $profile) {
			$profilesById[$profile['id']] = $profile;
		}

		$contextsByMaxChars = [];
		foreach ($rules as $rule) {
			if (!($rule['enabled'] ?? false)) {
				continue;
			}

			$profile = $profilesById[$rule['profile_id']] ?? null;
			if ($profile === null) {
				$results[] = $this->skippedResult($rule, 'missing_profile');
				continue;
			}
			if (!($profile['enabled'] ?? false)) {
				$results[] = $this->skippedResult($rule, 'profile_disabled');
				continue;
			}
			if (!$this->capabilities->supportsMode($profile, (string)$rule['mode'])) {
				$results[] = $this->skippedResult($rule, 'unsupported_mode');
				continue;
			}

			$maxChars = (int)$profile['content_max_chars'];
			if (!isset($contextsByMaxChars[$maxChars])) {
				$contextsByMaxChars[$maxChars] = $this->extractor->extractContext($entry, $maxChars);
			}
			$context = $contextsByMaxChars[$maxChars];
			$effectiveProfile = $this->effectiveProfile($profile);

			try {
				$result = $rule['mode'] === 'embedding'
					? $this->runEmbeddingRule($effectiveProfile, $rule, $context)
					: $this->runLlmRule($effectiveProfile, $rule, $context);
			} catch (Throwable $throwable) {
				$result = [
					'rule_id' => $rule['id'],
					'rule_name' => $rule['name'],
					'target_tags' => $rule['target_tags'],
					'mode' => $rule['mode'],
					'matched' => false,
					'status' => 'error',
					'reason' => $throwable->getMessage(),
				];
			}

			if (!empty($result['matched'])) {
				foreach ($rule['target_tags'] as $targetTag) {
					$tags[] = (string)$targetTag;
				}
			}
			$results[] = $result;
		}

		$tags = array_values(array_unique(array_filter(array_map(
			static fn ($tag): string => ltrim(trim((string)$tag), '#'),
			$tags
		))));

		if ($logDiagnostics && count($results) > 0) {
			$this->diagnostics->append([
				'type' => 'entry_classification',
				'title' => $entry->title(),
				'results' => $results,
				'tags' => $tags,
			]);
		}

		return [
			'tags' => $tags,
			'results' => $results,
			'context' => $this->diagnosticContext(
				$contextsByMaxChars[AutoLabelSystemProfileRepository::DEFAULT_CONTENT_MAX_CHARS]
					?? $this->extractor->extractContext($entry, AutoLabelSystemProfileRepository::DEFAULT_CONTENT_MAX_CHARS)
			),
		];
	}

	/**
	 * @param array<string,mixed> $profile
	 * @return array<string,mixed>
	 */
	private function effectiveProfile(array $profile): array {
		if ($this->timeoutCapSeconds === null) {
			return $profile;
		}

		$currentTimeout = max(1, (int)($profile['timeout_seconds'] ?? AutoLabelSystemProfileRepository::DEFAULT_TIMEOUT_SECONDS));
		$profile['timeout_seconds'] = min($currentTimeout, $this->timeoutCapSeconds);
		return $profile;
	}

	/**
	 * @param array<string,string> $context
	 * @return array<string,string>
	 */
	private function diagnosticContext(array $context): array {
		$embeddingText = (string)($context['embedding_text'] ?? $context['text'] ?? '');
		$llmText = (string)($context['text'] ?? $embeddingText);
		$context['llm_text'] = $llmText;
		$context['embedding_text'] = $embeddingText;
		$context['text'] = $embeddingText;
		return $context;
	}

	/**
	 * @param array<string,mixed> $rule
	 */
	private function effectiveInstruction(array $profile, array $rule): string {
		$instruction = trim((string)($rule['embedding_instruction'] ?? ''));
		if ($instruction === '') {
			$instruction = trim((string)($profile['default_instruction'] ?? ''));
		}
		return $instruction;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @param array<string,string> $context
	 */
	private function buildCombinedLlmPrompt(array $rules, array $context): string {
		$parts = [];
		foreach ($rules as $rule) {
			$parts[] = "Rule ID: {$rule['id']}\nRule name: {$rule['name']}\nTarget tags: " . implode(', ', $rule['target_tags']) . "\nPrompt:\n" . $this->buildLlmPrompt($rule, $context);
		}

		return "Evaluate each rule independently for the same article. Return JSON only in this exact format:\n"
			. "{\"results\":[{\"rule_id\":\"rule-id\",\"match\":true,\"confidence\":0.0,\"reason\":\"short explanation\"}]}\n"
			. "Include every rule exactly once.\n\n"
			. implode("\n\n---\n\n", $parts);
	}

	/**
	 * @return array<string,array{match:bool,confidence:float|null,reason:string}>
	 */
	private function parseCombinedLlmDecisions(string $text): array {
		$raw = trim($text);
		$decoded = json_decode($raw, true);
		if (!is_array($decoded) && preg_match('/\{.*\}/s', $raw, $matches) === 1) {
			$decoded = json_decode($matches[0], true);
		}
		if (!is_array($decoded)) {
			return [];
		}

		$items = is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
		$decisions = [];
		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}
			$ruleId = trim((string)($item['rule_id'] ?? ''));
			if ($ruleId === '') {
				continue;
			}
			$confidence = null;
			if (isset($item['confidence']) && is_numeric($item['confidence'])) {
				$confidence = max(0.0, min(1.0, (float)$item['confidence']));
			}
			$decisions[$ruleId] = [
				'match' => (bool)($item['match'] ?? false),
				'confidence' => $confidence,
				'reason' => trim((string)($item['reason'] ?? '')),
			];
		}

		return $decisions;
	}

	/**
	 * @param array<string,mixed> $profile
	 * @param array<string,mixed> $rule
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	private function runLlmRule(array $profile, array $rule, array $context): array {
		$provider = $this->providers->create((string)$profile['provider']);
		$prompt = $this->buildLlmPrompt($rule, $context);
		$decision = $provider->classify($profile, $prompt);

		return [
			'rule_id' => $rule['id'],
			'rule_name' => $rule['name'],
			'target_tags' => $rule['target_tags'],
			'mode' => 'llm',
			'matched' => $decision['match'],
			'status' => 'ok',
			'reason' => $decision['reason'],
			'confidence' => $decision['confidence'],
		];
	}

	/**
	 * @param array<string,mixed> $profile
	 * @param array<string,mixed> $rule
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	private function runEmbeddingRule(array $profile, array $rule, array $context): array {
		$provider = $this->providers->create((string)$profile['provider']);
		$instruction = trim((string)$rule['embedding_instruction']);
		if ($instruction === '') {
			$instruction = trim((string)$profile['default_instruction']);
		}

		$embeddingText = (string)($context['embedding_text'] ?? $context['text']);
		$entryVectorKey = hash('sha256', implode('|', [
			$profile['provider'],
			$profile['model'],
			$profile['base_url'],
			(string)($profile['embedding_dimensions'] ?? 0),
			(string)($profile['embedding_num_ctx'] ?? 0),
			$instruction,
			$embeddingText,
		]));

		if (!isset($this->entryEmbeddingMemo[$entryVectorKey])) {
			$vectors = $provider->embedTexts($profile, [$embeddingText], $instruction);
			if (!isset($vectors[0])) {
				throw new RuntimeException('No embedding was returned for the entry.');
			}
			$this->entryEmbeddingMemo[$entryVectorKey] = $vectors[0];
		}
		$entryVector = $this->entryEmbeddingMemo[$entryVectorKey];

		$anchors = $rule['embedding_anchor_texts'];
		$uncached = [];
		$anchorVectors = [];
		foreach ($anchors as $anchor) {
			$cacheKey = $this->anchorCacheKey($profile, $instruction, (string)$anchor);
			$cachedVector = $this->cache->get($cacheKey);
			if ($cachedVector !== null) {
				$anchorVectors[(string)$anchor] = $cachedVector;
			} else {
				$uncached[] = (string)$anchor;
			}
		}

		if (count($uncached) > 0) {
			$uncachedVectors = $provider->embedTexts($profile, $uncached, $instruction);
			foreach ($uncached as $index => $anchor) {
				if (!isset($uncachedVectors[$index])) {
					throw new RuntimeException('Some anchor embeddings were not returned.');
				}
				$cacheKey = $this->anchorCacheKey($profile, $instruction, $anchor);
				$this->cache->set($cacheKey, $uncachedVectors[$index]);
				$anchorVectors[$anchor] = $uncachedVectors[$index];
			}
		}

		$threshold = (float)$rule['embedding_threshold'];
		$bestSimilarity = -1.0;
		$bestAnchor = '';
		foreach ($anchors as $anchor) {
			$anchor = (string)$anchor;
			if (!isset($anchorVectors[$anchor])) {
				continue;
			}
			$similarity = $this->cosineSimilarity($entryVector, $anchorVectors[$anchor]);
			if ($similarity > $bestSimilarity) {
				$bestSimilarity = $similarity;
				$bestAnchor = $anchor;
			}
		}

		return [
			'rule_id' => $rule['id'],
			'rule_name' => $rule['name'],
			'target_tags' => $rule['target_tags'],
			'mode' => 'embedding',
			'matched' => $bestSimilarity >= $threshold,
			'status' => 'ok',
			'reason' => $bestAnchor === '' ? '' : 'Best anchor: ' . $bestAnchor,
			'confidence' => $bestSimilarity < 0 ? null : round($bestSimilarity, 4),
			'threshold' => $threshold,
		];
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	private function anchorCacheKey(array $profile, string $instruction, string $anchorText): string {
		return hash('sha256', implode('|', [
			(string)$profile['provider'],
			(string)$profile['model'],
			(string)$profile['base_url'],
			(string)($profile['embedding_dimensions'] ?? 0),
			(string)($profile['embedding_num_ctx'] ?? 0),
			$instruction,
			$anchorText,
		]));
	}

	/**
	 * @param array<string,mixed> $rule
	 * @param array<string,string> $context
	 */
	private function buildLlmPrompt(array $rule, array $context): string {
		$template = trim((string)$rule['llm_prompt']);
		if ($template === '') {
			$template = <<<TXT
Decide whether this article should receive the label "{{label}}".
Be conservative and only return match=true when the article clearly fits the label.
TXT;
		}

		$renderedTemplate = strtr($template, [
			'{{label}}' => implode(', ', $rule['target_tags']),
			'{{title}}' => $context['title'],
			'{{content}}' => $context['content'],
			'{{feed}}' => $context['feed'],
			'{{authors}}' => $context['authors'],
			'{{url}}' => $context['url'],
		]);

		return trim($renderedTemplate) . "\n\nArticle data:\n" . $context['text'];
	}

	/**
	 * @param list<float> $left
	 * @param list<float> $right
	 */
	private function cosineSimilarity(array $left, array $right): float {
		$count = min(count($left), count($right));
		if ($count === 0) {
			return -1.0;
		}

		$dot = 0.0;
		$leftNorm = 0.0;
		$rightNorm = 0.0;
		for ($index = 0; $index < $count; ++$index) {
			$dot += $left[$index] * $right[$index];
			$leftNorm += $left[$index] ** 2;
			$rightNorm += $right[$index] ** 2;
		}

		if ($leftNorm <= 0.0 || $rightNorm <= 0.0) {
			return -1.0;
		}

		return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
	}

	/**
	 * @param array<string,mixed> $rule
	 * @return array<string,mixed>
	 */
	private function skippedResult(array $rule, string $status, string $reason = ''): array {
		return [
			'rule_id' => $rule['id'],
			'rule_name' => $rule['name'],
			'target_tags' => $rule['target_tags'],
			'mode' => $rule['mode'],
			'matched' => false,
			'status' => $status,
			'reason' => $reason,
		];
	}
}

final class AutoLabelEntryPersistence {
	/** @var array<string,int> */
	private static array $tagIdsByName = [];

	/**
	 * @return array{updated:bool,applied_tags:list<string>,failed_tags:list<string>}
	 */
	public static function updateTags($entryDao, FreshRSS_Entry $entry, array $tags): array {
		$tags = self::normalizeTags($tags);
		$resolvedTags = self::ensureTagsExist($tags);
		$appliedTags = $resolvedTags['applied_tags'];
		$failedTags = $resolvedTags['failed_tags'];
		if (count($appliedTags) === 0) {
			return [
				'updated' => false,
				'applied_tags' => [],
				'failed_tags' => $failedTags,
			];
		}

		$updated = AutoLabelQueueUpdateGuard::withoutQueueing(static function () use ($entryDao, $entry, $appliedTags): bool {
			$entry->_tags($appliedTags);
			$payload = [
				'id' => $entry->id(),
				'guid' => $entry->guid(),
				'title' => $entry->title(),
				'author' => method_exists($entry, 'authors') ? (string)$entry->authors(true) : $entry->author(),
				'content' => $entry->content(false),
				'link' => $entry->link(true),
				'date' => (int)$entry->date(true),
				'lastSeen' => method_exists($entry, 'lastSeen') ? $entry->lastSeen() : 0,
				'lastModified' => method_exists($entry, 'lastModified') ? $entry->lastModified() : 0,
				'lastUserModified' => method_exists($entry, 'lastUserModified') ? $entry->lastUserModified() : 0,
				'hash' => $entry->hash(),
				'is_read' => $entry->isRead(),
				'is_favorite' => $entry->isFavorite(),
				'id_feed' => $entry->feedId(),
				'tags' => (string)$entry->tags(true),
				'attributes' => method_exists($entry, 'attributes') ? $entry->attributes() : [],
			];

			if (!(bool)$entryDao->updateEntry($payload)) {
				return false;
			}

			self::ensureEntryTagLinks($entry, $appliedTags);
			return true;
		});

		if (!$updated) {
			return [
				'updated' => false,
				'applied_tags' => $appliedTags,
				'failed_tags' => $failedTags,
			];
		}

		return [
			'updated' => true,
			'applied_tags' => $appliedTags,
			'failed_tags' => $failedTags,
		];
	}

	/**
	 * @param list<string> $tags
	 * @return array{applied_tags:list<string>,failed_tags:list<string>}
	 */
	private static function ensureTagsExist(array $tags): array {
		if (count($tags) === 0) {
			return [
				'applied_tags' => [],
				'failed_tags' => [],
			];
		}

		$tagDao = FreshRSS_Factory::createTagDao();
		$appliedTags = [];
		$failedTags = [];
		foreach ($tags as $tagName) {
			if (isset(self::$tagIdsByName[$tagName]) && self::$tagIdsByName[$tagName] > 0) {
				$appliedTags[] = $tagName;
				continue;
			}

			$tag = $tagDao->searchByName($tagName);
			if ($tag instanceof FreshRSS_Tag && $tag->id() > 0) {
				self::$tagIdsByName[$tagName] = $tag->id();
				$appliedTags[] = $tagName;
				continue;
			}

			$failedTags[] = $tagName;
			Minz_Log::warning('AutoLabel skipped a missing target tag: ' . $tagName);
		}

		return [
			'applied_tags' => array_values(array_unique($appliedTags)),
			'failed_tags' => array_values(array_unique($failedTags)),
		];
	}

	/**
	 * @param list<string> $tags
	 */
	private static function ensureEntryTagLinks(FreshRSS_Entry $entry, array $tags): void {
		$entryId = (int)$entry->id();
		if ($entryId <= 0 || count($tags) === 0) {
			return;
		}

		$tagDao = FreshRSS_Factory::createTagDao();
		foreach ($tags as $tagName) {
			$tagId = self::$tagIdsByName[$tagName] ?? 0;
			if ($tagId <= 0) {
				continue;
			}
			$tagDao->tagEntry($tagId, (string)$entryId, true);
		}
	}

	/**
	 * @param list<string> $tags
	 * @return list<string>
	 */
	private static function normalizeTags(array $tags): array {
		$normalized = [];
		foreach ($tags as $tag) {
			$tag = ltrim(trim((string)$tag), '#');
			if ($tag !== '') {
				$normalized[$tag] = $tag;
			}
		}

		return array_values($normalized);
	}
}

final class AutoLabelQueueUpdateGuard {
	private static int $depth = 0;

	public static function isActive(): bool {
		return self::$depth > 0;
	}

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function withoutQueueing(callable $callback) {
		self::$depth++;
		try {
			return $callback();
		} finally {
			self::$depth = max(0, self::$depth - 1);
		}
	}
}

final class AutoLabelBackfillService {
	/** @var AutoLabelSystemProfileRepository */
	private $profiles;
	/** @var AutoLabelEngine */
	private $engine;
	/** @var AutoLabelDiagnosticsStore */
	private $diagnostics;

	public function __construct(
		AutoLabelSystemProfileRepository $profiles,
		AutoLabelEngine $engine,
		AutoLabelDiagnosticsStore $diagnostics
	) {
		$this->profiles = $profiles;
		$this->engine = $engine;
		$this->diagnostics = $diagnostics;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return array{processed:int,updated:int,matched_tags:int}
	 */
	public function run(array $rules, int $lookbackDays, int $limit): array {
		$state = [
			'lookback_days' => $lookbackDays,
			'limit' => $limit,
			'offset' => 0,
			'processed' => 0,
			'updated' => 0,
			'matched_tags' => 0,
			'concurrent_entries' => 0,
			'fallback_entries' => 0,
		];
		$summary = ['processed' => 0, 'updated' => 0, 'matched_tags' => 0, 'concurrent_entries' => 0, 'fallback_entries' => 0];
		do {
			$result = $this->processJobSlice($rules, $state);
			$state = $result['state'];
			$summary['processed'] = (int)$state['processed'];
			$summary['updated'] = (int)$state['updated'];
			$summary['matched_tags'] = (int)$state['matched_tags'];
			$summary['concurrent_entries'] = (int)($state['concurrent_entries'] ?? 0);
			$summary['fallback_entries'] = (int)($state['fallback_entries'] ?? 0);
			if (!empty($result['deferred'])) {
				break;
			}
		} while (empty($result['finished']));

		return $summary;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @param array<string,mixed> $state
	 * @return array{state:array<string,mixed>,finished:bool,deferred:bool}
	 */
	public function processJobSlice(array $rules, array $state, ?int $maxEntriesOverride = null): array {
		if (count($rules) === 0) {
			return ['state' => $state, 'finished' => true, 'deferred' => false];
		}

		$limit = max(1, min(1000, (int)($state['limit'] ?? 0)));
		$lookbackDays = max(1, min(3650, (int)($state['lookback_days'] ?? 0)));
		$cutoff = time() - ($lookbackDays * 86400);
		$entryDao = FreshRSS_Factory::createEntryDao();
		$processed = max(0, (int)($state['processed'] ?? 0));
		$updated = max(0, (int)($state['updated'] ?? 0));
		$matchedTags = max(0, (int)($state['matched_tags'] ?? 0));
		$totalConcurrentEntries = max(0, (int)($state['concurrent_entries'] ?? 0));
		$totalFallbackEntries = max(0, (int)($state['fallback_entries'] ?? 0));
		$offset = max(0, (int)($state['offset'] ?? 0));
		$pendingQueue = array_values(array_filter(is_array($state['pending_queue'] ?? null) ? $state['pending_queue'] : [], 'is_array'));
		$fetchBatchSize = $this->resolveFetchBatchSize($rules);
		$profiles = $this->profilesForRules($rules);
		if (count($profiles) === 0) {
			return [
				'state' => [
					'lookback_days' => $lookbackDays,
					'limit' => $limit,
					'offset' => $offset,
					'processed' => $processed,
					'updated' => $updated,
					'matched_tags' => $matchedTags,
					'concurrent_entries' => $totalConcurrentEntries,
					'fallback_entries' => $totalFallbackEntries,
					'pending_queue' => [],
				],
				'finished' => true,
				'deferred' => false,
			];
		}

		$remaining = $limit - $processed;
		if ($remaining <= 0) {
			return [
				'state' => [
					'lookback_days' => $lookbackDays,
					'limit' => $limit,
					'offset' => $offset,
					'processed' => $processed,
					'updated' => $updated,
					'matched_tags' => $matchedTags,
					'concurrent_entries' => $totalConcurrentEntries,
					'fallback_entries' => $totalFallbackEntries,
					'pending_queue' => $pendingQueue,
				],
				'finished' => true,
				'deferred' => false,
			];
		}

		$currentBatchSize = min($fetchBatchSize, $remaining);
		if ($maxEntriesOverride !== null) {
			$currentBatchSize = min($currentBatchSize, max(1, $maxEntriesOverride));
		}
		$selected = [];
		$selectedCountsByProfile = [];
		$deferredQueue = [];
		$exhausted = false;
		$batchSequence = 0;

		while (count($selected) < $currentBatchSize) {
			$queuedCandidate = array_shift($pendingQueue);
			$entry = null;
			$entryDescriptor = [];
			$candidateRules = [];
			$attempts = 0;

			if (is_array($queuedCandidate)) {
				$entryDescriptor = is_array($queuedCandidate['entry'] ?? null) ? $queuedCandidate['entry'] : [];
				$attempts = max(0, (int)($queuedCandidate['attempts'] ?? 0));
				$entry = $this->resolveBackfillDescriptor($entryDao, $entryDescriptor);
				$candidateRules = $this->rulesForBackfillQueueItem($rules, is_array($queuedCandidate['rule_ids'] ?? null) ? $queuedCandidate['rule_ids'] : []);
				if (!$entry instanceof FreshRSS_Entry) {
					++$processed;
					$this->diagnostics->append([
						'type' => 'backfill_entry',
						'entry_title' => (string)($entryDescriptor['title'] ?? ''),
						'result' => ['results' => []],
						'updated' => false,
						'failed_tags' => [],
						'error' => 'Entry could not be resolved for backfill retry.',
					]);
					continue;
				}
			} else {
				$entries = $entryDao->listWhere('a', 0, FreshRSS_Entry::STATE_ALL, null, '0', '0', 'date', 'DESC', '0', [], 1, $offset, 'id', 'DESC');
				$offset++;
				$entry = null;
				if (is_iterable($entries)) {
					foreach ($entries as $candidateEntry) {
						$entry = $candidateEntry;
						break;
					}
				}
				if (!$entry instanceof FreshRSS_Entry) {
					$exhausted = true;
					break;
				}
				if ((int)$entry->date(true) < $cutoff) {
					$exhausted = true;
					break;
				}

				$entryDescriptor = $this->backfillEntryDescriptor($entry);
				$candidateRules = $rules;
			}

			if (count($candidateRules) === 0) {
				++$processed;
				continue;
			}

			$rulesByProfile = $this->groupRulesByProfile($candidateRules);
			if (count($rulesByProfile) === 0) {
				++$processed;
				continue;
			}

			$fitsWindow = true;
			foreach ($rulesByProfile as $profileId => $profileRules) {
				$profile = $this->profiles->find((string)$profileId);
				if (!is_array($profile) || empty($profile['enabled'])) {
					continue;
				}
				$limitForProfile = AutoLabelSystemProfileRepository::normalizeBatchSize((int)($profile['batch_size'] ?? AutoLabelSystemProfileRepository::DEFAULT_BATCH_SIZE));
				if (($selectedCountsByProfile[$profileId] ?? 0) >= $limitForProfile) {
					$fitsWindow = false;
					break;
				}
			}

			if (!$fitsWindow) {
				$deferredQueue[] = [
					'entry' => $entryDescriptor,
					'rule_ids' => array_values(array_map(static fn (array $rule): string => (string)$rule['id'], $candidateRules)),
					'attempts' => $attempts,
				];
				if ($queuedCandidate === null) {
					continue;
				}
				continue;
			}

			foreach ($rulesByProfile as $profileId => $_profileRules) {
				$selectedCountsByProfile[$profileId] = ($selectedCountsByProfile[$profileId] ?? 0) + 1;
			}

			$selected[] = [
				'entry' => $entry,
				'entry_descriptor' => $entryDescriptor,
				'rules' => $candidateRules,
				'rules_by_profile' => $rulesByProfile,
				'attempts' => $attempts,
			];
		}

		if (count($selected) === 0) {
			return [
				'state' => [
					'lookback_days' => $lookbackDays,
					'limit' => $limit,
					'offset' => $offset,
					'processed' => $processed,
					'updated' => $updated,
					'matched_tags' => $matchedTags,
					'concurrent_entries' => $totalConcurrentEntries,
					'fallback_entries' => $totalFallbackEntries,
					'pending_queue' => array_values(array_merge($deferredQueue, $pendingQueue)),
				],
				'finished' => $exhausted && count($deferredQueue) === 0 && count($pendingQueue) === 0,
				'deferred' => false,
			];
		}

		$resultsByEntry = [];
		foreach ($selected as $index => $candidate) {
			$resultsByEntry[$index] = [
				'tags' => is_array($candidate['entry']->tags(false)) ? $candidate['entry']->tags(false) : [],
				'results' => [],
				'failed_rule_ids' => [],
				'failed_tags' => [],
			];
		}

		foreach ($selectedCountsByProfile as $profileId => $countForProfile) {
			$profile = $this->profiles->find((string)$profileId);
			if (!is_array($profile) || empty($profile['enabled'])) {
				continue;
			}
			$tasks = [];
			foreach ($selected as $index => $candidate) {
				if (!isset($candidate['rules_by_profile'][$profileId])) {
					continue;
				}
				$tasks[] = [
					'task_id' => (string)$index,
					'entry' => $candidate['entry'],
					'rules' => $candidate['rules_by_profile'][$profileId],
				];
			}
			if (count($tasks) === 0) {
				continue;
			}

			$startedAt = microtime(true);
			$batchResults = $this->engine->runProfileBatch($profile, $tasks);
			++$batchSequence;
			$failedEntries = 0;
			$concurrentEntries = 0;
			$fallbackEntries = 0;
			foreach ($tasks as $task) {
				$taskId = (string)$task['task_id'];
				$result = $batchResults[$taskId] ?? [
					'tags' => [],
					'results' => [],
					'failed_rule_ids' => array_values(array_map(static fn (array $rule): string => (string)$rule['id'], $task['rules'])),
					'transport' => 'concurrent',
				];
				$resultsByEntry[(int)$taskId]['tags'] = array_values(array_unique(array_merge($resultsByEntry[(int)$taskId]['tags'], $result['tags'] ?? [])));
				$resultsByEntry[(int)$taskId]['results'] = array_merge($resultsByEntry[(int)$taskId]['results'], $result['results'] ?? []);
				$resultsByEntry[(int)$taskId]['failed_rule_ids'] = array_values(array_unique(array_merge($resultsByEntry[(int)$taskId]['failed_rule_ids'], $result['failed_rule_ids'] ?? [])));
				$resultsByEntry[(int)$taskId]['transport'] = (string)($result['transport'] ?? 'concurrent');
				if (($result['transport'] ?? 'concurrent') === 'fallback_retry') {
					$fallbackEntries++;
				} else {
					$concurrentEntries++;
				}
				if (count($result['failed_rule_ids'] ?? []) > 0) {
					$failedEntries++;
				}
			}

			$this->diagnostics->append([
				'type' => 'backfill_batch',
				'profile_id' => $profileId,
				'profile_name' => (string)($profile['name'] ?? $profileId),
				'batch_index' => $batchSequence,
				'window_size' => $countForProfile,
				'processed_entries' => count($tasks),
				'concurrent_entries' => $concurrentEntries,
				'fallback_entries' => $fallbackEntries,
				'failed_entries' => $failedEntries,
				'execution_mode' => $fallbackEntries > 0 ? ($concurrentEntries > 0 ? 'mixed' : 'fallback_retry') : 'concurrent',
				'remaining_entries' => max(0, $limit - $processed),
				'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
			]);
			$totalConcurrentEntries += $concurrentEntries;
			$totalFallbackEntries += $fallbackEntries;
		}

		foreach ($selected as $index => $candidate) {
			$entry = $candidate['entry'];
			$entryResult = $resultsByEntry[$index];
			$beforeTags = is_array($entry->tags(false)) ? $entry->tags(false) : [];
			$persist = ['updated' => false, 'applied_tags' => [], 'failed_tags' => []];
			if (count($entryResult['tags']) > count($beforeTags)) {
				$persist = AutoLabelEntryPersistence::updateTags($entryDao, $entry, $entryResult['tags']);
				if (!empty($persist['updated'])) {
					++$updated;
					$matchedTags += max(0, count($persist['applied_tags']) - count($beforeTags));
				}
			}

			$this->diagnostics->append([
				'type' => 'backfill_entry',
				'entry_title' => $entry->title(),
				'result' => [
					'tags' => $entryResult['tags'],
					'results' => $entryResult['results'],
					'transport' => $entryResult['transport'] ?? 'concurrent',
				],
				'updated' => !empty($persist['updated']),
				'failed_tags' => is_array($persist['failed_tags'] ?? null) ? $persist['failed_tags'] : [],
			]);

			if (count($entryResult['failed_rule_ids']) > 0) {
				$attempts = (int)$candidate['attempts'] + 1;
				if ($attempts < 3) {
					$deferredQueue[] = [
						'entry' => $candidate['entry_descriptor'],
						'rule_ids' => $entryResult['failed_rule_ids'],
						'attempts' => $attempts,
					];
					continue;
				}
			}

			++$processed;
		}

		return [
			'state' => [
				'lookback_days' => $lookbackDays,
				'limit' => $limit,
				'offset' => $offset,
				'processed' => $processed,
				'updated' => $updated,
				'matched_tags' => $matchedTags,
				'concurrent_entries' => $totalConcurrentEntries,
				'fallback_entries' => $totalFallbackEntries,
				'pending_queue' => array_values(array_merge($deferredQueue, $pendingQueue)),
			],
			'finished' => $processed >= $limit || ($exhausted && count($deferredQueue) === 0 && count($pendingQueue) === 0),
			'deferred' => false,
		];
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return list<array<string,mixed>>
	 */
	private function profilesForRules(array $rules): array {
		$profilesById = [];
		foreach ($rules as $rule) {
			$profileId = (string)($rule['profile_id'] ?? '');
			if ($profileId === '' || isset($profilesById[$profileId])) {
				continue;
			}

			$profile = $this->profiles->find($profileId);
			if (is_array($profile) && !empty($profile['enabled'])) {
				$profilesById[$profileId] = $profile;
			}
		}

		return array_values($profilesById);
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 */
	private function resolveFetchBatchSize(array $rules): int {
		$profiles = $this->profilesForRules($rules);
		$batchSize = 1;
		foreach ($profiles as $profile) {
			$batchSize = max(
				$batchSize,
				AutoLabelSystemProfileRepository::normalizeBatchSize((int)($profile['batch_size'] ?? AutoLabelSystemProfileRepository::DEFAULT_BATCH_SIZE))
			);
		}

		return $batchSize;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @param list<string> $ruleIds
	 * @return list<array<string,mixed>>
	 */
	private function rulesForBackfillQueueItem(array $rules, array $ruleIds): array {
		if (count($ruleIds) === 0) {
			return $rules;
		}

		$wanted = array_fill_keys(array_map(static fn ($ruleId): string => trim((string)$ruleId), $ruleIds), true);
		$filtered = [];
		foreach ($rules as $rule) {
			if (!isset($wanted[(string)$rule['id']])) {
				continue;
			}
			$filtered[] = $rule;
		}

		return $filtered;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function groupRulesByProfile(array $rules): array {
		$grouped = [];
		foreach ($rules as $rule) {
			if (!($rule['enabled'] ?? false)) {
				continue;
			}
			$profileId = trim((string)($rule['profile_id'] ?? ''));
			if ($profileId === '') {
				continue;
			}
			$profile = $this->profiles->find($profileId);
			if (!is_array($profile) || empty($profile['enabled'])) {
				continue;
			}
			if (!$this->engine->supportsConcurrentWindow()) {
				continue;
			}
			$grouped[$profileId][] = $rule;
		}
		return $grouped;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function backfillEntryDescriptor(FreshRSS_Entry $entry): array {
		return [
			'entry_id' => method_exists($entry, 'id') ? (int)$entry->id() : 0,
			'feed_id' => method_exists($entry, 'feedId') ? (int)$entry->feedId() : 0,
			'guid' => method_exists($entry, 'guid') ? trim((string)$entry->guid()) : '',
			'link' => method_exists($entry, 'link') ? trim((string)$entry->link(true)) : '',
			'title' => method_exists($entry, 'title') ? trim((string)$entry->title()) : '',
			'date' => method_exists($entry, 'date') ? (int)$entry->date(true) : time(),
		];
	}

	private function resolveBackfillDescriptor($entryDao, array $descriptor): ?FreshRSS_Entry {
		$entryId = (int)($descriptor['entry_id'] ?? 0);
		if ($entryId > 0 && method_exists($entryDao, 'searchById')) {
			$entry = $entryDao->searchById((string)$entryId);
			if ($entry instanceof FreshRSS_Entry) {
				return $entry;
			}
		}

		$feedId = (int)($descriptor['feed_id'] ?? 0);
		$guid = trim((string)($descriptor['guid'] ?? ''));
		if ($feedId > 0 && $guid !== '' && method_exists($entryDao, 'searchByGuid')) {
			$entry = $entryDao->searchByGuid($feedId, $guid);
			if ($entry instanceof FreshRSS_Entry) {
				return $entry;
			}
		}

		return null;
	}
}

final class AutoLabelQueueProcessor {
	private const MAX_ENTRY_RESOLVE_ATTEMPTS = 5;
	private const ENTRY_RETRY_DELAY_SECONDS = 30;
	private const ENTRY_STALE_AFTER_SECONDS = 172800;
	private const ENTRY_SCAN_LIMIT = 500;
	private const ENTRY_SCAN_BATCH_SIZE = 100;
	private const DEFAULT_MAX_RUNTIME_SECONDS = 8.0;
	private const DEFAULT_MAX_PROCESSED_ITEMS = 20;
	private const DEFAULT_MAX_BACKFILL_ENTRIES = null;

	/** @var AutoLabelQueueStore */
	private $queue;
	/** @var AutoLabelSystemProfileRepository */
	private $profiles;
	/** @var AutoLabelUserRuleRepository */
	private $rules;
	/** @var AutoLabelEngine */
	private $engine;
	/** @var AutoLabelDiagnosticsStore */
	private $diagnostics;
	/** @var AutoLabelBackfillService */
	private $backfill;

	public function __construct(
		AutoLabelQueueStore $queue,
		AutoLabelSystemProfileRepository $profiles,
		AutoLabelUserRuleRepository $rules,
		AutoLabelEngine $engine,
		AutoLabelDiagnosticsStore $diagnostics,
		AutoLabelBackfillService $backfill
	) {
		$this->queue = $queue;
		$this->profiles = $profiles;
		$this->rules = $rules;
		$this->engine = $engine;
		$this->diagnostics = $diagnostics;
		$this->backfill = $backfill;
	}

	/**
	 * @return array{processed_items:int,processed_entries:int,updated_entries:int,matched_tags:int,remaining_items:int}
	 */
	/**
	 * @param array{max_runtime_seconds?:float,max_processed_items?:int,max_backfill_entries?:int|null,profile_timeout_cap_seconds?:int|null,source?:string} $options
	 * @return array{processed_items:int,processed_entries:int,updated_entries:int,matched_tags:int,remaining_items:int}
	 */
	public function process(array $options = []): array {
		$initialVersion = $this->queue->version();
		$items = $this->queue->allItems();
		usort($items, static function (array $left, array $right): int {
			$leftPriority = ($left['type'] ?? '') === 'entry' ? 0 : 1;
			$rightPriority = ($right['type'] ?? '') === 'entry' ? 0 : 1;
			if ($leftPriority !== $rightPriority) {
				return $leftPriority <=> $rightPriority;
			}

			return strcmp((string)($left['enqueued_at'] ?? ''), (string)($right['enqueued_at'] ?? ''));
		});
		$remainingItems = [];
		$stats = [
			'processed_items' => 0,
			'processed_entries' => 0,
			'updated_entries' => 0,
			'matched_tags' => 0,
			'remaining_items' => 0,
		];
		$maxRuntimeSeconds = isset($options['max_runtime_seconds']) ? max(0.1, (float)$options['max_runtime_seconds']) : self::DEFAULT_MAX_RUNTIME_SECONDS;
		$maxProcessedItems = isset($options['max_processed_items']) ? max(1, (int)$options['max_processed_items']) : self::DEFAULT_MAX_PROCESSED_ITEMS;
		$maxBackfillEntries = array_key_exists('max_backfill_entries', $options) && $options['max_backfill_entries'] !== null
			? max(1, (int)$options['max_backfill_entries'])
			: self::DEFAULT_MAX_BACKFILL_ENTRIES;
		$profileTimeoutCapSeconds = array_key_exists('profile_timeout_cap_seconds', $options) && $options['profile_timeout_cap_seconds'] !== null
			? max(1, (int)$options['profile_timeout_cap_seconds'])
			: null;
		$source = trim((string)($options['source'] ?? 'unspecified'));
		if ($source === '') {
			$source = 'unspecified';
		}
		$startedAt = microtime(true);
		$this->engine->setTimeoutCap($profileTimeoutCapSeconds);

		try {
			if (!$this->engine->supportsConcurrentWindow()) {
				$remainingItems = $items;
				$this->diagnostics->append([
					'type' => 'queue_concurrency_unavailable',
					'source' => $source,
					'message' => 'Concurrent batch execution requires the PHP curl extension.',
				]);
			} else {
				$remainingItems = $items;
				do {
					$madeProgress = false;
					if ($stats['processed_items'] < $maxProcessedItems && (microtime(true) - $startedAt) < $maxRuntimeSeconds) {
						$entryPass = $this->processConcurrentEntryPass(
							$remainingItems,
							$maxProcessedItems - $stats['processed_items']
						);
						$remainingItems = $entryPass['items'];
						$madeProgress = $madeProgress || $entryPass['made_progress'];
						$stats['processed_items'] += $entryPass['stats']['processed_items'];
						$stats['processed_entries'] += $entryPass['stats']['processed_entries'];
						$stats['updated_entries'] += $entryPass['stats']['updated_entries'];
						$stats['matched_tags'] += $entryPass['stats']['matched_tags'];
					}

					if ($stats['processed_items'] < $maxProcessedItems && (microtime(true) - $startedAt) < $maxRuntimeSeconds) {
						$backfillPass = $this->processConcurrentBackfillPass(
							$remainingItems,
							$maxProcessedItems - $stats['processed_items'],
							$maxBackfillEntries
						);
						$remainingItems = $backfillPass['items'];
						$madeProgress = $madeProgress || $backfillPass['made_progress'];
						$stats['processed_items'] += $backfillPass['stats']['processed_items'];
						$stats['processed_entries'] += $backfillPass['stats']['processed_entries'];
						$stats['updated_entries'] += $backfillPass['stats']['updated_entries'];
						$stats['matched_tags'] += $backfillPass['stats']['matched_tags'];
					}

					if (!$madeProgress) {
						break;
					}
				} while ($stats['processed_items'] < $maxProcessedItems && (microtime(true) - $startedAt) < $maxRuntimeSeconds);
			}
		} finally {
			$this->engine->setTimeoutCap(null);
		}

		$stats['remaining_items'] = count($remainingItems);
		$stored = $this->queue->replaceItems($remainingItems, [
			'at' => date(DATE_ATOM),
			'stats' => $stats,
		], $initialVersion);
		if (!$stored) {
			$stats['remaining_items'] = count($this->queue->allItems());
			$this->diagnostics->append([
				'type' => 'queue_version_conflict',
				'source' => $source,
				'stats' => $stats,
			]);
		}

		if ($stats['processed_items'] > 0 || $stats['updated_entries'] > 0) {
			$this->diagnostics->append([
				'type' => 'queue_run',
				'source' => $source,
				'stats' => $stats,
			]);
		}

		return $stats;
	}

	/**
	 * @param list<array<string,mixed>> $items
	 * @return array{items:list<array<string,mixed>>,stats:array{processed_items:int,processed_entries:int,updated_entries:int,matched_tags:int},made_progress:bool}
	 */
	private function processConcurrentEntryPass(array $items, int $itemBudget): array {
		$stats = [
			'processed_items' => 0,
			'processed_entries' => 0,
			'updated_entries' => 0,
			'matched_tags' => 0,
		];
		if ($itemBudget <= 0) {
			return ['items' => $items, 'stats' => $stats, 'made_progress' => false];
		}

		$selectedIndexes = [];
		$selectedStates = [];
		$selectedCountsByProfile = [];
		$itemsToRemove = [];
		$retryItemsByIndex = [];
		$now = time();

		foreach ($items as $index => $item) {
			if (($item['type'] ?? '') !== 'entry') {
				continue;
			}
			if (count($selectedStates) >= $itemBudget) {
				break;
			}
			if ((int)($item['next_attempt_at'] ?? 0) > $now) {
				continue;
			}

			$rules = $this->rulesForItem($item);
			if (count($rules) === 0) {
				$itemsToRemove[$index] = true;
				$stats['processed_items']++;
				continue;
			}

			$entryDescriptor = is_array($item['entry'] ?? null) ? $item['entry'] : [];
			$entry = $this->resolveQueuedEntry($entryDescriptor);
			if (!$entry instanceof FreshRSS_Entry) {
				$item['attempts'] = (int)($item['attempts'] ?? 0) + 1;
				$item['next_attempt_at'] = $now + self::ENTRY_RETRY_DELAY_SECONDS;
				$isStale = $this->entryDescriptorDate($entryDescriptor) < ($now - self::ENTRY_STALE_AFTER_SECONDS);
				if ($item['attempts'] >= self::MAX_ENTRY_RESOLVE_ATTEMPTS || $isStale) {
					$this->diagnostics->append([
						'type' => 'queue_drop',
						'reason' => 'entry_not_found',
						'item' => $item,
					]);
					$itemsToRemove[$index] = true;
					$stats['processed_items']++;
				} else {
					$retryItemsByIndex[$index] = $item;
				}
				continue;
			}

			$rulesByProfile = $this->groupRulesByProfile($rules);
			if (count($rulesByProfile) === 0) {
				$itemsToRemove[$index] = true;
				$stats['processed_items']++;
				continue;
			}

			$fitsWindow = true;
			foreach ($rulesByProfile as $profileId => $_profileRules) {
				$limit = $this->profileWindowSize($profileId);
				if (($selectedCountsByProfile[$profileId] ?? 0) >= $limit) {
					$fitsWindow = false;
					break;
				}
			}
			if (!$fitsWindow) {
				continue;
			}

			foreach ($rulesByProfile as $profileId => $_profileRules) {
				$selectedCountsByProfile[$profileId] = ($selectedCountsByProfile[$profileId] ?? 0) + 1;
			}

			$selectedIndexes[$index] = true;
			$selectedStates[$index] = [
				'item' => $item,
				'entry' => $entry,
				'descriptor' => $entryDescriptor,
				'rules' => $rules,
				'rules_by_profile' => $rulesByProfile,
				'before_tags' => is_array($entry->tags(false)) ? $entry->tags(false) : [],
			];
		}

		if (count($selectedStates) === 0 && count($itemsToRemove) === 0 && count($retryItemsByIndex) === 0) {
			return ['items' => $items, 'stats' => $stats, 'made_progress' => false];
		}

		$aggregates = [];
		foreach ($selectedStates as $index => $state) {
			$aggregates[$index] = [
				'tags' => $state['before_tags'],
				'results' => [],
				'failed_rule_ids' => [],
			];
		}

		$batchSequence = 0;
		foreach ($selectedCountsByProfile as $profileId => $windowSize) {
			$profile = $this->profiles->find((string)$profileId);
			if (!is_array($profile) || empty($profile['enabled'])) {
				continue;
			}
			$tasks = [];
			foreach ($selectedStates as $index => $state) {
				if (!isset($state['rules_by_profile'][$profileId])) {
					continue;
				}
				$tasks[] = [
					'task_id' => (string)$index,
					'entry' => $state['entry'],
					'rules' => $state['rules_by_profile'][$profileId],
				];
			}
			if (count($tasks) === 0) {
				continue;
			}

			$startedAt = microtime(true);
			$batchResults = $this->engine->runProfileBatch($profile, $tasks);
			++$batchSequence;
			$failedEntries = 0;
			$concurrentEntries = 0;
			$fallbackEntries = 0;
			foreach ($tasks as $task) {
				$taskId = (int)$task['task_id'];
				$result = $batchResults[(string)$taskId] ?? ['tags' => [], 'results' => [], 'failed_rule_ids' => [], 'transport' => 'concurrent'];
				$aggregates[$taskId]['tags'] = array_values(array_unique(array_merge($aggregates[$taskId]['tags'], $result['tags'] ?? [])));
				$aggregates[$taskId]['results'] = array_merge($aggregates[$taskId]['results'], $result['results'] ?? []);
				$aggregates[$taskId]['failed_rule_ids'] = array_values(array_unique(array_merge($aggregates[$taskId]['failed_rule_ids'], $result['failed_rule_ids'] ?? [])));
				$aggregates[$taskId]['transport'] = (string)($result['transport'] ?? 'concurrent');
				if (($result['transport'] ?? 'concurrent') === 'fallback_retry') {
					$fallbackEntries++;
				} else {
					$concurrentEntries++;
				}
				if (count($result['failed_rule_ids'] ?? []) > 0) {
					$failedEntries++;
				}
			}

			$this->diagnostics->append([
				'type' => 'queue_batch',
				'profile_id' => $profileId,
				'profile_name' => (string)($profile['name'] ?? $profileId),
				'batch_index' => $batchSequence,
				'window_size' => $windowSize,
				'processed_entries' => count($tasks),
				'concurrent_entries' => $concurrentEntries,
				'fallback_entries' => $fallbackEntries,
				'failed_entries' => $failedEntries,
				'execution_mode' => $fallbackEntries > 0 ? ($concurrentEntries > 0 ? 'mixed' : 'fallback_retry') : 'concurrent',
				'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
			]);
		}

		$entryDao = FreshRSS_Factory::createEntryDao();
		$retryQueue = [];
		foreach ($selectedStates as $index => $state) {
			$aggregate = $aggregates[$index];
			$persist = ['updated' => false, 'applied_tags' => [], 'failed_tags' => []];
			if (count($aggregate['tags']) > count($state['before_tags'])) {
				$persist = AutoLabelEntryPersistence::updateTags($entryDao, $state['entry'], $aggregate['tags']);
				if (!empty($persist['updated'])) {
					$stats['updated_entries']++;
					$stats['matched_tags'] += max(0, count($persist['applied_tags']) - count($state['before_tags']));
				}
			}

			$this->diagnostics->append([
				'type' => 'queue_entry',
				'entry_title' => $state['entry']->title(),
				'result' => [
					'tags' => $aggregate['tags'],
					'results' => $aggregate['results'],
					'transport' => $aggregate['transport'] ?? 'concurrent',
				],
				'updated' => !empty($persist['updated']),
				'failed_tags' => $persist['failed_tags'] ?? [],
			]);

			$stats['processed_items']++;
			$stats['processed_entries']++;

			if (count($aggregate['failed_rule_ids']) > 0) {
				$attempts = (int)($state['item']['attempts'] ?? 0) + 1;
				if ($attempts < 3) {
					$retryItem = $state['item'];
					$retryItem['attempts'] = $attempts;
					$retryItem['next_attempt_at'] = $now + self::ENTRY_RETRY_DELAY_SECONDS;
					$retryItem['rule_ids'] = $aggregate['failed_rule_ids'];
					$retryQueue[] = $retryItem;
					continue;
				}

				$this->diagnostics->append([
					'type' => 'queue_drop',
					'reason' => 'max_retries_reached',
					'item' => $state['item'],
					'failed_rule_ids' => $aggregate['failed_rule_ids'],
				]);
			}
		}

		$newItems = [];
		foreach ($items as $index => $item) {
			if (isset($selectedIndexes[$index]) || isset($itemsToRemove[$index])) {
				continue;
			}
			if (isset($retryItemsByIndex[$index])) {
				$newItems[] = $retryItemsByIndex[$index];
				continue;
			}
			$newItems[] = $item;
		}
		$newItems = array_merge($newItems, $retryQueue);

		return ['items' => $newItems, 'stats' => $stats, 'made_progress' => true];
	}

	/**
	 * @param list<array<string,mixed>> $items
	 * @return array{items:list<array<string,mixed>>,stats:array{processed_items:int,processed_entries:int,updated_entries:int,matched_tags:int},made_progress:bool}
	 */
	private function processConcurrentBackfillPass(array $items, int $itemBudget, ?int $maxBackfillEntries = null): array {
		$stats = [
			'processed_items' => 0,
			'processed_entries' => 0,
			'updated_entries' => 0,
			'matched_tags' => 0,
		];
		if ($itemBudget <= 0) {
			return ['items' => $items, 'stats' => $stats, 'made_progress' => false];
		}

		foreach ($items as $index => $item) {
			if (($item['type'] ?? '') !== 'backfill') {
				continue;
			}

			$rules = $this->rulesForItem($item);
			if (count($rules) === 0) {
				$newItems = $items;
				unset($newItems[$index]);
				$stats['processed_items'] = 1;
				return ['items' => array_values($newItems), 'stats' => $stats, 'made_progress' => true];
			}

			$state = is_array($item['state'] ?? null) ? $item['state'] : [];
			$result = $this->backfill->processJobSlice($rules, $state, $maxBackfillEntries);
			$item['state'] = $result['state'];
			$stats['processed_entries'] = (int)($result['state']['processed'] ?? 0) - (int)($state['processed'] ?? 0);
			$stats['updated_entries'] = (int)($result['state']['updated'] ?? 0) - (int)($state['updated'] ?? 0);
			$stats['matched_tags'] = (int)($result['state']['matched_tags'] ?? 0) - (int)($state['matched_tags'] ?? 0);
			$stats['processed_items'] = !empty($result['deferred']) ? 0 : 1;

			$newItems = $items;
			if (!empty($result['finished'])) {
				$this->diagnostics->append([
					'type' => 'backfill',
					'stats' => $result['state'],
				]);
				unset($newItems[$index]);
			} else {
				$newItems[$index] = $item;
			}

			return ['items' => array_values($newItems), 'stats' => $stats, 'made_progress' => $stats['processed_items'] > 0 || $stats['processed_entries'] > 0];
		}

		return ['items' => $items, 'stats' => $stats, 'made_progress' => false];
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array{keep:bool,item?:array<string,mixed>,processed_items:int,processed_entries:int,updated_entries:int,matched_tags:int}
	 */
	private function processEntryItem(array $item): array {
		$now = time();
		if ((int)($item['next_attempt_at'] ?? 0) > $now) {
			return ['keep' => true, 'item' => $item, 'processed_items' => 0, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}

		$rules = $this->rulesForItem($item);
		if (count($rules) === 0) {
			return ['keep' => false, 'processed_items' => 1, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}

		$profiles = $this->profilesForRules($rules);
		if (count($profiles) === 0) {
			return ['keep' => false, 'processed_items' => 1, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}
		if (!$this->hasAvailableCapacity($profiles)) {
			return ['keep' => true, 'item' => $item, 'processed_items' => 0, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}

		$entryDescriptor = is_array($item['entry'] ?? null) ? $item['entry'] : [];
		$entry = $this->resolveQueuedEntry($entryDescriptor);
		if (!$entry instanceof FreshRSS_Entry) {
			$item['attempts'] = (int)($item['attempts'] ?? 0) + 1;
			$item['next_attempt_at'] = $now + self::ENTRY_RETRY_DELAY_SECONDS;
			$isStale = $this->entryDescriptorDate($entryDescriptor) < ($now - self::ENTRY_STALE_AFTER_SECONDS);
			if ($item['attempts'] >= self::MAX_ENTRY_RESOLVE_ATTEMPTS || $isStale) {
				$this->diagnostics->append([
					'type' => 'queue_drop',
					'reason' => 'entry_not_found',
					'item' => $item,
				]);
				return ['keep' => false, 'processed_items' => 1, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
			}

			return ['keep' => true, 'item' => $item, 'processed_items' => 0, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}

		$beforeTags = is_array($entry->tags(false)) ? $entry->tags(false) : [];
		$result = $this->engine->runRules($entry, $rules, false);
		$afterTags = $result['tags'];
		$updatedEntries = 0;
		$matchedTags = 0;
		$failedTags = [];
		if (count($afterTags) > count($beforeTags)) {
			$entryDao = FreshRSS_Factory::createEntryDao();
			$persist = AutoLabelEntryPersistence::updateTags($entryDao, $entry, $afterTags);
			$failedTags = $persist['failed_tags'];
			if ($persist['updated']) {
				$updatedEntries = 1;
				$matchedTags = max(0, count($persist['applied_tags']) - count($beforeTags));
			}
		}

		$this->diagnostics->append([
			'type' => 'queue_entry',
			'entry_title' => $entry->title(),
			'result' => $result,
			'updated' => $updatedEntries === 1,
			'failed_tags' => $failedTags,
		]);

		return [
			'keep' => false,
			'processed_items' => 1,
			'processed_entries' => 1,
			'updated_entries' => $updatedEntries,
			'matched_tags' => $matchedTags,
		];
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array{keep:bool,item?:array<string,mixed>,processed_items:int,processed_entries:int,updated_entries:int,matched_tags:int}
	 */
	private function processBackfillItem(array $item, ?int $maxBackfillEntries = null): array {
		$rules = $this->rulesForItem($item);
		if (count($rules) === 0) {
			return ['keep' => false, 'processed_items' => 1, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}

		$profiles = $this->profilesForRules($rules);
		if (count($profiles) === 0) {
			return ['keep' => false, 'processed_items' => 1, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}
		if (!$this->hasAvailableCapacity($profiles)) {
			return ['keep' => true, 'item' => $item, 'processed_items' => 0, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}

		$state = is_array($item['state'] ?? null) ? $item['state'] : [];
		$result = $this->backfill->processJobSlice($rules, $state, $maxBackfillEntries);
		$item['state'] = $result['state'];

		if (!empty($result['finished'])) {
			$this->diagnostics->append([
				'type' => 'backfill',
				'stats' => $result['state'],
			]);
			return [
				'keep' => false,
				'processed_items' => 1,
				'processed_entries' => (int)($result['state']['processed'] ?? 0) - (int)($state['processed'] ?? 0),
				'updated_entries' => (int)($result['state']['updated'] ?? 0) - (int)($state['updated'] ?? 0),
				'matched_tags' => (int)($result['state']['matched_tags'] ?? 0) - (int)($state['matched_tags'] ?? 0),
			];
		}

		return [
			'keep' => true,
			'item' => $item,
			'processed_items' => !empty($result['deferred']) ? 0 : 1,
			'processed_entries' => (int)($result['state']['processed'] ?? 0) - (int)($state['processed'] ?? 0),
			'updated_entries' => (int)($result['state']['updated'] ?? 0) - (int)($state['updated'] ?? 0),
			'matched_tags' => (int)($result['state']['matched_tags'] ?? 0) - (int)($state['matched_tags'] ?? 0),
		];
	}

	/**
	 * @param array<string,mixed> $item
	 * @return list<array<string,mixed>>
	 */
	private function rulesForItem(array $item): array {
		$ruleIds = is_array($item['rule_ids'] ?? null) ? $item['rule_ids'] : [];
		if (count($ruleIds) === 0) {
			return $this->rules->enabled();
		}

		$rules = [];
		foreach ($ruleIds as $ruleId) {
			$rule = $this->rules->find((string)$ruleId);
			if (is_array($rule)) {
				$rules[] = $rule;
			}
		}
		return $rules;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return list<array<string,mixed>>
	 */
	private function profilesForRules(array $rules): array {
		$profiles = [];
		foreach ($rules as $rule) {
			$profileId = (string)($rule['profile_id'] ?? '');
			if ($profileId === '' || isset($profiles[$profileId])) {
				continue;
			}
			$profile = $this->profiles->find($profileId);
			if (is_array($profile) && !empty($profile['enabled'])) {
				$profiles[$profileId] = $profile;
			}
		}
		return array_values($profiles);
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function groupRulesByProfile(array $rules): array {
		$grouped = [];
		foreach ($rules as $rule) {
			if (!($rule['enabled'] ?? false)) {
				continue;
			}
			$profileId = trim((string)($rule['profile_id'] ?? ''));
			if ($profileId === '') {
				continue;
			}
			$profile = $this->profiles->find($profileId);
			if (!is_array($profile) || empty($profile['enabled'])) {
				continue;
			}
			$grouped[$profileId][] = $rule;
		}

		return $grouped;
	}

	private function profileWindowSize(string $profileId): int {
		$profile = $this->profiles->find($profileId);
		if (!is_array($profile)) {
			return AutoLabelSystemProfileRepository::DEFAULT_BATCH_SIZE;
		}

		return AutoLabelSystemProfileRepository::normalizeBatchSize((int)($profile['batch_size'] ?? AutoLabelSystemProfileRepository::DEFAULT_BATCH_SIZE));
	}

	/**
	 * @param list<array<string,mixed>> $profiles
	 */
	private function hasAvailableCapacity(array $profiles): bool {
		if (count($profiles) === 0) {
			return false;
		}

		foreach ($profiles as $profile) {
			if (AutoLabelRuntimeBatchGate::hasCapacity($profile)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $descriptor
	 */
	private function resolveQueuedEntry(array $descriptor): ?FreshRSS_Entry {
		$entryDao = FreshRSS_Factory::createEntryDao();
		$entryId = (int)($descriptor['entry_id'] ?? 0);
		if ($entryId > 0 && method_exists($entryDao, 'searchById')) {
			$entry = $entryDao->searchById((string)$entryId);
			if ($entry instanceof FreshRSS_Entry) {
				return $entry;
			}
		}

		$feedId = (int)($descriptor['feed_id'] ?? 0);
		$guid = trim((string)($descriptor['guid'] ?? ''));
		if ($feedId > 0 && $guid !== '' && method_exists($entryDao, 'searchByGuid')) {
			$entry = $entryDao->searchByGuid($feedId, $guid);
			if ($entry instanceof FreshRSS_Entry) {
				return $entry;
			}
		}

		for ($offset = 0; $offset < self::ENTRY_SCAN_LIMIT; $offset += self::ENTRY_SCAN_BATCH_SIZE) {
			$entries = $entryDao->listWhere('a', 0, FreshRSS_Entry::STATE_ALL, null, '0', '0', 'date', 'DESC', '0', [], self::ENTRY_SCAN_BATCH_SIZE, $offset, 'id', 'DESC');
			if (!is_iterable($entries)) {
				return null;
			}

			$foundAny = false;
			foreach ($entries as $entry) {
				$foundAny = true;
				if ($this->matchesDescriptor($entry, $descriptor)) {
					return $entry;
				}
			}

			if (!$foundAny) {
				return null;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $descriptor
	 */
	private function matchesDescriptor(FreshRSS_Entry $entry, array $descriptor): bool {
		$entryId = (int)($descriptor['entry_id'] ?? 0);
		if ($entryId > 0 && (int)$entry->id() === $entryId) {
			return true;
		}

		$guid = trim((string)($descriptor['guid'] ?? ''));
		if ($guid !== '' && trim((string)$entry->guid()) === $guid) {
			return true;
		}

		$link = trim((string)($descriptor['link'] ?? ''));
		$title = trim((string)($descriptor['title'] ?? ''));
		$date = (int)($descriptor['date'] ?? 0);
		$feedId = (int)($descriptor['feed_id'] ?? 0);

		if ($link !== '' && trim((string)$entry->link(true)) === $link) {
			if ($title === '' || trim((string)$entry->title()) === $title) {
				return true;
			}
		}

		if ($title !== '' && trim((string)$entry->title()) === $title && $date > 0 && (int)$entry->date(true) === $date) {
			if ($feedId <= 0 || (int)$entry->feedId() === $feedId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $descriptor
	 */
	private function entryDescriptorDate(array $descriptor): int {
		return max(0, (int)($descriptor['date'] ?? 0));
	}
}
