<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

final class AutoLabelExtension extends Minz_Extension {
	private static ?self $instance = null;

	public static function instance(): ?self {
		return self::$instance;
	}

	public function init(): void {
		parent::init();
		self::$instance = $this;

		$this->registerController('autolabel');
		$this->registerViews();
		$this->registerTranslates();
		$this->registerHook($this->hookName('EntryBeforeAdd', 'entry_before_add'), [$this, 'handleEntryBeforeAdd']);
		$this->registerHook($this->hookName('FreshrssUserMaintenance', 'freshrss_user_maintenance'), [$this, 'runQueueMaintenance']);
		$this->registerHook($this->hookName('MenuAdminEntry', 'menu_admin_entry'), [$this, 'renderNavigationEntry']);
	}

	public function profilesConfiguration(): array {
		if (method_exists($this, 'getSystemConfigurationArray')) {
			return $this->getSystemConfigurationArray('profiles') ?? [];
		}

		$configuration = $this->getSystemConfiguration();
		return is_array($configuration['profiles'] ?? null) ? $configuration['profiles'] : [];
	}

	/**
	 * @param array<int,array<string,mixed>> $profiles
	 */
	public function saveProfilesConfiguration(array $profiles): void {
		if (method_exists($this, 'setSystemConfigurationValue')) {
			$this->setSystemConfigurationValue('profiles', array_values($profiles));
			return;
		}

		$configuration = $this->getSystemConfiguration();
		$configuration['profiles'] = array_values($profiles);
		$this->setSystemConfiguration($configuration);
	}

	public function rulesConfiguration(): array {
		if (method_exists($this, 'getUserConfigurationArray')) {
			return $this->getUserConfigurationArray('rules') ?? [];
		}

		$configuration = $this->getUserConfiguration();
		return is_array($configuration['rules'] ?? null) ? $configuration['rules'] : [];
	}

	/**
	 * @param array<int,array<string,mixed>> $rules
	 */
	public function saveRulesConfiguration(array $rules): void {
		if (method_exists($this, 'setUserConfigurationValue')) {
			$this->setUserConfigurationValue('rules', array_values($rules));
			return;
		}

		$configuration = $this->getUserConfiguration();
		$configuration['rules'] = array_values($rules);
		$this->setUserConfiguration($configuration);
	}

	public function diagnosticsEnabled(): bool {
		if (method_exists($this, 'getUserConfigurationValue')) {
			$value = $this->getUserConfigurationValue('diagnostics_enabled');
			return $value === null ? true : (bool)$value;
		}

		$configuration = $this->getUserConfiguration();
		if (!array_key_exists('diagnostics_enabled', $configuration)) {
			return true;
		}

		return (bool)$configuration['diagnostics_enabled'];
	}

	public function setDiagnosticsEnabled(bool $enabled): void {
		if (method_exists($this, 'setUserConfigurationValue')) {
			$this->setUserConfigurationValue('diagnostics_enabled', $enabled);
			return;
		}

		$configuration = $this->getUserConfiguration();
		$configuration['diagnostics_enabled'] = $enabled;
		$this->setUserConfiguration($configuration);
	}

	public function readUserDataFile(string $filename): ?string {
		if (method_exists($this, 'getFile')) {
			return $this->getFile($filename);
		}

		$path = $this->fallbackDataPath($filename);
		if (!is_readable($path)) {
			return null;
		}

		$content = @file_get_contents($path);
		return is_string($content) ? $content : null;
	}

	public function writeUserDataFile(string $filename, string $content): void {
		if (method_exists($this, 'saveFile')) {
			$this->saveFile($filename, $content);
			return;
		}

		$path = $this->fallbackDataPath($filename);
		$directory = dirname($path);
		if (!is_dir($directory)) {
			@mkdir($directory, 0775, true);
		}
		@file_put_contents($path, $content);
	}

	public function deleteUserDataFile(string $filename): void {
		if (method_exists($this, 'removeFile')) {
			$this->removeFile($filename);
			return;
		}

		$path = $this->fallbackDataPath($filename);
		if (is_file($path)) {
			@unlink($path);
		}
	}

	public function systemProfiles(): AutoLabelSystemProfileRepository {
		return new AutoLabelSystemProfileRepository($this);
	}

	public function profileCapabilities(): AutoLabelProfileCapabilityResolver {
		return new AutoLabelProfileCapabilityResolver();
	}

	public function userRules(): AutoLabelUserRuleRepository {
		return new AutoLabelUserRuleRepository($this, $this->systemProfiles(), $this->profileCapabilities());
	}

	public function diagnostics(): AutoLabelDiagnosticsStore {
		return new AutoLabelDiagnosticsStore($this);
	}

	public function queueStore(): AutoLabelQueueStore {
		return new AutoLabelQueueStore($this);
	}

	public function embeddingCache(): AutoLabelEmbeddingCacheStore {
		return new AutoLabelEmbeddingCacheStore($this);
	}

	public function engine(): AutoLabelEngine {
		$http = new AutoLabelHttpClient();
		return new AutoLabelEngine(
			$http,
			$this->systemProfiles(),
			$this->userRules(),
			$this->profileCapabilities(),
			new AutoLabelEntryTextExtractor(),
			$this->embeddingCache(),
			$this->diagnostics(),
			new AutoLabelProviderFactory($http),
		);
	}

	public function backfillService(): AutoLabelBackfillService {
		return new AutoLabelBackfillService($this->systemProfiles(), $this->engine(), $this->diagnostics());
	}

	public function queueProcessor(): AutoLabelQueueProcessor {
		return new AutoLabelQueueProcessor(
			$this->queueStore(),
			$this->systemProfiles(),
			$this->userRules(),
			$this->engine(),
			$this->diagnostics(),
			$this->backfillService()
		);
	}

	public function queueCronToken(): string {
		$salt = '';
		if (class_exists('FreshRSS_Context') && method_exists('FreshRSS_Context', 'systemConf')) {
			$systemConf = FreshRSS_Context::systemConf();
			if (is_object($systemConf) && isset($systemConf->salt) && is_string($systemConf->salt)) {
				$salt = $systemConf->salt;
			}
		}

		return hash('sha256', 'autolabel-queue|' . $salt . '|' . $this->getPath());
	}

	public function handleEntryBeforeAdd(FreshRSS_Entry $entry): FreshRSS_Entry {
		try {
			$this->queueStore()->enqueueEntry($entry, [], 'reception');
		} catch (Throwable $throwable) {
			Minz_Log::warning('AutoLabel failed while queueing a new entry: ' . $throwable->getMessage());
		}

		return $entry;
	}

	public function handleEntryBeforeUpdate(FreshRSS_Entry $entry): FreshRSS_Entry {
		// FreshRSS updates entries for many reasons beyond a real content refresh,
		// so queueing every update causes the queue to grow indefinitely.
		return $entry;
	}

	public function runQueueMaintenance(): void {
		try {
			$this->drainQueueUntilIdle([
				'max_runtime_seconds' => 20.0,
				'max_processed_items' => 500,
				'source' => 'maintenance',
			], [
				'max_total_seconds' => 900.0,
				'max_idle_rounds' => 3,
			]);
		} catch (Throwable $throwable) {
			Minz_Log::warning('AutoLabel queue maintenance failed: ' . $throwable->getMessage());
		}
	}

	/**
	 * @param array{max_runtime_seconds?:float,max_processed_items?:int,max_backfill_entries?:int|null,profile_timeout_cap_seconds?:int|null,source?:string} $sliceOptions
	 * @param array{max_total_seconds?:float,max_idle_rounds?:int,sleep_seconds?:int} $drainOptions
	 * @return array{ok:bool,done:bool,made_progress:bool,stats:array<string,int>,snapshot:array<string,mixed>,rounds:int,idle_rounds:int,timed_out:bool}
	 */
	public function drainQueueUntilIdle(array $sliceOptions = [], array $drainOptions = []): array {
		$maxTotalSeconds = isset($drainOptions['max_total_seconds']) ? max(1.0, (float)$drainOptions['max_total_seconds']) : 600.0;
		$maxIdleRounds = isset($drainOptions['max_idle_rounds']) ? max(1, (int)$drainOptions['max_idle_rounds']) : 3;
		$sleepSeconds = isset($drainOptions['sleep_seconds']) ? max(0, (int)$drainOptions['sleep_seconds']) : 0;
		$startedAt = microtime(true);
		$rounds = 0;
		$idleRounds = 0;
		$aggregate = [
			'processed_items' => 0,
			'processed_entries' => 0,
			'updated_entries' => 0,
			'matched_tags' => 0,
			'remaining_items' => 0,
		];
		$madeProgress = false;
		$timedOut = false;
		$snapshot = $this->queueStore()->snapshot();

		while (!$this->queueSnapshotDone($snapshot)) {
			if ((microtime(true) - $startedAt) >= $maxTotalSeconds) {
				$timedOut = true;
				break;
			}

			$beforeSnapshot = $snapshot;
			$stats = $this->queueProcessor()->process($sliceOptions);
			++$rounds;

			$aggregate['processed_items'] += (int)($stats['processed_items'] ?? 0);
			$aggregate['processed_entries'] += (int)($stats['processed_entries'] ?? 0);
			$aggregate['updated_entries'] += (int)($stats['updated_entries'] ?? 0);
			$aggregate['matched_tags'] += (int)($stats['matched_tags'] ?? 0);

			$snapshot = $this->queueStore()->snapshot();
			$aggregate['remaining_items'] = (int)(($snapshot['pending_entries'] ?? 0) + ($snapshot['pending_backfills'] ?? 0));

			$roundMadeProgress = $this->queueStatsMadeProgress($stats) || $this->queueSnapshotImproved($beforeSnapshot, $snapshot);
			$madeProgress = $madeProgress || $roundMadeProgress;

			if ($this->queueSnapshotDone($snapshot)) {
				$idleRounds = 0;
				break;
			}

			if ($roundMadeProgress) {
				$idleRounds = 0;
			} else {
				++$idleRounds;
				if ($idleRounds >= $maxIdleRounds) {
					break;
				}
			}

			if ($sleepSeconds > 0) {
				sleep($sleepSeconds);
			}
		}

		return [
			'ok' => true,
			'done' => $this->queueSnapshotDone($snapshot),
			'made_progress' => $madeProgress,
			'stats' => $aggregate,
			'snapshot' => $snapshot,
			'rounds' => $rounds,
			'idle_rounds' => $idleRounds,
			'timed_out' => $timedOut,
		];
	}

	public function renderNavigationEntry(): string {
		$url = _url('autolabel', 'index');
		$label = _t('ext.auto_label.nav');
		return '<li class="item"><a href="' . $url . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></li>';
	}

	private function hookName(string $hookConstantName, string $fallback): string {
		if (class_exists('Minz_HookType')) {
			$constantName = 'Minz_HookType::' . $hookConstantName;
			if (defined($constantName)) {
				$hook = constant($constantName);
				if (is_object($hook) && property_exists($hook, 'value')) {
					return (string)$hook->value;
				}
				if (is_string($hook)) {
					return $hook;
				}
			}
		}

		return $fallback;
	}

	private function fallbackDataPath(string $filename): string {
		return rtrim($this->getPath(), '/\\') . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . ltrim($filename, '/\\');
	}

	/**
	 * @param array<string,mixed> $snapshot
	 */
	private function queueSnapshotDone(array $snapshot): bool {
		return (int)($snapshot['pending_entries'] ?? 0) === 0
			&& (int)($snapshot['pending_backfills'] ?? 0) === 0
			&& (int)($snapshot['pending_backfill_entries'] ?? 0) === 0;
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 */
	private function queueSnapshotImproved(array $before, array $after): bool {
		$beforePendingEntries = (int)($before['pending_entries'] ?? 0);
		$afterPendingEntries = (int)($after['pending_entries'] ?? 0);
		$beforePendingBackfills = (int)($before['pending_backfills'] ?? 0);
		$afterPendingBackfills = (int)($after['pending_backfills'] ?? 0);
		$beforePendingBackfillEntries = (int)($before['pending_backfill_entries'] ?? 0);
		$afterPendingBackfillEntries = (int)($after['pending_backfill_entries'] ?? 0);

		return $afterPendingEntries < $beforePendingEntries
			|| $afterPendingBackfills < $beforePendingBackfills
			|| $afterPendingBackfillEntries < $beforePendingBackfillEntries;
	}

	/**
	 * @param array<string,mixed> $stats
	 */
	private function queueStatsMadeProgress(array $stats): bool {
		return (int)($stats['processed_items'] ?? 0) > 0
			|| (int)($stats['processed_entries'] ?? 0) > 0
			|| (int)($stats['updated_entries'] ?? 0) > 0
			|| (int)($stats['matched_tags'] ?? 0) > 0;
	}
}
