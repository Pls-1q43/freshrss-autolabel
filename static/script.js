document.addEventListener('DOMContentLoaded', () => {
  const profileForm = document.querySelector('[data-autolabel-profile-form]');
  if (profileForm) {
    const providerSelect = profileForm.querySelector('[data-autolabel-provider]');
    const baseUrlInput = profileForm.querySelector('input[name="base_url"]');
    const profileModeSelect = profileForm.querySelector('[data-autolabel-profile-mode]');
    const llmPanel = profileForm.querySelector('[data-autolabel-profile-panel="llm"]');
    const embeddingPanel = profileForm.querySelector('[data-autolabel-profile-panel="embedding"]');
    const providerDefaults = {
      openai: 'https://api.openai.com',
      anthropic: 'https://api.anthropic.com',
      gemini: 'https://generativelanguage.googleapis.com',
      ollama: 'http://127.0.0.1:11434',
    };
    let previousProvider = providerSelect?.value ?? '';

    const applyProfileMode = () => {
      if (!profileModeSelect || !llmPanel || !embeddingPanel) {
        return;
      }

      llmPanel.hidden = profileModeSelect.value !== 'llm';
      embeddingPanel.hidden = profileModeSelect.value !== 'embedding';
    };

    const applyProviderCapabilities = () => {
      if (!providerSelect || !profileModeSelect) {
        return;
      }

      const embeddingOption = Array.from(profileModeSelect.options).find((option) => option.value === 'embedding');
      const isAnthropic = providerSelect.value === 'anthropic';
      if (embeddingOption) {
        embeddingOption.disabled = isAnthropic;
      }
      if (isAnthropic && profileModeSelect.value === 'embedding') {
        profileModeSelect.value = 'llm';
      }
      applyProfileMode();
    };

    const syncBaseUrlForProviderChange = () => {
      if (!providerSelect || !baseUrlInput) {
        return;
      }

      const nextProvider = providerSelect.value;
      if (nextProvider === previousProvider) {
        return;
      }

      const currentValue = baseUrlInput.value.trim();
      const previousDefault = providerDefaults[previousProvider] ?? '';
      const nextDefault = providerDefaults[nextProvider] ?? '';
      const canReplace = currentValue === '' || currentValue === previousDefault;

      if (nextProvider === 'ollama' && nextDefault !== '') {
        baseUrlInput.value = nextDefault;
      } else if (canReplace && nextDefault !== '') {
        baseUrlInput.value = nextDefault;
      }

      previousProvider = nextProvider;
    };

    const syncProvider = () => {
      if (!providerSelect) {
        return;
      }

      applyProviderCapabilities();
      syncBaseUrlForProviderChange();
    };

    providerSelect?.addEventListener('change', syncProvider);
    profileModeSelect?.addEventListener('change', applyProfileMode);
    applyProviderCapabilities();
    applyProfileMode();
    if (providerSelect) {
      previousProvider = providerSelect.value;
    }
  }

  const ruleForm = document.querySelector('[data-autolabel-rule-form]');
  if (ruleForm) {
    const profileSelect = ruleForm.querySelector('[data-autolabel-profile-select]');
    const modeSelect = ruleForm.querySelector('[data-autolabel-mode-select]');
    const llmPanel = ruleForm.querySelector('[data-autolabel-mode-panel="llm"]');
    const embeddingPanel = ruleForm.querySelector('[data-autolabel-mode-panel="embedding"]');

    const syncRuleForm = () => {
      if (!profileSelect || !modeSelect || !llmPanel || !embeddingPanel) {
        return;
      }

      const selectedOption = profileSelect.selectedOptions[0];
      const supportsLlm = selectedOption?.dataset.supportsLlm === '1';
      const supportsEmbedding = selectedOption?.dataset.supportsEmbedding === '1';

      for (const option of modeSelect.options) {
        const mode = option.value;
        const supported = (mode === 'llm' && supportsLlm) || (mode === 'embedding' && supportsEmbedding);
        option.disabled = !supported;
      }

      if (modeSelect.selectedOptions[0]?.disabled) {
        if (supportsLlm) {
          modeSelect.value = 'llm';
        } else if (supportsEmbedding) {
          modeSelect.value = 'embedding';
        }
      }

      llmPanel.hidden = modeSelect.value !== 'llm';
      embeddingPanel.hidden = modeSelect.value !== 'embedding';
    };

    profileSelect?.addEventListener('change', syncRuleForm);
    modeSelect?.addEventListener('change', syncRuleForm);
    syncRuleForm();
  }

  const queueForm = document.querySelector('.autolabel-card form[action*="processQueue"]');
  if (queueForm instanceof HTMLFormElement) {
    const button = queueForm.querySelector('[data-autolabel-queue-button]');
    const status = document.querySelector('[data-autolabel-queue-status]');
    const pendingEntries = document.querySelector('[data-autolabel-queue-pending-entries]');
    const pendingBackfills = document.querySelector('[data-autolabel-queue-pending-backfills]');
    const pendingBackfillEntries = document.querySelector('[data-autolabel-queue-pending-backfill-entries]');
    const lastRun = document.querySelector('[data-autolabel-queue-last-run]');
    const progress = document.querySelector('[data-autolabel-queue-progress]');
    const progressBar = document.querySelector('[data-autolabel-queue-progress-bar]');
    const progressText = document.querySelector('[data-autolabel-queue-progress-text]');
    const startUrlInput = queueForm.querySelector('input[name="queue_manual_start_url"]');
    const statusUrlInput = queueForm.querySelector('input[name="queue_manual_status_url"]');
    const runIdInput = queueForm.querySelector('input[name="queue_manual_run_id"]');
    const runStatusInput = queueForm.querySelector('input[name="queue_manual_status"]');
    const runInitialTotalInput = queueForm.querySelector('input[name="queue_manual_initial_total"]');
    let running = false;
    let initialWorkTotal = 0;
    let currentRunId = '';
    let lastKnownTotal = 0;
    let lastProgressAt = 0;
    let transientFailures = 0;

    const sleep = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

    const setStatus = (text) => {
      if (status) {
        status.textContent = text;
      }
    };

    const queueErrorText = (data) => {
      const fallback = button?.dataset.failedLabel ?? 'Queue processing failed.';
      if (!data || typeof data.error !== 'string' || data.error.trim() === '') {
        return fallback;
      }

      return `${fallback} ${data.error.trim()}`;
    };

    const rawErrorText = (raw) => {
      const fallback = button?.dataset.failedLabel ?? 'Queue processing failed.';
      if (typeof raw !== 'string') {
        return fallback;
      }

      const snippet = raw.replace(/\s+/g, ' ').trim().slice(0, 180);
      return snippet === '' ? fallback : `${fallback} ${snippet}`;
    };

    const parseJsonResponse = async (response) => {
      const raw = await response.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch (error) {
        console.error('AutoLabel queue response was not valid JSON', raw);
        throw new Error(rawErrorText(raw));
      }

      if (!response.ok) {
        console.error('AutoLabel queue request failed', response.status, data);
        throw new Error(queueErrorText(data));
      }

      if (data.ok === false) {
        throw new Error(queueErrorText(data));
      }

      return data;
    };

    const updateSnapshot = (snapshot) => {
      if (!snapshot || typeof snapshot !== 'object') {
        return;
      }

      if (pendingEntries) {
        pendingEntries.textContent = String(snapshot.pending_entries ?? '0');
      }
      if (pendingBackfills) {
        pendingBackfills.textContent = String(snapshot.pending_backfills ?? '0');
      }
      if (pendingBackfillEntries) {
        pendingBackfillEntries.textContent = String(snapshot.pending_backfill_entries ?? '0');
      }
      if (lastRun) {
        lastRun.textContent = String(snapshot.last_run?.at ?? '');
      }

      updateProgress(snapshot);
    };

    const totalWorkFromSnapshot = (snapshot) => {
      const pendingEntryCount = Number.parseInt(String(snapshot?.pending_entries ?? '0'), 10) || 0;
      const pendingBackfillEntryCount = Number.parseInt(String(snapshot?.pending_backfill_entries ?? '0'), 10) || 0;
      return Math.max(0, pendingEntryCount + pendingBackfillEntryCount);
    };

    const currentSnapshotFromDom = () => ({
      pending_entries: pendingEntries?.textContent ?? '0',
      pending_backfill_entries: pendingBackfillEntries?.textContent ?? '0',
    });

    const setProgressVisibility = (visible) => {
      if (progress instanceof HTMLElement) {
        progress.hidden = !visible;
      }
      if (progressText instanceof HTMLElement) {
        progressText.hidden = !visible;
      }
    };

    const updateProgress = (snapshot) => {
      if (!(progressBar instanceof HTMLElement) || !(progressText instanceof HTMLElement)) {
        return;
      }

      if (!running || initialWorkTotal <= 0) {
        progressBar.style.width = '0%';
        progressText.textContent = '';
        setProgressVisibility(false);
        return;
      }

      const remaining = totalWorkFromSnapshot(snapshot);
      const completed = Math.max(0, initialWorkTotal - Math.min(initialWorkTotal, remaining));
      const ratio = initialWorkTotal <= 0 ? 0 : completed / initialWorkTotal;
      const percent = Math.max(0, Math.min(100, Math.round(ratio * 100)));
      progressBar.style.width = `${percent}%`;
      progressText.textContent = `${percent}% · ${completed} / ${initialWorkTotal}`;
      setProgressVisibility(true);
    };

    const syncProgressSignals = (snapshot) => {
      const total = totalWorkFromSnapshot(snapshot);
      if (lastKnownTotal === 0 || total < lastKnownTotal) {
        lastProgressAt = Date.now();
      }
      lastKnownTotal = total;
    };

    const buildStatusUrl = () => {
      const formAction = typeof queueForm.action === 'string' ? queueForm.action.trim() : '';
      const base = formAction !== ''
        ? `${formAction}${formAction.includes('?') ? '&' : '?'}manual_queue_mode=status`
        : (statusUrlInput instanceof HTMLInputElement ? statusUrlInput.value.trim() : '');
      if (base === '') {
        return '';
      }

      if (currentRunId === '') {
        return base;
      }

      const separator = base.includes('?') ? '&' : '?';
      return `${base}${separator}run_id=${encodeURIComponent(currentRunId)}`;
    };

    const shouldFailAfterTransientErrors = () => transientFailures >= 6 && (Date.now() - lastProgressAt > 30000);

    const syncManualRunInputs = (data) => {
      if (runIdInput instanceof HTMLInputElement && typeof data.run_id === 'string') {
        runIdInput.value = data.run_id;
      }
      if (runStatusInput instanceof HTMLInputElement && typeof data.status === 'string') {
        runStatusInput.value = data.status;
      }
      if (runInitialTotalInput instanceof HTMLInputElement && Number.isFinite(Number(data.initial_total))) {
        runInitialTotalInput.value = String(Number(data.initial_total));
      }
    };

    const resolveStartUrl = () => {
      const formAction = typeof queueForm.action === 'string' ? queueForm.action.trim() : '';
      return formAction !== ''
        ? `${formAction}${formAction.includes('?') ? '&' : '?'}manual_queue_mode=start`
        : (startUrlInput instanceof HTMLInputElement ? startUrlInput.value.trim() : '');
    };

    const startManualRunRequest = async () => {
      const startUrl = resolveStartUrl();
      if (startUrl === '') {
        throw new Error(button?.dataset.failedLabel ?? 'Queue processing failed.');
      }

      const response = await fetch(startUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        body: new FormData(queueForm),
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
        },
      });
      const data = await parseJsonResponse(response);
      syncManualRunInputs(data);
      currentRunId = typeof data.run_id === 'string' ? data.run_id : '';
      updateSnapshot(data.snapshot ?? {});
      if (Number.isFinite(Number(data.initial_total))) {
        initialWorkTotal = Number(data.initial_total);
      } else if (initialWorkTotal <= 0) {
        initialWorkTotal = totalWorkFromSnapshot(data.snapshot ?? currentSnapshotFromDom());
      }
      syncProgressSignals(data.snapshot ?? currentSnapshotFromDom());
      updateProgress(data.snapshot ?? currentSnapshotFromDom());
      return data;
    };

    const pollManualRun = async () => {
      while (true) {
        await sleep(5000);
        const statusUrl = buildStatusUrl();
        if (statusUrl === '') {
          throw new Error(button?.dataset.failedLabel ?? 'Queue processing failed.');
        }

        try {
          const response = await fetch(statusUrl, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json',
            },
          });
          const data = await parseJsonResponse(response);
          transientFailures = 0;
          syncManualRunInputs(data);
          if (typeof data.run_id === 'string' && data.run_id !== '') {
            currentRunId = data.run_id;
          }
          updateSnapshot(data.snapshot ?? {});
          syncProgressSignals(data.snapshot ?? currentSnapshotFromDom());

          if (data.status === 'completed') {
            setStatus(button?.dataset.completedLabel ?? 'Queue completed.');
            return;
          }

          if (data.status === 'error') {
            throw new Error(queueErrorText(data));
          }

          if (data.status === 'running') {
            setStatus(button?.dataset.processingBackgroundLabel ?? button?.dataset.processingLabel ?? 'Processing queue...');
            continue;
          }

          if (totalWorkFromSnapshot(data.snapshot ?? {}) === 0) {
            setStatus(button?.dataset.completedLabel ?? 'Queue completed.');
            return;
          }

          if (data.status === 'idle') {
            setStatus(button?.dataset.processingContinuingLabel ?? button?.dataset.processingBackgroundLabel ?? 'The request ended, but background processing is still continuing...');
            const restarted = await startManualRunRequest();
            if (restarted.status === 'completed') {
              setStatus(button?.dataset.completedLabel ?? 'Queue completed.');
              return;
            }
            if (restarted.status === 'error') {
              throw new Error(queueErrorText(restarted));
            }
            setStatus(button?.dataset.processingBackgroundLabel ?? button?.dataset.processingLabel ?? 'Processing queue...');
            continue;
          }

          setStatus(button?.dataset.stalledLabel ?? 'Queue paused because there was no progress.');
          return;
        } catch (error) {
          transientFailures += 1;
          setStatus(button?.dataset.processingContinuingLabel ?? button?.dataset.processingBackgroundLabel ?? 'The request ended, but background processing is still continuing...');
          if (shouldFailAfterTransientErrors()) {
            throw error instanceof Error ? error : new Error(button?.dataset.failedLabel ?? 'Queue processing failed.');
          }
        }
      }
    };

    const runQueue = async () => {
      if (running || !(button instanceof HTMLButtonElement)) {
        return;
      }

      running = true;
      button.disabled = true;
      currentRunId = runIdInput instanceof HTMLInputElement ? runIdInput.value.trim() : '';
      initialWorkTotal = totalWorkFromSnapshot(currentSnapshotFromDom());
      lastKnownTotal = initialWorkTotal;
      lastProgressAt = Date.now();
      transientFailures = 0;
      updateProgress(currentSnapshotFromDom());
      try {
        setStatus(button.dataset.processingLabel ?? 'Processing queue...');
        const data = await startManualRunRequest();

        if (data.status === 'completed') {
          setStatus(button.dataset.completedLabel ?? 'Queue completed.');
          return;
        }

        if (data.status === 'error') {
          throw new Error(queueErrorText(data));
        }

        setStatus(button.dataset.processingBackgroundLabel ?? button.dataset.processingLabel ?? 'Processing queue...');
        await pollManualRun();
      } catch (error) {
        setStatus(error instanceof Error && error.message ? error.message : (button.dataset.failedLabel ?? 'Queue processing failed.'));
      } finally {
        running = false;
        initialWorkTotal = 0;
        updateProgress(currentSnapshotFromDom());
        button.disabled = false;
      }
    };

    queueForm.addEventListener('submit', (event) => {
      event.preventDefault();
      runQueue();
    });

    const initialRunId = runIdInput instanceof HTMLInputElement ? runIdInput.value.trim() : '';
    const initialRunStatus = runStatusInput instanceof HTMLInputElement ? runStatusInput.value.trim() : '';
    const initialRunTotal = runInitialTotalInput instanceof HTMLInputElement ? Number(runInitialTotalInput.value) : 0;
    if (initialRunId !== '' && initialRunStatus === 'running' && button instanceof HTMLButtonElement) {
      currentRunId = initialRunId;
      initialWorkTotal = Number.isFinite(initialRunTotal) ? initialRunTotal : totalWorkFromSnapshot(currentSnapshotFromDom());
      lastKnownTotal = totalWorkFromSnapshot(currentSnapshotFromDom());
      lastProgressAt = Date.now();
      running = true;
      button.disabled = true;
      updateProgress(currentSnapshotFromDom());
      setStatus(button.dataset.processingBackgroundLabel ?? button.dataset.processingLabel ?? 'Processing queue...');
      pollManualRun()
        .catch((error) => {
          setStatus(error instanceof Error && error.message ? error.message : (button.dataset.failedLabel ?? 'Queue processing failed.'));
        })
        .finally(() => {
          running = false;
          initialWorkTotal = 0;
          updateProgress(currentSnapshotFromDom());
          button.disabled = false;
        });
    }
  }
});
