/**
 * JS for multisite mode switching and table clone operations.
 * Uses a JS-driven AJAX batch runner — one site per request, sequential.
 *
 * Only enqueued when is_multisite() is true.
 *
 * All HTML is rendered server-side in clone-status.php.
 * This JS only shows/hides elements and updates text/classes.
 * Translatable strings come from IawmlfCloneTemplates (localised via PHP).
 *
 * @since 1.4.0
 */
(function () {
	"use strict";

	document.addEventListener('DOMContentLoaded', function () {

		var T = typeof IawmlfCloneTemplates !== 'undefined' ? IawmlfCloneTemplates : {};

		var multisiteModeSelect = document.getElementById('iawmlf_multisite_links_table_mode');
		var cloneConfig         = document.getElementById('iawmlf-clone-config');
		var cloneSitesContainer = document.getElementById('iawmlf-clone-sites');
		var cloneConfirmCheckbox = document.getElementById('iawmlf-clone-confirm');
		var cloneStartButton    = document.getElementById('iawmlf-clone-start');
		var cloneCancelButton   = document.getElementById('iawmlf-clone-cancel');
		var cloneSpinner        = document.getElementById('iawmlf-clone-spinner');
		var cloneMessage        = document.getElementById('iawmlf-clone-message');
		var progressContainer   = document.getElementById('iawmlf_multisite_progress');

		var cloneInProgress = false;
		var skippedSites    = [];

		if (!multisiteModeSelect || !cloneConfig) {
			return;
		}

		// ── Helpers ──────────────────────────────────────────────────────

		/**
		 * Simple sprintf replacement for localised strings.
		 * Replaces %1$s, %2$s, %1$d, %2$d, %3$s etc with args in order.
		 */
		function sprintf(template) {
			var args = Array.prototype.slice.call(arguments, 1);
			var i = 0;
			return template.replace(/%\d+\$[sd]/g, function () {
				return args[i++] !== undefined ? args[i - 1] : '';
			});
		}

		function show(el) { if (el) el.style.display = ''; }
		function hide(el) { if (el) el.style.display = 'none'; }

		// ── Site badge helpers ───────────────────────────────────────────

		function showSelectedSiteBadges(selectedSiteIds) {
			var badges = document.querySelectorAll('#iawmlf-sites-list .iawmlf-site-badge');
			badges.forEach(function (badge) {
				var id = badge.getAttribute('data-site-id');
				if (selectedSiteIds.indexOf(id) !== -1 || selectedSiteIds.indexOf(Number(id)) !== -1) {
					show(badge);
					badge.className = 'iawmlf-site-badge iawmlf-site-pending';
					var icon = badge.querySelector('.dashicons');
					if (icon) icon.className = 'dashicons dashicons-clock';
				} else {
					hide(badge);
				}
			});
		}

		function updateSiteBadge(siteId, state) {
			var badge = document.querySelector('[data-site-id="' + siteId + '"]');
			if (!badge) return;

			var icon = badge.querySelector('.dashicons');
			badge.className = 'iawmlf-site-badge';

			if (state === 'completed') {
				badge.classList.add('iawmlf-site-completed');
				if (icon) icon.className = 'dashicons dashicons-yes';
			} else if (state === 'skipped') {
				badge.classList.add('iawmlf-site-error');
				if (icon) icon.className = 'dashicons dashicons-warning';
			} else if (state === 'processing') {
				badge.classList.add('iawmlf-site-processing');
				if (icon) icon.className = 'dashicons dashicons-update iawmlf-spin';
			}
		}

		// ── Progress ────────────────────────────────────────────────────

		function updateProgress(completed, total) {
			var percent = total > 0 ? (completed / total * 100) : 0;
			var fillEl = document.getElementById('iawmlf-progress-fill');
			var textEl = document.getElementById('iawmlf-progress-text');

			if (fillEl) fillEl.style.width = percent.toFixed(1) + '%';
			if (textEl) textEl.textContent = sprintf(
				T.progressText || '%1$d of %2$d sites completed (%3$s%%)',
				completed, total, percent.toFixed(1)
			);
		}

		// ── Log entries ─────────────────────────────────────────────────

		function appendLog(message, status) {
			var container = document.getElementById('iawmlf-log-entries');
			var countEl   = document.getElementById('iawmlf-log-count');
			if (!container) return;

			var div = document.createElement('div');
			div.className = 'iawmlf-log-entry iawmlf-log-' + (status || 'info');
			div.textContent = message;
			container.appendChild(div);

			if (countEl) {
				var count = container.querySelectorAll('.iawmlf-log-entry').length;
				countEl.textContent = '(' + count + ')';
			}
		}

		function appendServerLog(logEntries) {
			if (!logEntries || !logEntries.length) return;
			logEntries.forEach(function (entry) {
				appendLog(entry.message || entry, entry.status || 'info');
			});
		}

		// ── Status / Footer switching ───────────────────────────────────

		function setStatus(status) {
			var badge     = document.getElementById('iawmlf-status-badge');
			var icon      = document.getElementById('iawmlf-status-icon');
			var label     = document.getElementById('iawmlf-status-label');
			var progInfo  = document.getElementById('iawmlf-progress-info');

			// Footer sections
			var footerRunning   = document.getElementById('iawmlf-footer-running');
			var footerResume    = document.getElementById('iawmlf-footer-resume');
			var footerCompleted = document.getElementById('iawmlf-footer-completed');
			var footerError     = document.getElementById('iawmlf-footer-error');

			// Hide all footers.
			hide(footerRunning);
			hide(footerResume);
			hide(footerCompleted);
			hide(footerError);

			if (badge) badge.className = 'iawmlf-status-badge iawmlf-status-' + status;
			if (progressContainer) progressContainer.className = 'iawmlf-progress-message-' + status;

			if (status === 'running') {
				if (icon) icon.className = 'dashicons dashicons-update';
				if (label) label.textContent = T.progressText ? '' : 'In Progress';
				show(progInfo);
				show(footerRunning);
			} else if (status === 'completed') {
				if (icon) icon.className = 'dashicons dashicons-yes-alt';
				if (label) label.textContent = T.completedLabel || 'Completed';
				show(footerCompleted);
			} else if (status === 'error') {
				if (icon) icon.className = 'dashicons dashicons-warning';
				if (label) label.textContent = T.completedErrors || 'Completed with errors';
				show(footerError);
				buildSkippedSummary();
			}
		}

		function buildSkippedSummary() {
			var summaryEl = document.getElementById('iawmlf-skipped-summary');
			if (!summaryEl || skippedSites.length === 0) return;

			var heading = sprintf(T.skippedHeading || 'Skipped %1$d site(s) due to errors:', skippedSites.length);
			var html = '<strong>' + heading + '</strong><ul>';
			skippedSites.forEach(function (s) {
				var item = sprintf(T.skippedSiteItem || 'Site %1$s: %2$s', s.id, s.message);
				html += '<li>' + item + '</li>';
			});
			html += '</ul>';
			summaryEl.innerHTML = html;
			show(summaryEl);
		}

		// ── Options display ─────────────────────────────────────────────

		function updateOptionsDisplay(options) {
			var optionEls = document.querySelectorAll('#iawmlf-clone-options [data-option]');
			optionEls.forEach(function (el) {
				var key  = el.getAttribute('data-option');
				var on   = options[key] === '1';
				var iconEl = el.querySelector('.iawmlf-option-icon');

				el.className = on ? 'iawmlf-option-enabled' : 'iawmlf-option-disabled';
				el.setAttribute('data-option', key);
				if (iconEl) iconEl.textContent = on ? '✓ ' : '✗ ';
			});
		}

		// ── UI state helpers ────────────────────────────────────────────

		function setMultisiteFieldsDisabled(disabled) {
			if (multisiteModeSelect) multisiteModeSelect.disabled = disabled;

			var container = document.querySelector('.iawmlf_settings_sites_checkboxes');
			if (container) {
				container.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
					cb.disabled = disabled;
				});
				if (disabled) {
					container.classList.add('iawmlf-disabled');
				} else {
					container.classList.remove('iawmlf-disabled');
				}
			}
		}

		function setConfigDisabled(disabled) {
			cloneConfig.querySelectorAll('input, button').forEach(function (input) {
				input.disabled = disabled;
			});
		}

		function resetCloneUI() {
			if (cloneSpinner) cloneSpinner.classList.remove('is-active');
			if (cloneMessage) {
				hide(cloneMessage);
				cloneMessage.className = '';
				cloneMessage.textContent = '';
			}
			setConfigDisabled(false);
			skippedSites = [];
		}

		function showCloneMessage(message, type) {
			if (!cloneMessage) return;
			cloneMessage.textContent = message;
			show(cloneMessage);
			if (type === 'success') {
				cloneMessage.style.background = '#d4edda';
				cloneMessage.style.borderLeft = '4px solid #46b450';
				cloneMessage.style.color = '#155724';
			} else {
				cloneMessage.style.background = '#f8d7da';
				cloneMessage.style.borderLeft = '4px solid #dc3232';
				cloneMessage.style.color = '#721c24';
			}
		}

		function populateSitesCheckboxes() {
			if (!cloneSitesContainer || !IawmlfCloneSettings.multisiteSites) return;

			cloneSitesContainer.innerHTML = '';
			IawmlfCloneSettings.multisiteSites.forEach(function (site) {
				var label = document.createElement('label');
				label.className = 'iawmlf-site-checkbox';
				label.innerHTML = '<input type="checkbox" name="iawmlf_clone_sites[]" value="' + site.id + '" checked /> ' + site.name;
				cloneSitesContainer.appendChild(label);
			});
		}

		// ── Log toggle ──────────────────────────────────────────────────

		function attachLogToggle() {
			var toggle  = document.getElementById('iawmlf-logs-toggle');
			var entries = document.getElementById('iawmlf-log-entries');
			if (!toggle || !entries) return;

			toggle.addEventListener('click', function () {
				var toggleText = this.querySelector('.iawmlf-logs-toggle');
				var isVisible  = entries.style.display !== 'none';
				entries.style.display = isVisible ? 'none' : 'block';
				if (toggleText) toggleText.textContent = isVisible ? 'Show' : 'Hide';
			});
		}

		// ── Dismiss handlers ────────────────────────────────────────────

		function dismissCloneState() {
			var formData = new FormData();
			formData.append('action', IawmlfCloneSettings.cloneDismissAction);
			formData.append('nonce', IawmlfCloneSettings.cloneDismissNonce);

			fetch(IawmlfCloneSettings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			})
			.then(function () {
				window.location.reload();
			})
			.catch(function () {
				window.location.reload();
			});
		}

		function attachDismissHandlers() {
			var dismissCompleted = document.getElementById('iawmlf-dismiss-clone');
			var dismissError     = document.getElementById('iawmlf-dismiss-clone-error');

			if (dismissCompleted) {
				dismissCompleted.addEventListener('click', function () {
					dismissCloneState();
				});
			}
			if (dismissError) {
				dismissError.addEventListener('click', function () {
					dismissCloneState();
				});
			}
		}

		// ── Clone process ───────────────────────────────────────────────

		function startCloneProcess() {
			var siteCheckboxes = cloneSitesContainer.querySelectorAll('input[type="checkbox"]:checked');
			var selectedSites  = Array.from(siteCheckboxes).map(function (cb) { return cb.value; });

			if (selectedSites.length === 0) {
				showCloneMessage('Please select at least one site.', 'error');
				return;
			}

			var resetChecks   = document.getElementById('iawmlf-clone-reset-checks');
			var resetSource   = document.getElementById('iawmlf-clone-reset-source');
			var shareSettings = document.getElementById('iawmlf-clone-share-settings');

			var cloneOptions = {
				reset_checks:   resetChecks && resetChecks.checked ? '1' : '0',
				reset_source:   resetSource && resetSource.checked ? '1' : '0',
				share_settings: shareSettings && shareSettings.checked ? '1' : '0'
			};

			if (cloneSpinner) cloneSpinner.classList.add('is-active');
			setConfigDisabled(true);
			cloneInProgress = true;
			skippedSites = [];
			if (multisiteModeSelect) multisiteModeSelect.disabled = true;

			// Step 1: Create state via Clone_Start_Ajax.
			var formData = new FormData();
			formData.append('action', IawmlfCloneSettings.cloneStartAction);
			formData.append('nonce', IawmlfCloneSettings.cloneStartNonce);
			formData.append('sites', JSON.stringify(selectedSites));
			formData.append('new_mode', multisiteModeSelect.value);
			formData.append('reset_checks', cloneOptions.reset_checks);
			formData.append('reset_source', cloneOptions.reset_source);
			formData.append('share_settings', cloneOptions.share_settings);

			fetch(IawmlfCloneSettings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			})
			.then(function (response) { return response.json(); })
			.then(function (data) {
				if (cloneSpinner) cloneSpinner.classList.remove('is-active');

				if (data.success) {
					// Hide config, show progress template.
					hide(cloneConfig);
					showSelectedSiteBadges(selectedSites);
					updateOptionsDisplay(cloneOptions);
					updateProgress(0, selectedSites.length);
					setStatus('running');
					show(progressContainer);

					// Step 2: Process sites sequentially.
					processNextSite(selectedSites, 0, selectedSites.length, cloneOptions, 0);
				} else {
					showCloneMessage(data.data.message || 'An error occurred.', 'error');
					setConfigDisabled(false);
					cloneInProgress = false;
					if (multisiteModeSelect) multisiteModeSelect.disabled = false;
				}
			})
			.catch(function (error) {
				if (cloneSpinner) cloneSpinner.classList.remove('is-active');
				showCloneMessage('Request failed: ' + error.message, 'error');
				setConfigDisabled(false);
				cloneInProgress = false;
				if (multisiteModeSelect) multisiteModeSelect.disabled = false;
			});
		}

		function processNextSite(sites, index, total, options, retryCount) {
			if (index >= sites.length) {
				updateProgress(total, total);
				var finalStatus = skippedSites.length > 0 ? 'error' : 'completed';
				appendLog(
					sprintf(T.finishedLog || 'Clone process finished. %1$d of %2$d sites completed.', total - skippedSites.length, total),
					'info'
				);
				setStatus(finalStatus);
				cloneInProgress = false;
				setMultisiteFieldsDisabled(false);
				if (finalStatus === 'completed') {
					multisiteModeSelect.dataset.originalValue = multisiteModeSelect.value;
				}
				return;
			}

			var siteId = sites[index];
			var isLast = (index === sites.length - 1);

			updateSiteBadge(siteId, 'processing');

			var formData = new FormData();
			formData.append('action', IawmlfCloneSettings.cloneProcessSiteAction);
			formData.append('nonce', IawmlfCloneSettings.cloneProcessSiteNonce);
			formData.append('site_id', siteId);
			formData.append('reset_checks', options.reset_checks);
			formData.append('reset_source', isLast ? options.reset_source : '0');

			fetch(IawmlfCloneSettings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			})
			.then(function (response) { return response.json(); })
			.then(function (data) {
				if (data.success) {
					updateSiteBadge(siteId, 'completed');
					updateProgress(index + 1, total);
					appendServerLog(data.data.log);
					processNextSite(sites, index + 1, total, options, 0);
				} else {
					var errorMsg = (data.data && data.data.message) ? data.data.message : 'Unknown error';
					appendServerLog(data.data && data.data.log);

					if (retryCount < 1) {
						appendLog(sprintf(T.retryingLog || 'Retrying site %1$s...', siteId), 'info');
						processNextSite(sites, index, total, options, retryCount + 1);
					} else {
						appendLog(sprintf(T.skippingLog || 'Skipping site %1$s after retry: %2$s', siteId, errorMsg), 'error');
						updateSiteBadge(siteId, 'skipped');
						updateProgress(index + 1, total);
						skippedSites.push({ id: siteId, message: errorMsg });
						processNextSite(sites, index + 1, total, options, 0);
					}
				}
			})
			.catch(function (error) {
				if (retryCount < 1) {
					appendLog(sprintf(T.requestFailed || 'Request failed for site %1$s, retrying...', siteId), 'info');
					processNextSite(sites, index, total, options, retryCount + 1);
				} else {
					appendLog(sprintf(T.skippingLog || 'Skipping site %1$s after retry: %2$s', siteId, error.message), 'error');
					updateSiteBadge(siteId, 'skipped');
					updateProgress(index + 1, total);
					skippedSites.push({ id: siteId, message: error.message });
					processNextSite(sites, index + 1, total, options, 0);
				}
			});
		}

		// ── Resume ──────────────────────────────────────────────────────

		function resumeCloneProcess() {
			if (!progressContainer) return;

			var pendingBadges  = progressContainer.querySelectorAll('.iawmlf-site-pending:not([style*="display: none"])');
			var remainingSites = Array.from(pendingBadges).map(function (b) { return b.getAttribute('data-site-id'); }).filter(Boolean);

			if (remainingSites.length === 0) {
				setStatus('completed');
				cloneInProgress = false;
				setMultisiteFieldsDisabled(false);
				return;
			}

			var allVisibleBadges = progressContainer.querySelectorAll('.iawmlf-site-badge:not([style*="display: none"])');
			var total       = allVisibleBadges.length;
			var alreadyDone = total - remainingSites.length;

			// Read options from the rendered option spans.
			var options = { reset_checks: '0', reset_source: '0', share_settings: '0' };
			var optionEls = progressContainer.querySelectorAll('.iawmlf-option-enabled');
			optionEls.forEach(function (el) {
				var key = el.getAttribute('data-option');
				if (key) options[key] = '1';
			});

			setStatus('running');
			updateProgress(alreadyDone, total);
			appendLog(sprintf(T.resumingLog || 'Resuming migration — %1$d site(s) remaining.', remainingSites.length), 'info');

			skippedSites = [];

			(function processResumeNext(rIndex, retries) {
				if (rIndex >= remainingSites.length) {
					updateProgress(total, total);
					var finalStatus = skippedSites.length > 0 ? 'error' : 'completed';
					appendLog(
						sprintf(T.finishedLog || 'Clone process finished. %1$d of %2$d sites completed.', total - skippedSites.length, total),
						'info'
					);
					setStatus(finalStatus);
					cloneInProgress = false;
					setMultisiteFieldsDisabled(false);
					if (finalStatus === 'completed') {
						multisiteModeSelect.dataset.originalValue = multisiteModeSelect.value;
					}
					return;
				}

				var siteId = remainingSites[rIndex];
				var isLast = (rIndex === remainingSites.length - 1);

				updateSiteBadge(siteId, 'processing');

				var formData = new FormData();
				formData.append('action', IawmlfCloneSettings.cloneProcessSiteAction);
				formData.append('nonce', IawmlfCloneSettings.cloneProcessSiteNonce);
				formData.append('site_id', siteId);
				formData.append('reset_checks', options.reset_checks);
				formData.append('reset_source', isLast ? options.reset_source : '0');

				fetch(IawmlfCloneSettings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				})
				.then(function (response) { return response.json(); })
				.then(function (data) {
					if (data.success) {
						updateSiteBadge(siteId, 'completed');
						updateProgress(alreadyDone + rIndex + 1, total);
						appendServerLog(data.data.log);
						processResumeNext(rIndex + 1, 0);
					} else {
						var errorMsg = (data.data && data.data.message) ? data.data.message : 'Unknown error';
						appendServerLog(data.data && data.data.log);

						if (retries < 1) {
							appendLog(sprintf(T.retryingLog || 'Retrying site %1$s...', siteId), 'info');
							processResumeNext(rIndex, retries + 1);
						} else {
							appendLog(sprintf(T.skippingLog || 'Skipping site %1$s after retry: %2$s', siteId, errorMsg), 'error');
							updateSiteBadge(siteId, 'skipped');
							updateProgress(alreadyDone + rIndex + 1, total);
							skippedSites.push({ id: siteId, message: errorMsg });
							processResumeNext(rIndex + 1, 0);
						}
					}
				})
				.catch(function (error) {
					if (retries < 1) {
						appendLog(sprintf(T.requestFailed || 'Request failed for site %1$s, retrying...', siteId), 'info');
						processResumeNext(rIndex, retries + 1);
					} else {
						appendLog(sprintf(T.skippingLog || 'Skipping site %1$s after retry: %2$s', siteId, error.message), 'error');
						updateSiteBadge(siteId, 'skipped');
						updateProgress(alreadyDone + rIndex + 1, total);
						skippedSites.push({ id: siteId, message: error.message });
						processResumeNext(rIndex + 1, 0);
					}
				});
			})(0, 0);
		}

		// ── Event listeners ─────────────────────────────────────────────

		multisiteModeSelect.addEventListener('change', function () {
			if (cloneInProgress) {
				this.value = this.dataset.originalValue;
				return;
			}

			if (this.value !== this.dataset.originalValue) {
				populateSitesCheckboxes();
				show(cloneConfig);
				// resetCloneUI() re-enables every config input, so it must run
				// BEFORE the confirm/start lockout or the Start button ends up
				// enabled without the confirmation checkbox being ticked.
				resetCloneUI();
				if (cloneConfirmCheckbox) cloneConfirmCheckbox.checked = false;
				if (cloneStartButton) cloneStartButton.disabled = true;
				setMultisiteFieldsDisabled(true);
			} else {
				hide(cloneConfig);
				resetCloneUI();
				setMultisiteFieldsDisabled(false);
			}
		});

		if (cloneConfirmCheckbox && cloneStartButton) {
			cloneConfirmCheckbox.addEventListener('change', function () {
				cloneStartButton.disabled = !this.checked;
			});
		}

		if (cloneCancelButton) {
			cloneCancelButton.addEventListener('click', function () {
				multisiteModeSelect.value = multisiteModeSelect.dataset.originalValue;
				hide(cloneConfig);
				resetCloneUI();
				setMultisiteFieldsDisabled(false);
			});
		}

		if (cloneStartButton) {
			cloneStartButton.addEventListener('click', function () {
				startCloneProcess();
			});
		}

		// ── Initialization ──────────────────────────────────────────────

		attachLogToggle();
		attachDismissHandlers();

		// Check for interrupted migration on page load.
		if (progressContainer && progressContainer.classList.contains('iawmlf-progress-message-running')) {
			cloneInProgress = true;
			setMultisiteFieldsDisabled(true);

			// Show resume footer instead of running footer.
			hide(document.getElementById('iawmlf-footer-running'));
			show(document.getElementById('iawmlf-footer-resume'));

			var resumeBtn = document.getElementById('iawmlf-resume-clone');
			if (resumeBtn) {
				resumeBtn.addEventListener('click', function () {
					hide(document.getElementById('iawmlf-footer-resume'));
					show(document.getElementById('iawmlf-footer-running'));
					resumeCloneProcess();
				});
			}
		}
	});
})();
