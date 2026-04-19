<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';

final class FreshExtension_autolabel_Controller extends FreshRSS_ActionController {
	private AutoLabelExtension $extension;

	public function firstAction(): void {
		$extension = AutoLabelExtension::instance();
		if (!$extension instanceof AutoLabelExtension) {
			Minz_Error::error(500);
			return;
		}

		$this->extension = $extension;
		FreshRSS_View::prependTitle($this->extension->getName() . ' · ');
		$actionName = Minz_Request::actionName();
		if ($actionName !== 'cronQueue' && !FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
			return;
		}
		if (in_array($actionName, ['processQueueBatch', 'cronQueue', 'startManualQueue', 'manualQueueStatus'], true)) {
			$this->view()->_layout(null);
		}
	}

	public function indexAction(): void {
		$this->requireUser();
		$canAdmin = FreshRSS_Auth::hasAccess('admin');
		$profiles = $this->extension->systemProfiles()->all();
		$rules = $this->extension->userRules()->all();
		$editProfileId = Minz_Request::paramString('edit_profile');
		$editRuleId = Minz_Request::paramString('edit_rule');
		$profileRepository = $this->extension->systemProfiles();
		$ruleRepository = $this->extension->userRules();

		AutoLabelViewState::replace([
			'can_admin' => $canAdmin,
			'profiles' => $canAdmin ? $profiles : [],
			'enabled_profiles' => $profileRepository->enabled(),
			'providers' => $canAdmin ? $profileRepository->providers() : [],
			'profile_modes' => $canAdmin ? $profileRepository->modes() : [],
			'available_tags' => $this->availableTags(),
			'rule_profiles' => $this->profilesForRuleForm($profiles, $editRuleId),
			'rules' => $rules,
			'profiles_by_id' => $this->profilesById($profiles),
			'diagnostics' => $this->extension->diagnostics()->all(),
			'diagnostics_enabled' => $this->extension->diagnosticsEnabled(),
			'queue_snapshot' => $this->extension->queueStore()->snapshot(),
			'queue_concurrency_available' => $this->extension->engine()->supportsConcurrentWindow(),
			'manual_queue_run' => $this->extension->queueStore()->manualRun(),
			'queue_batch_url' => _url('autolabel', 'processQueueBatch'),
			'queue_manual_start_url' => _url('autolabel', 'startManualQueue'),
			'queue_manual_status_url' => _url('autolabel', 'manualQueueStatus'),
			'queue_cron_url' => $canAdmin ? (_url('autolabel', 'cronQueue') . '?token=' . rawurlencode($this->extension->queueCronToken())) : '',
			'tag_management_url' => _url('tag', 'index'),
			'profile_capabilities' => $this->extension->profileCapabilities(),
			'profile_form' => $canAdmin
				? ($editProfileId !== '' ? ($profileRepository->find($editProfileId) ?? $profileRepository->defaultProfile()) : $profileRepository->defaultProfile())
				: $profileRepository->defaultProfile(),
			'rule_form' => $editRuleId !== '' ? ($ruleRepository->find($editRuleId) ?? $ruleRepository->defaultRule()) : $ruleRepository->defaultRule(),
			'style_url' => $this->extension->getFileUrl('style.css'),
			'script_url' => $this->extension->getFileUrl('script.js'),
		]);
	}

	public function saveProfileAction(): void {
		$this->requireAdmin();
		$redirect = ['c' => 'autolabel', 'a' => 'index'];
		if (!Minz_Request::isPost()) {
			Minz_Request::forward($redirect, true);
			return;
		}

		try {
			$profile = $this->extension->systemProfiles()->saveFromPayload([
				'id' => Minz_Request::paramString('profile_id'),
				'name' => Minz_Request::paramString('name'),
				'provider' => Minz_Request::paramString('provider'),
				'profile_mode' => Minz_Request::paramString('profile_mode'),
				'model' => Minz_Request::paramString('model'),
				'base_url' => Minz_Request::paramString('base_url'),
				'api_key' => Minz_Request::paramString('api_key'),
				'enabled' => Minz_Request::paramBoolean('enabled'),
				'timeout_seconds' => (int)Minz_Request::paramString('timeout_seconds'),
				'content_max_chars' => (int)Minz_Request::paramString('content_max_chars'),
				'batch_size' => (int)Minz_Request::paramString('batch_size'),
				'embedding_dimensions' => (int)Minz_Request::paramString('embedding_dimensions'),
				'embedding_num_ctx' => (int)Minz_Request::paramString('embedding_num_ctx'),
				'default_instruction' => Minz_Request::paramString('default_instruction'),
			]);

			Minz_Request::good(_t('ext.auto_label.messages.profile_saved', $profile['name']), $redirect);
		} catch (Throwable $throwable) {
			Minz_Request::bad($throwable->getMessage(), $redirect);
		}

		Minz_Request::forward($redirect, true);
	}

	public function deleteProfileAction(): void {
		$this->requireAdmin();
		$redirect = ['c' => 'autolabel', 'a' => 'index'];
		if (Minz_Request::isPost()) {
			$this->extension->systemProfiles()->delete(Minz_Request::paramString('profile_id'));
			Minz_Request::good(_t('ext.auto_label.messages.profile_deleted'), $redirect);
		}
		Minz_Request::forward($redirect, true);
	}

	public function toggleProfileAction(): void {
		$this->requireAdmin();
		$redirect = ['c' => 'autolabel', 'a' => 'index'];
		if (Minz_Request::isPost()) {
			try {
				$this->extension->systemProfiles()->setEnabled(
					Minz_Request::paramString('profile_id'),
					Minz_Request::paramBoolean('enabled')
				);
				Minz_Request::good(_t('ext.auto_label.messages.profile_updated'), $redirect);
			} catch (Throwable $throwable) {
				Minz_Request::bad($throwable->getMessage(), $redirect);
			}
		}
		Minz_Request::forward($redirect, true);
	}

	public function testProfileAction(): void {
		$this->requireAdmin();
		$redirect = ['c' => 'autolabel', 'a' => 'index'];
		if (!Minz_Request::isPost()) {
			Minz_Request::forward($redirect, true);
			return;
		}

		$profile = $this->extension->systemProfiles()->find(Minz_Request::paramString('profile_id'));
		if ($profile === null) {
			Minz_Request::bad(_t('ext.auto_label.messages.unknown_profile'), $redirect);
			Minz_Request::forward($redirect, true);
			return;
		}

		try {
			$provider = (new AutoLabelProviderFactory(new AutoLabelHttpClient()))->create((string)$profile['provider']);
			if (!empty($profile['supports_llm'])) {
				$result = $provider->classify($profile, 'Target label: test. Return whether this short text should match. Article data: Title: Test. Content: This is a profile connectivity test.');
			} elseif (!empty($profile['supports_embedding'])) {
				$embeddings = $provider->embedTexts($profile, ['This is a profile connectivity test.'], (string)$profile['default_instruction']);
				$result = ['match' => true, 'reason' => 'Received ' . count($embeddings) . ' embedding vector(s).', 'confidence' => null, 'raw' => ''];
			} else {
				throw new RuntimeException('The profile has no enabled capability.');
			}

			$this->extension->diagnostics()->append([
				'type' => 'profile_test',
				'profile_name' => $profile['name'],
				'provider' => $profile['provider'],
				'result' => $result,
			]);
			Minz_Request::good(_t('ext.auto_label.messages.profile_tested', $profile['name']), $redirect);
		} catch (Throwable $throwable) {
			Minz_Request::bad($throwable->getMessage(), $redirect);
		}

		Minz_Request::forward($redirect, true);
	}

	public function saveRuleAction(): void {
		$this->requireUser();
		$redirect = ['c' => 'autolabel', 'a' => 'index'];
		if (!Minz_Request::isPost()) {
			Minz_Request::forward($redirect, true);
			return;
		}

		try {
			$rule = $this->extension->userRules()->saveFromPayload([
				'id' => Minz_Request::paramString('rule_id'),
				'name' => Minz_Request::paramString('name'),
				'enabled' => Minz_Request::paramBoolean('enabled'),
				'target_tags' => $this->requestTargetTags(),
				'profile_id' => Minz_Request::paramString('profile_id'),
				'mode' => Minz_Request::paramString('mode'),
				'llm_prompt' => Minz_Request::paramString('llm_prompt'),
				'embedding_anchor_texts' => Minz_Request::paramString('embedding_anchor_texts'),
				'embedding_threshold' => (float)Minz_Request::paramString('embedding_threshold'),
				'embedding_instruction' => Minz_Request::paramString('embedding_instruction'),
			]);
			Minz_Request::good(_t('ext.auto_label.messages.rule_saved', $rule['name']), $redirect);
		} catch (Throwable $throwable) {
			Minz_Request::bad($throwable->getMessage(), $redirect);
		}

		Minz_Request::forward($redirect, true);
	}

	public function deleteRuleAction(): void {
		$this->requireUser();
		$redirect = ['c' => 'autolabel', 'a' => 'index'];
		if (Minz_Request::isPost()) {
			$this->extension->userRules()->delete(Minz_Request::paramString('rule_id'));
			Minz_Request::good(_t('ext.auto_label.messages.rule_deleted'), $redirect);
		}
		Minz_Request::forward($redirect, true);
	}

	public function toggleRuleAction(): void {
		$this->requireUser();
		$redirect = ['c' => 'autolabel', 'a' => 'index'];
		if (Minz_Request::isPost()) {
			try {
				$this->extension->userRules()->setEnabled(
					Minz_Request::paramString('rule_id'),
					Minz_Request::paramBoolean('enabled')
				);
				Minz_Request::good(_t('ext.auto_label.messages.rule_updated'), $redirect);
			} catch (Throwable $throwable) {
				Minz_Request::bad($throwable->getMessage(), $redirect);
			}
		}
		Minz_Request::forward($redirect, true);
	}

	public function dryRunAction(): void {
		$this->requireUser();
		$redirect = ['c' => 'autolabel', 'a' => 'index'];
		if (!Minz_Request::isPost()) {
			Minz_Request::forward($redirect, true);
			return;
		}

		$rule = $this->extension->userRules()->find(Minz_Request::paramString('rule_id'));
		if ($rule === null) {
			Minz_Request::bad(_t('ext.auto_label.messages.unknown_rule'), $redirect);
			Minz_Request::forward($redirect, true);
			return;
		}

		try {
			$entry = new FreshRSS_Entry(
				0,
				'dry-run',
				Minz_Request::paramString('sample_title'),
				'',
				nl2br(Minz_Request::paramString('sample_content'), false),
				Minz_Request::paramString('sample_url'),
				time(),
				false,
				false,
				''
			);

			$result = $this->extension->engine()->runRules($entry, [$rule], false);
			$this->extension->diagnostics()->append([
				'type' => 'dry_run',
				'rule_name' => $rule['name'],
				'result' => $result,
			]);

			$matched = count($result['tags']) > 0 ? implode(', ', $result['tags']) : _t('ext.auto_label.misc.none');
			Minz_Request::good(_t('ext.auto_label.messages.dry_run_done', $matched), $redirect);
		} catch (Throwable $throwable) {
			Minz_Request::bad($throwable->getMessage(), $redirect);
		}

		Minz_Request::forward($redirect, true);
	}

	public function backfillAction(): void {
		$this->requireUser();
		$redirect = ['c' => 'autolabel', 'a' => 'index'];
		if (!Minz_Request::isPost()) {
			Minz_Request::forward($redirect, true);
			return;
		}

		$ruleId = Minz_Request::paramString('backfill_rule_id');
		try {
			$job = $this->extension->queueStore()->enqueueBackfillJob(
				$ruleId !== '' ? [$ruleId] : [],
				(int)Minz_Request::paramString('lookback_days'),
				(int)Minz_Request::paramString('max_entries')
			);
			Minz_Request::good(
				_t('ext.auto_label.messages.backfill_queued', (int)($job['state']['limit'] ?? 0)),
				$redirect
			);
		} catch (Throwable $throwable) {
			Minz_Request::bad($throwable->getMessage(), $redirect);
		}

		Minz_Request::forward($redirect, true);
	}

	public function clearQueueAction(): void {
		$this->requireUser();
		$redirect = ['c' => 'autolabel', 'a' => 'index'];
		if (!Minz_Request::isPost()) {
			Minz_Request::forward($redirect, true);
			return;
		}

		$this->extension->queueStore()->clear();
		Minz_Request::good(_t('ext.auto_label.messages.queue_cleared'), $redirect);
		Minz_Request::forward($redirect, true);
	}

	public function saveDiagnosticsAction(): void {
		$this->requireUser();
		$redirect = ['c' => 'autolabel', 'a' => 'index'];
		if (!Minz_Request::isPost()) {
			Minz_Request::forward($redirect, true);
			return;
		}

		$this->extension->setDiagnosticsEnabled(Minz_Request::paramBoolean('diagnostics_enabled'));
		Minz_Request::good(_t('ext.auto_label.messages.diagnostics_updated'), $redirect);
		Minz_Request::forward($redirect, true);
	}

	public function clearDiagnosticsAction(): void {
		$this->requireUser();
		$redirect = ['c' => 'autolabel', 'a' => 'index'];
		if (!Minz_Request::isPost()) {
			Minz_Request::forward($redirect, true);
			return;
		}

		$this->extension->diagnostics()->clear();
		Minz_Request::good(_t('ext.auto_label.messages.diagnostics_cleared'), $redirect);
		Minz_Request::forward($redirect, true);
	}

	public function startManualQueueAction(): void {
		$this->handleStartManualQueue();
	}

	public function manualQueueStatusAction(): void {
		$this->handleManualQueueStatus();
	}

	public function processQueueAction(): void {
		$this->requireUser();
		$manualMode = Minz_Request::paramString('manual_queue_mode');
		if ($this->isAjaxRequest() && ($manualMode === 'start' || $manualMode === 'status')) {
			if ($manualMode === 'start') {
				$this->handleStartManualQueue();
			} else {
				$this->handleManualQueueStatus();
			}
			return;
		}

		$redirect = ['c' => 'autolabel', 'a' => 'index'];
		if (!Minz_Request::isPost()) {
			Minz_Request::forward($redirect, true);
			return;
		}

		if ($this->isAjaxRequest()) {
			try {
				$response = $this->runQueueBatch([
					'max_runtime_seconds' => 3.0,
					'max_processed_items' => 100,
					'profile_timeout_cap_seconds' => 4,
					'source' => 'processQueueAjax',
				]);
			} catch (Throwable $throwable) {
				$response = $this->queueErrorResponse('processQueue', $throwable);
			}

			$this->renderJson($response);
			return;
		}

		try {
			$stats = $this->extension->queueProcessor()->process([
				'max_runtime_seconds' => 2.0,
				'max_processed_items' => 50,
				'source' => 'processQueuePage',
			]);
			Minz_Request::good(
				_t('ext.auto_label.messages.queue_processed', $stats['processed_entries'], $stats['updated_entries']),
				$redirect
			);
		} catch (Throwable $throwable) {
			Minz_Request::bad($throwable->getMessage(), $redirect);
		}

		Minz_Request::forward($redirect, true);
	}

	public function processQueueBatchAction(): void {
		$this->requireUser();
		if (!Minz_Request::isPost() && !$this->isGetRequest()) {
			Minz_Error::error(405);
			return;
		}

		try {
			$response = $this->runQueueBatch([
				'max_runtime_seconds' => 2.0,
				'max_processed_items' => 50,
				'source' => 'processQueueBatch',
			]);
		} catch (Throwable $throwable) {
			$response = $this->queueErrorResponse('processQueueBatch', $throwable);
		}

		$this->renderJson($response);
	}

	public function cronQueueAction(): void {
		$token = Minz_Request::paramString('token');
		if (!$this->isValidQueueCronToken($token) && !FreshRSS_Auth::hasAccess('admin')) {
			Minz_Error::error(403);
			return;
		}

		$interactive = Minz_Request::paramBoolean('interactive');
		try {
			$response = $interactive
				? $this->runQueueBatch([
					'max_runtime_seconds' => 2.0,
					'max_processed_items' => 50,
					'source' => 'cronQueueInteractive',
				])
				: $this->extension->drainQueueUntilIdle([
					'max_runtime_seconds' => 20.0,
					'max_processed_items' => 500,
					'source' => 'cronQueue',
				], [
					'max_total_seconds' => 900.0,
					'max_idle_rounds' => 3,
				]);
		} catch (Throwable $throwable) {
			$response = $this->queueErrorResponse('cronQueue', $throwable);
		}

		$this->renderJson($response);
	}

	/**
	 * @param list<array<string,mixed>> $profiles
	 * @return list<array<string,mixed>>
	 */
	private function profilesForRuleForm(array $profiles, string $editRuleId): array {
		$currentRule = $editRuleId !== '' ? $this->extension->userRules()->find($editRuleId) : null;
		$currentProfileId = is_array($currentRule) ? (string)$currentRule['profile_id'] : '';

		$selectable = [];
		foreach ($profiles as $profile) {
			if (($profile['enabled'] ?? false) || $profile['id'] === $currentProfileId) {
				$selectable[] = $profile;
			}
		}

		return $selectable;
	}

	/**
	 * @param list<array<string,mixed>> $profiles
	 * @return array<string,array<string,mixed>>
	 */
	private function profilesById(array $profiles): array {
		$mapped = [];
		foreach ($profiles as $profile) {
			$mapped[(string)$profile['id']] = $profile;
		}
		return $mapped;
	}

	/**
	 * @return list<string>
	 */
	private function availableTags(): array {
		$available = [];
		$tagDao = FreshRSS_Factory::createTagDao();
		$tags = method_exists($tagDao, 'listTags') ? $tagDao->listTags() : [];
		if (is_array($tags)) {
			foreach ($tags as $tag) {
				if ($tag instanceof FreshRSS_Tag && method_exists($tag, 'name')) {
					$name = ltrim(trim((string)$tag->name()), '#');
					if ($name !== '') {
						$available[$name] = $name;
					}
				}
			}
		}

		natcasesort($available);
		return array_values($available);
	}

	/**
	 * @return list<string>
	 */
	private function requestTargetTags(): array {
		$targetTags = $_POST['target_tags'] ?? [];
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

	private function requireAdmin(): void {
		if (!FreshRSS_Auth::hasAccess('admin')) {
			Minz_Error::error(403);
		}
	}

	private function requireUser(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
		}
	}

	/**
	 * @param array{max_runtime_seconds?:float,max_processed_items?:int,max_backfill_entries?:int|null,profile_timeout_cap_seconds?:int|null,source?:string} $options
	 * @return array<string,mixed>
	 */
	private function runQueueBatch(array $options): array {
		$stats = $this->extension->queueProcessor()->process($options);
		$snapshot = $this->extension->queueStore()->snapshot();
		$done = (int)($snapshot['pending_entries'] ?? 0) === 0
			&& (int)($snapshot['pending_backfills'] ?? 0) === 0
			&& (int)($snapshot['pending_backfill_entries'] ?? 0) === 0;
		$madeProgress = (int)($stats['processed_items'] ?? 0) > 0
			|| (int)($stats['processed_entries'] ?? 0) > 0
			|| (int)($stats['updated_entries'] ?? 0) > 0;

		return [
			'ok' => true,
			'done' => $done,
			'made_progress' => $madeProgress,
			'stats' => $stats,
			'snapshot' => $snapshot,
		];
	}

	/**
	 * @return array{max_runtime_seconds:float,max_processed_items:int}
	 */
	private function manualQueueSliceOptions(): array {
		return [
			'max_runtime_seconds' => 2.5,
			'max_processed_items' => 200,
			'source' => 'manualQueue',
		];
	}

	/**
	 * @param array<string,mixed> $run
	 * @return array<string,mixed>
	 */
	private function manualQueuePayload(array $run): array {
		$run = $this->extension->queueStore()->saveManualRun($run);
		return [
			'ok' => true,
			'run_id' => (string)($run['run_id'] ?? ''),
			'status' => (string)($run['status'] ?? 'idle'),
			'snapshot' => is_array($run['last_snapshot'] ?? null) ? $run['last_snapshot'] : $this->extension->queueStore()->snapshot(),
			'initial_total' => (int)($run['initial_total'] ?? 0),
			'processed_total' => (int)($run['processed_total'] ?? 0),
			'progress_percent' => (int)($run['progress_percent'] ?? 0),
			'error' => trim((string)($run['error'] ?? '')),
		];
	}

	private function supportsAsyncManualQueue(): bool {
		return function_exists('fastcgi_finish_request');
	}

	private function handleStartManualQueue(): void {
		$this->requireUser();
		if (!Minz_Request::isPost() && !$this->isGetRequest()) {
			Minz_Error::error(405);
			return;
		}

		try {
			$run = $this->extension->queueStore()->manualRun();
			$alreadyRunning = ($run['status'] ?? 'idle') === 'running' && trim((string)($run['run_id'] ?? '')) !== '';
			if (!$alreadyRunning) {
				$run = $this->extension->queueStore()->startManualRun($this->extension->queueStore()->snapshot());
			}

			$payload = $this->manualQueuePayload($run);
			if ($alreadyRunning) {
				$this->renderJson($payload);
				return;
			}
			if (!$this->supportsAsyncManualQueue() || ($payload['status'] ?? 'idle') !== 'running') {
				$this->renderJson($payload);
				return;
			}

			$this->sendImmediateJsonResponse($payload);
			$this->runManualQueueLoop((string)$payload['run_id']);
			exit;
		} catch (Throwable $throwable) {
			$this->renderJson($this->queueErrorResponse('startManualQueue', $throwable));
		}
	}

	private function handleManualQueueStatus(): void {
		$this->requireUser();
		if (!Minz_Request::isPost() && !$this->isGetRequest()) {
			Minz_Error::error(405);
			return;
		}

		try {
			$run = $this->extension->queueStore()->manualRun();
			$snapshot = $this->extension->queueStore()->snapshot();
			$canResume = trim((string)($run['run_id'] ?? '')) !== ''
				&& ($run['status'] ?? 'idle') === 'idle'
				&& !$this->queueIsDone($snapshot);
			if ($canResume) {
				$run['status'] = 'running';
				$run['updated_at'] = date(DATE_ATOM);
				$run['last_snapshot'] = $snapshot;
				$run = $this->extension->queueStore()->saveManualRun($run);
				$payload = $this->manualQueuePayload($run);
				if ($this->supportsAsyncManualQueue()) {
					$this->sendImmediateJsonResponse($payload);
					$this->runManualQueueLoop((string)($run['run_id'] ?? ''));
					exit;
				}
			}

			if (($run['status'] ?? 'idle') === 'running' && trim((string)($run['run_id'] ?? '')) !== '' && !$this->supportsAsyncManualQueue()) {
				$run = $this->advanceManualQueueRun((string)$run['run_id']);
			}

			$this->renderJson($this->manualQueuePayload($run));
		} catch (Throwable $throwable) {
			$this->renderJson($this->queueErrorResponse('manualQueueStatus', $throwable));
		}
	}

	private function runManualQueueLoop(string $runId): void {
		if ($runId === '') {
			return;
		}

		@ignore_user_abort(true);
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		while (true) {
			$run = $this->extension->queueStore()->manualRun();
			if (($run['run_id'] ?? '') !== $runId || ($run['status'] ?? 'idle') !== 'running') {
				return;
			}

			$run = $this->advanceManualQueueRun($runId);
			if (($run['status'] ?? 'idle') !== 'running') {
				return;
			}

			sleep(5);
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function advanceManualQueueRun(string $runId): array {
		$run = $this->extension->queueStore()->manualRun();
		if (($run['run_id'] ?? '') !== $runId || ($run['status'] ?? 'idle') !== 'running') {
			return $run;
		}

		try {
			$this->extension->queueProcessor()->process($this->manualQueueSliceOptions());
			$snapshot = $this->extension->queueStore()->snapshot();
			$run['status'] = $this->queueIsDone($snapshot) ? 'completed' : 'running';
			$run['updated_at'] = date(DATE_ATOM);
			$run['last_snapshot'] = $snapshot;
			$run['error'] = '';
			return $this->extension->queueStore()->saveManualRun($run);
		} catch (Throwable $throwable) {
			$run['status'] = 'error';
			$run['updated_at'] = date(DATE_ATOM);
			$run['last_snapshot'] = $this->extension->queueStore()->snapshot();
			$run['error'] = $throwable->getMessage();
			$this->extension->diagnostics()->append([
				'type' => 'queue_run_error',
				'source' => 'manualQueueRun',
				'message' => $throwable->getMessage(),
				'exception' => get_class($throwable),
			]);
			return $this->extension->queueStore()->saveManualRun($run);
		}
	}

	/**
	 * @param array<string,mixed> $snapshot
	 */
	private function queueIsDone(array $snapshot): bool {
		return (int)($snapshot['pending_entries'] ?? 0) === 0
			&& (int)($snapshot['pending_backfills'] ?? 0) === 0
			&& (int)($snapshot['pending_backfill_entries'] ?? 0) === 0;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function sendImmediateJsonResponse(array $payload): void {
		$json = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$this->view()->_layout(null);
		header('Content-Type: application/json; charset=UTF-8');
		header('Cache-Control: no-store, no-cache, must-revalidate');
		header('Connection: close');
		echo $json;
		while (ob_get_level() > 0) {
			@ob_end_flush();
		}
		@flush();
		if (function_exists('session_write_close')) {
			@session_write_close();
		}
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function renderJson(array $payload): void {
		$this->view()->_layout(null);
		header('Content-Type: application/json; charset=UTF-8');
		AutoLabelViewState::replace([
			'json' => (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		]);
		$this->view()->_path('autolabel/json.phtml');
	}

	private function isValidQueueCronToken(string $token): bool {
		if ($token === '') {
			return false;
		}

		return hash_equals($this->extension->queueCronToken(), $token);
	}

	private function isAjaxRequest(): bool {
		$requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
		if (is_string($requestedWith) && strcasecmp($requestedWith, 'XMLHttpRequest') === 0) {
			return true;
		}

		$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
		return is_string($accept) && stripos($accept, 'application/json') !== false;
	}

	private function isGetRequest(): bool {
		if (method_exists('Minz_Request', 'isGet')) {
			return Minz_Request::isGet();
		}

		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		return is_string($method) && strtoupper($method) === 'GET';
	}

	private function queueErrorResponse(string $source, Throwable $throwable): array {
		$this->extension->diagnostics()->append([
			'type' => 'queue_run_error',
			'source' => $source,
			'message' => $throwable->getMessage(),
			'exception' => get_class($throwable),
		]);

		return [
			'ok' => false,
			'done' => false,
			'made_progress' => false,
			'error' => $throwable->getMessage(),
			'snapshot' => $this->extension->queueStore()->snapshot(),
		];
	}
}
