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
			$this->queueProcessor()->process([
				'max_runtime_seconds' => 20.0,
				'max_processed_items' => 120,
				'source' => 'maintenance',
			]);
		} catch (Throwable $throwable) {
			Minz_Log::warning('AutoLabel queue maintenance failed: ' . $throwable->getMessage());
		}
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
}
