/**
 * Setup Wizard Controller - First-time configuration wizard.
 *
 * This script manages the multi-step setup wizard that guides users through
 * initial plugin configuration, including API key setup and preferences.
 *
 * ## Wizard Steps
 *
 * | Step        | Description                           |
 * |-------------|---------------------------------------|
 * | welcome     | Introduction and overview             |
 * | api-keys    | Configure AI provider API keys        |
 * | preferences | Set language, website type, options   |
 * | complete    | Finish wizard and redirect            |
 *
 * ## Features
 *
 * - Multi-step navigation with validation
 * - Real-time API key validation
 * - Password visibility toggle
 * - Skip confirmation modal
 * - Auto-save between steps
 * - Responsive design
 *
 * ## AJAX Actions
 *
 * | Action                          | Purpose                    |
 * |---------------------------------|----------------------------|
 * | infyp_wizard_save_step        | Save step data             |
 * | infyp_wizard_validate_api_key | Validate API key           |
 * | infyp_wizard_complete         | Complete wizard            |
 * | infyp_wizard_skip             | Skip wizard                |
 *
 * ## Localized Data
 *
 * Uses `infyp_wizard` global object with:
 * - `ajax_url` - WordPress AJAX endpoint
 * - `nonce` - Security nonce
 * - `strings` - Translated UI strings
 *
 * @file Setup wizard controller and UI handler.
 * @author AI Image SEO
 * @since 1.0.0
 * @requires jQuery
 */

(function($) {
    'use strict';

    /**
     * Wizard Controller object.
     *
     * Manages all wizard functionality including navigation,
     * validation, and data persistence.
     *
     * @namespace WizardController
     */
    const WizardController = {
        /**
         * Validation timeout for debouncing API key checks.
         * @type {number|null}
         */
        validationTimeout: null,

        /**
         * Track which API keys have been validated.
         * @type {Object.<string, boolean>}
         */
        validatedKeys: {},

        /**
         * Whether the "key saved but not verified" warning was shown since
         * the last key edit; the next Continue then goes on.
         *
         * @since 1.0.9
         */
        unverifiedAcknowledged: false,

        /**
         * Providers with a key stored on the server (rendered with the page,
         * or saved by this step). Unlike the card's has-key class, typing or
         * a rejection does not clear it: an empty field keeps the stored key.
         *
         * @since 1.0.9
         */
        storedKeys: {},

        /**
         * Ordered step slugs, read from the rendered panels.
         * @since 1.0.6
         * @type {string[]}
         */
        stepKeys: [],

        /**
         * Highest step index the user has reached (visited milestones are
         * clickable; future ones stay disabled).
         * @since 1.0.6
         * @type {number}
         */
        maxVisitedIndex: 0,

        /**
         * Whether the user prefers reduced motion — panel switches become
         * instant and no transitionend waits happen.
         * @since 1.0.6
         * @type {boolean}
         */
        reducedMotion: false,

        /**
         * True while a panel transition is running (guards double clicks).
         * @since 1.0.6
         * @type {boolean}
         */
        transitioning: false,

        /**
         * Initialize the wizard
         */
        init: function() {
            this.bindEvents();
            this.initApiKeyStatus();
            this.initPanels();
        },

        /**
         * Initialize the single-page panel engine.
         *
         * Reads the rendered panels, seeds the visited pointer from the
         * server-activated step and paints the initial progress state.
         *
         * @since 1.0.6
         */
        initPanels: function() {
            const self = this;

            this.stepKeys = $('.infyp-wizard-panel').map(function() {
                return $(this).data('step');
            }).get();

            this.reducedMotion = window.matchMedia &&
                window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            const activeIndex = this.getStepIndex(this.getCurrentStep());
            this.maxVisitedIndex = Math.max(0, activeIndex);
            this.updateProgress(activeIndex);
            this.syncSkipStepButton();

            // Keep browser back/forward working against the pushState history.
            window.addEventListener('popstate', function() {
                const params = new URLSearchParams(window.location.search);
                const step = params.get('step') || 'welcome';
                if (self.stepKeys.indexOf(step) !== -1) {
                    self.showPanel(step, { push: false });
                }
            });
        },

        /**
         * Bind all event handlers
         */
        bindEvents: function() {
            // Navigation
            $(document).on('click', '.infyp-wizard-next', this.handleNext.bind(this));
            $(document).on('click', '#infyp-wizard-complete', this.handleComplete.bind(this));
            $(document).on('click', '.infyp-wizard-skip', this.handleSkip.bind(this));
            $(document).on('click', '.infyp-wizard-skip-step', this.handleSkipStep.bind(this));
            $(document).on('click', '.infyp-wizard-prev', this.handlePrev.bind(this));
            $(document).on('click', '.infyp-wizard-step', this.handleMilestoneClick.bind(this));

            // Connect sub-screen: selection cards + provider tabs (1.0.6)
            $(document).on('change', '.infyp-connection-choice', this.handleConnectionChoice.bind(this));
            $(document).on('click', '.infyp-provider-tab', this.handleProviderTab.bind(this));

            // Preferences: website-type selection cards (1.0.6)
            $(document).on('change', '.infyp-site-type-choice', this.handleSiteTypeChoice.bind(this));

            // Header light/dark switcher (1.0.6) — light is the default,
            // the choice persists in the shared infypress-theme key.
            $(document).on('click', '.infyp-theme-toggle', function() {
                const root = document.documentElement;
                const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', next);
                try {
                    localStorage.setItem('infypress-theme', next);
                } catch (err) {
                    // Storage unavailable (private mode) — theme still switches for this view.
                }
            });

            // API Key inputs
            $(document).on('input', '.infyp-api-input', this.handleApiKeyInput.bind(this));
            $(document).on('blur', '.infyp-api-input', this.validateApiKey.bind(this));
            $(document).on('click', '.infyp-wizard-test-key', this.handleTestKey.bind(this));

            // Toggle visibility
            $(document).on('click', '.infyp-toggle-visibility', this.toggleVisibility.bind(this));

            // Disclosure panel toggle
            $(document).on('click', '.infyp-disclosure-trigger', this.toggleDisclosure.bind(this));

            // Form submission prevention
            $(document).on('submit', 'form', function(e) {
                e.preventDefault();
            });
        },

        /**
         * Initialize API key status from existing data
         */
        initApiKeyStatus: function() {
            $('.infyp-api-card.has-key').each(function() {
                const provider = $(this).data('provider');
                WizardController.validatedKeys[provider] = true;
                WizardController.storedKeys[provider] = true;
            });
        },

        /**
         * Resolve a step slug to its panel index.
         *
         * @since 1.0.6
         * @param {string} step Step slug.
         * @return {number} Index in stepKeys, -1 when unknown.
         */
        getStepIndex: function(step) {
            return this.stepKeys.indexOf(step);
        },

        /**
         * Switch the visible panel with an animated transition.
         *
         * Handles direction-aware enter/exit classes, the progress rail,
         * the aria-live announcement, focus management and pushState. With
         * reduced motion (or an unknown step) the switch is instant.
         *
         * @since 1.0.6
         * @param {string} step            Target step slug.
         * @param {Object} [opts]          Options.
         * @param {boolean} [opts.push=true] Whether to push a history entry.
         */
        showPanel: function(step, opts) {
            const self = this;
            const options = opts || {};
            const targetIndex = this.getStepIndex(step);
            const $current = $('.infyp-wizard-panel.is-active');
            const currentIndex = this.getStepIndex($current.data('step'));

            if (targetIndex === -1 || targetIndex === currentIndex || this.transitioning) {
                return;
            }

            const $target = $('.infyp-wizard-panel[data-step="' + step + '"]');
            const forward = targetIndex > currentIndex;
            const dirClass = forward ? 'dir-forward' : 'dir-back';

            // Entering a panel always leads with its first sub-screen.
            this.resetSubscreens(step);

            this.maxVisitedIndex = Math.max(this.maxVisitedIndex, targetIndex);
            this.updateProgress(targetIndex);

            if (options.push !== false && window.history && window.history.pushState) {
                const url = new URL(window.location.href);
                url.searchParams.set('step', step);
                window.history.pushState({ step: step }, '', url.toString());
            }

            const finish = function() {
                $current.removeClass('is-active is-leaving dir-forward dir-back').prop('hidden', true);
                $target.removeClass('is-entering dir-forward dir-back');
                self.transitioning = false;
                self.focusPanel($target);
            };

            if (this.reducedMotion) {
                $current.removeClass('is-active').prop('hidden', true);
                $target.prop('hidden', false).addClass('is-active');
                this.focusPanel($target);
                return;
            }

            this.transitioning = true;
            $current.addClass('is-leaving ' + dirClass);
            $target.prop('hidden', false).addClass('is-active is-entering ' + dirClass);

            // Force a reflow so the entering start-state paints before it transitions in.
            void $target[0].offsetWidth;
            $target.removeClass('is-entering');

            let done = false;
            const onDone = function() {
                if (!done) {
                    done = true;
                    finish();
                }
            };
            $target.one('transitionend', onDone);
            // Safety net in case transitionend never fires (display quirks).
            setTimeout(onDone, 450);
        },

        /**
         * Paint the progress rail for the given active index.
         *
         * @since 1.0.6
         * @param {number} activeIndex Index of the now-active step.
         * @param {number} [fraction]  Sub-screen progress within the step (0–1).
         */
        updateProgress: function(activeIndex, fraction) {
            const self = this;
            const total = this.stepKeys.length;
            const frac = fraction || 0;

            $('.infyp-wizard-step').each(function() {
                const $btn = $(this);
                const idx = parseInt($btn.data('index'), 10);

                // Reaching the final step marks its own milestone completed
                // too — the journey is done, so it gets the green check.
                const isCompleted = idx < activeIndex ||
                    (idx === activeIndex && activeIndex === total - 1);

                $btn.toggleClass('completed', isCompleted)
                    .toggleClass('active', idx === activeIndex)
                    .prop('disabled', idx > self.maxVisitedIndex);

                if (idx === activeIndex) {
                    $btn.attr('aria-current', 'step');
                } else {
                    $btn.removeAttr('aria-current');
                }
            });

            const fill = total > 1 ? (activeIndex + frac) / (total - 1) : 0;
            $('.infyp-progress-fill').css('transform', 'scaleX(' + fill + ')');

            this.announceStep(activeIndex);
        },

        /**
         * Announce the active step in the aria-live region.
         *
         * @since 1.0.6
         * @param {number} activeIndex Index of the active step.
         */
        announceStep: function(activeIndex) {
            const $live = $('#infyp-wizard-live');
            if (!$live.length) {
                return;
            }

            const label = $('.infyp-wizard-step[data-index="' + activeIndex + '"] .infyp-step-label').text();
            const template = (infyp_wizard.strings && infyp_wizard.strings.step_announcement) || 'Step %1$s of %2$s: %3$s';

            $live.text(
                template
                    .replace('%1$s', activeIndex + 1)
                    .replace('%2$s', this.stepKeys.length)
                    .replace('%3$s', label)
            );
        },

        /**
         * Move keyboard focus to the heading of the newly shown panel.
         *
         * @since 1.0.6
         * @param {jQuery} $panel The now-active panel.
         */
        focusPanel: function($panel) {
            const $heading = $panel.find('h1, h2, h3').filter(':visible').first();
            if ($heading.length) {
                $heading.attr('tabindex', '-1');
                $heading[0].focus({ preventScroll: true });
            }
        },

        /**
         * The sub-panels of a step panel, in DOM order.
         *
         * @since 1.0.6
         * @param {string} step Step slug.
         * @return {jQuery} The step's .infyp-subpanel elements (may be empty).
         */
        getSubpanels: function(step) {
            return $('.infyp-wizard-panel[data-step="' + step + '"] .infyp-subpanel');
        },

        /**
         * The sub-screen currently visible inside a step panel.
         *
         * @since 1.0.6
         * @param {string} step Step slug.
         * @return {string|undefined} data-subscreen of the active sub-panel.
         */
        getActiveSubscreen: function(step) {
            return this.getSubpanels(step).filter('.is-active').data('subscreen');
        },

        /**
         * Switch sub-screens inside a step panel.
         *
         * Animates with a one-shot keyframe (skipped under reduced motion),
         * advances the progress fill fractionally, syncs the panel's nav
         * buttons and moves focus to the sub-screen heading.
         *
         * @since 1.0.6
         * @param {string} step Step slug owning the sub-screens.
         * @param {string} name Target sub-screen name.
         */
        showSubscreen: function(step, name) {
            const $panel = $('.infyp-wizard-panel[data-step="' + step + '"]');
            const $subs = this.getSubpanels(step);
            const $current = $subs.filter('.is-active');
            const $target = $subs.filter('[data-subscreen="' + name + '"]');

            if (!$target.length || $current.data('subscreen') === name) {
                return;
            }

            const forward = $subs.index($target) > $subs.index($current);

            $current.removeClass('is-active subpanel-enter-forward subpanel-enter-back');
            $target.addClass('is-active');

            if (!this.reducedMotion) {
                $target.addClass(forward ? 'subpanel-enter-forward' : 'subpanel-enter-back');
                setTimeout(function() {
                    $target.removeClass('subpanel-enter-forward subpanel-enter-back');
                }, 350);
            }

            this.updateProgress(this.getStepIndex(step), $subs.index($target) / $subs.length);
            this.updateStepNav(step);
            this.focusPanel($panel);
        },

        /**
         * Reset a step panel to its first sub-screen (no-op without any).
         *
         * @since 1.0.6
         * @param {string} step Step slug.
         */
        resetSubscreens: function(step) {
            const $subs = this.getSubpanels(step);

            if ($subs.length) {
                $subs.removeClass('is-active subpanel-enter-forward subpanel-enter-back');
                $subs.first().addClass('is-active');
                this.updateStepNav(step);
            }
        },

        /**
         * Sync a panel's Continue/Complete buttons with its sub-screen
         * position: when both exist, Complete replaces Continue on the last
         * sub-screen only.
         *
         * @since 1.0.6
         * @param {string} step Step slug.
         */
        updateStepNav: function(step) {
            this.syncSkipStepButton();

            const $panel = $('.infyp-wizard-panel[data-step="' + step + '"]');
            const $next = $panel.find('.infyp-panel-nav .infyp-wizard-next');
            const $complete = $panel.find('.infyp-panel-nav .infyp-wizard-complete-btn');

            if (!$complete.length || !$next.length) {
                return;
            }

            const $subs = this.getSubpanels(step);
            const onLast = !$subs.length ||
                $subs.index($subs.filter('.is-active')) === $subs.length - 1;

            $next.toggleClass('infyp-hidden', onLast);
            $complete.toggleClass('infyp-hidden', !onLast);
        },

        /**
         * Handle a connection-method card selection.
         *
         * Mirrors the choice into the hidden #infyp-connection-method input
         * (the field collectStepData and validateApiKeysStep read).
         *
         * @since 1.0.6
         */
        handleConnectionChoice: function(e) {
            const value = $(e.currentTarget).val();
            $('#infyp-connection-method').val(value);
            $('.infyp-choice-card').removeClass('is-selected');
            $(e.currentTarget).closest('.infyp-choice-card').addClass('is-selected');
            this.syncSkipStepButton();
        },

        /**
         * Show "Continue without a key" only where it means what it says: on
         * the provider-keys sub-screen with API keys as the connection. It
         * saves nothing, so on the connection choice (where WordPress AI may
         * be selected) it would drop that choice.
         *
         * @since 1.0.9
         */
        syncSkipStepButton: function() {
            const $btn = $('.infyp-wizard-skip-step');
            if (!$btn.length) {
                return;
            }

            const show = this.getActiveSubscreen('api-keys') === 'providers' &&
                $('#infyp-connection-method').val() !== 'wp_ai';
            $btn.toggleClass('infyp-hidden', !show);
        },

        /**
         * Handle a website-type card selection.
         *
         * Mirrors the choice into the hidden #infyp-website-type input that
         * collectStepData('preferences') reads.
         *
         * @since 1.0.6
         */
        handleSiteTypeChoice: function(e) {
            const $input = $(e.currentTarget);
            $('#infyp-website-type').val($input.val());
            $('.infyp-site-type-choice').closest('.infyp-choice-card').removeClass('is-selected');
            $input.closest('.infyp-choice-card').addClass('is-selected');
        },

        /**
         * Handle a provider tab click: reveal that provider's key pane.
         *
         * All panes stay in the DOM (hidden panes keep their values), so
         * saving and validation are unaffected.
         *
         * @since 1.0.6
         */
        handleProviderTab: function(e) {
            e.preventDefault();
            const $tab = $(e.currentTarget);
            const provider = $tab.data('provider');

            $('.infyp-provider-tab').removeClass('is-active').attr('aria-selected', 'false');
            $tab.addClass('is-active').attr('aria-selected', 'true');

            $('.infyp-provider-panes .infyp-api-card').removeClass('is-active-pane');
            $('.infyp-provider-panes .infyp-api-card[data-provider="' + provider + '"]').addClass('is-active-pane');
        },

        /**
         * Handle Back button click (client-side animated transition).
         *
         * @since 1.0.6
         */
        handlePrev: function(e) {
            e.preventDefault();

            // Back first walks the panel's own sub-screens before leaving it.
            const currentStep = this.getCurrentStep();
            const $subs = this.getSubpanels(currentStep);
            if ($subs.length) {
                const activeIdx = $subs.index($subs.filter('.is-active'));
                if (activeIdx > 0) {
                    this.showSubscreen(currentStep, $subs.eq(activeIdx - 1).data('subscreen'));
                    return;
                }
            }

            const target = $(e.currentTarget).data('step-target');
            if (target) {
                this.showPanel(target);
            }
        },

        /**
         * Handle a click on a visited progress milestone.
         *
         * @since 1.0.6
         */
        handleMilestoneClick: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            if ($btn.prop('disabled')) {
                return;
            }
            this.showPanel($btn.data('step'));
        },

        /**
         * Handle next button click
         */
        handleNext: function(e) {
            e.preventDefault();

            const $btn = $(e.currentTarget);
            const nextStep = $btn.data('next-step');
            const currentStep = this.getCurrentStep();

            // Sub-screen walking: Continue advances within the panel before
            // it leaves it. Exception: choosing WordPress AI on the Connect
            // decision skips the provider sub-screen entirely and falls
            // through to the normal save-and-advance (the server persists
            // connection_method and skips key handling).
            const $subs = this.getSubpanels(currentStep);
            if ($subs.length) {
                const activeIdx = $subs.index($subs.filter('.is-active'));
                const skipToSave = currentStep === 'api-keys' &&
                    this.getActiveSubscreen(currentStep) === 'connect' &&
                    $('#infyp-connection-method').val() === 'wp_ai';

                if (!skipToSave && activeIdx < $subs.length - 1) {
                    this.showSubscreen(currentStep, $subs.eq(activeIdx + 1).data('subscreen'));
                    return;
                }
            }

            // Validate current step
            if (!this.validateStep(currentStep)) {
                return;
            }

            // Save step data
            $btn.addClass('loading').prop('disabled', true);

            this.saveStepData(currentStep).then(function(response) {
                $btn.removeClass('loading').prop('disabled', false);
                if (response.success) {
                    if (currentStep === 'api-keys') {
                        WizardController.rememberStoredKeys();
                        if (WizardController.holdForUnverifiedKeys(response.data)) {
                            return;
                        }
                    }
                    WizardController.showPanel(nextStep);
                } else if (currentStep === 'api-keys' && response.data && response.data.invalid) {
                    WizardController.showRejectedKeys(response.data);
                } else {
                    WizardController.showError((response.data && response.data.message) || infyp_wizard.strings.error);
                }
            }).catch(function(error) {
                WizardController.showError(infyp_wizard.strings.error);
                $btn.removeClass('loading').prop('disabled', false);
            });
        },

        /**
         * Name of a provider as shown on its key card.
         *
         * @since 1.0.9
         * @param {string} provider Provider slug.
         * @return {string} Provider label, or the slug.
         */
        getProviderLabel: function(provider) {
            const label = $('.infyp-api-card[data-provider="' + provider + '"] h4').first().text().trim();
            return label || provider;
        },

        /**
         * Replace %s / %1$s placeholders of a translated string.
         *
         * @since 1.0.9
         * @param {string} format Translated string with placeholders.
         * @param {...string} args Values, in placeholder order.
         * @return {string} Formatted text.
         */
        formatString: function(format) {
            const args = Array.prototype.slice.call(arguments, 1);
            let next = 0;
            return String(format || '').replace(/%(?:(\d+)\$)?s/g, function(match, position) {
                const value = position ? args[parseInt(position, 10) - 1] : args[next++];
                return value === undefined ? '' : String(value);
            });
        },

        /**
         * After a successful key step save, every key that was in a field
         * is stored on the server.
         *
         * @since 1.0.9
         */
        rememberStoredKeys: function() {
            ['openai', 'google', 'anthropic'].forEach(function(provider) {
                if (($('#infyp-api-' + provider).val() || '').trim()) {
                    WizardController.storedKeys[provider] = true;
                }
            });
        },

        /**
         * Keys the provider rejected on Continue: nothing was saved, the step
         * stays. Marks each card, opens the first rejected provider's tab and
         * shows the provider's reason in the notice.
         *
         * @since 1.0.9
         * @param {Object} data Error payload: message, invalid {provider: reason}.
         */
        showRejectedKeys: function(data) {
            // The server's message names each rejected provider and its reply.
            Object.keys(data.invalid).forEach(function(provider) {
                WizardController.updateApiKeyStatus(provider, 'invalid', data.invalid[provider] || '');
                delete WizardController.validatedKeys[provider];
            });

            const first = Object.keys(data.invalid)[0];
            if (first) {
                $('.infyp-provider-tab[data-provider="' + first + '"]').trigger('click');
            }

            this.showError(data.message || infyp_wizard.strings.error, '#infyp-api-notice', 'error', true);
        },

        /**
         * Keys saved but not checked (provider unreachable): warn once and
         * stay; the next Continue goes on.
         *
         * @since 1.0.9
         * @param {Object} data Success payload, may carry unverified {provider: reason}.
         * @return {boolean} True when the step should stay for the warning.
         */
        holdForUnverifiedKeys: function(data) {
            const unverified = (data && data.unverified) || {};
            const providers = Object.keys(unverified);
            if (!providers.length || this.unverifiedAcknowledged) {
                return false;
            }

            const strings = infyp_wizard.strings;
            const replies = providers.map(function(provider) {
                const label = WizardController.getProviderLabel(provider);
                WizardController.updateApiKeyStatus(provider, 'unverified', unverified[provider]);
                return unverified[provider] ? WizardController.formatString(strings.provider_reply, label, unverified[provider]) : label;
            });

            this.unverifiedAcknowledged = true;
            this.showError(this.formatString(strings.unverified_continue, replies.join(' ')), '#infyp-api-notice', 'warning', true);
            return true;
        },

        /**
         * Leave the key step without saving a key.
         *
         * @since 1.0.9
         */
        handleSkipStep: function(e) {
            e.preventDefault();
            this.resetKeyNotice();
            this.showPanel($(e.currentTarget).data('next-step'));
        },

        /**
         * Put the key notice back to its default hint.
         *
         * @since 1.0.9
         */
        resetKeyNotice: function() {
            const $notice = $('#infyp-api-notice');
            const $text = $notice.find('span');
            if ($text.data('default-text') !== undefined) {
                $text.text($text.data('default-text'));
            }
            $notice.removeClass('error warning');
        },

        /**
         * Handle complete button click
         */
        handleComplete: function(e) {
            e.preventDefault();

            const $btn = $(e.currentTarget);
            const currentStep = this.getCurrentStep();

            // Validate current step
            if (!this.validateStep(currentStep)) {
                return;
            }

            $btn.addClass('loading').prop('disabled', true);

            // Save preferences first
            this.saveStepData(currentStep).then(function() {
                // Then complete the wizard
                return $.ajax({
                    url: infyp_wizard.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'infyp_wizard_complete',
                        nonce: infyp_wizard.nonce
                    }
                });
            }).then(function(response) {
                $btn.removeClass('loading').prop('disabled', false);
                if (response.success) {
                    // The final screen rendered at page load predates the
                    // keys saved in this visit; use the server's fresh one
                    // (it says what is missing when no connection works).
                    if (response.data && typeof response.data.html === 'string' && response.data.html) {
                        $('.infyp-wizard-panel[data-step="complete"] .infyp-step-complete').replaceWith(response.data.html);
                    }
                    // Show the celebration panel; its CTAs link onwards
                    // (previously this redirected straight to the dashboard
                    // and the complete screen was never seen).
                    WizardController.showPanel('complete');
                } else {
                    WizardController.showError(response.data.message);
                }
            }).catch(function(error) {
                WizardController.showError(infyp_wizard.strings.error);
                $btn.removeClass('loading').prop('disabled', false);
            });
        },

        /**
         * Handle skip button click
         */
        handleSkip: function(e) {
            e.preventDefault();
            this.showSkipModal();
        },

        /**
         * Show skip confirmation modal
         */
        showSkipModal: function() {
            // Create modal if it doesn't exist
            if (!$('#infyp-skip-modal').length) {
                const modalHtml = `
                    <div class="infyp-modal-overlay" id="infyp-skip-modal">
                        <div class="infyp-modal">
                            <div class="infyp-modal-icon">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                </svg>
                            </div>
                            <h3 class="infyp-modal-title">${infyp_wizard.strings.skip_title || 'Skip Setup?'}</h3>
                            <p class="infyp-modal-message">${infyp_wizard.strings.skip_confirm}</p>
                            <div class="infyp-modal-actions">
                                <button type="button" class="infyp-btn infyp-btn-secondary" id="infyp-skip-cancel">
                                    ${infyp_wizard.strings.cancel || 'Cancel'}
                                </button>
                                <button type="button" class="infyp-btn infyp-btn-danger" id="infyp-skip-confirm">
                                    ${infyp_wizard.strings.skip_button || 'Skip Setup'}
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                $('body').append(modalHtml);

                // Bind modal events
                $(document).on('click', '#infyp-skip-cancel', this.hideSkipModal.bind(this));
                $(document).on('click', '#infyp-skip-confirm', this.confirmSkip.bind(this));
                $(document).on('click', '#infyp-skip-modal', function(e) {
                    if ($(e.target).is('#infyp-skip-modal')) {
                        WizardController.hideSkipModal();
                    }
                });
                $(document).on('keydown', function(e) {
                    if (e.key === 'Escape' && $('#infyp-skip-modal').hasClass('active')) {
                        WizardController.hideSkipModal();
                    }
                });
            }

            // Show modal
            $('#infyp-skip-modal').addClass('active');
            $('body').addClass('infyp-modal-open');
        },

        /**
         * Hide skip confirmation modal
         */
        hideSkipModal: function() {
            $('#infyp-skip-modal').removeClass('active');
            $('body').removeClass('infyp-modal-open');
        },

        /**
         * Confirm skip action
         */
        confirmSkip: function() {
            const $btn = $('#infyp-skip-confirm');
            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: infyp_wizard.ajax_url,
                type: 'POST',
                data: {
                    action: 'infyp_wizard_skip',
                    nonce: infyp_wizard.nonce
                }
            }).then(function(response) {
                if (response.success) {
                    window.location.href = response.data.redirect_url;
                } else {
                    WizardController.showError(response.data.message);
                    WizardController.hideSkipModal();
                    $btn.removeClass('loading').prop('disabled', false);
                }
            }).catch(function(error) {
                WizardController.showError(infyp_wizard.strings.error);
                WizardController.hideSkipModal();
                $btn.removeClass('loading').prop('disabled', false);
            });
        },

        /**
         * Get current step from the active panel (URL as fallback).
         *
         * @since 1.0.6 Reads the DOM instead of the URL — with the
         * single-page shell the URL only mirrors the active panel.
         */
        getCurrentStep: function() {
            const domStep = $('.infyp-wizard-panel.is-active').data('step');
            if (domStep) {
                return domStep;
            }
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get('step') || 'welcome';
        },

        /**
         * Validate current step
         */
        validateStep: function(step) {
            switch (step) {
                case 'api-keys':
                    return this.validateApiKeysStep();
                default:
                    return true;
            }
        },

        /**
         * Validate API keys step
         */
        validateApiKeysStep: function() {
            // WordPress AI Connectors satisfies the step without any key.
            if ($('#infyp-connection-method').val() === 'wp_ai') {
                return true;
            }

            // Check if at least one API key is provided
            let hasKey = false;
            const providers = ['openai', 'google', 'anthropic'];

            providers.forEach(function(provider) {
                const $input = $('#infyp-api-' + provider);
                const value = $input.val().trim();
                const $card = $input.closest('.infyp-api-card');

                // A stored key counts while its field is empty: an empty
                // field is not submitted, so the stored key stays.
                if (value || $card.hasClass('has-key') || WizardController.storedKeys[provider]) {
                    hasKey = true;
                }
            });

            if (!hasKey) {
                this.showError(infyp_wizard.strings.api_key_required, '#infyp-api-notice');
                return false;
            }

            return true;
        },

        /**
         * Save step data via AJAX
         */
        saveStepData: function(step) {
            const data = this.collectStepData(step);

            return $.ajax({
                url: infyp_wizard.ajax_url,
                type: 'POST',
                data: {
                    action: 'infyp_wizard_save_step',
                    nonce: infyp_wizard.nonce,
                    step: step,
                    data: data
                }
            });
        },

        /**
         * Collect data from current step
         */
        collectStepData: function(step) {
            const data = {};

            switch (step) {
                case 'api-keys':
                    const method = $('#infyp-connection-method').val();
                    if (method) {
                        data.connection_method = method;
                    }
                    ['openai', 'google', 'anthropic'].forEach(function(provider) {
                        const value = $('#infyp-api-' + provider).val().trim();
                        if (value) {
                            data[provider] = value;
                        }
                    });
                    break;

                case 'preferences':
                    data.website_type = $('#infyp-website-type').val();
                    data.language = $('#infyp-language').val();
                    data.image_count = $('input[name="image_count"]:checked').val();
                    // Newsletter opt-in uses logged-in user's data
                    data.newsletter_consent = $('input[name="newsletter_consent"]').is(':checked') ? 1 : 0;
                    data.user_email = $('input[name="user_email"]').val();
                    data.user_name = $('input[name="user_name"]').val();
                    data.user_id = $('input[name="user_id"]').val();
                    // Telemetry preferences (only relevant when newsletter is checked)
                    if (data.newsletter_consent) {
                        data.telemetry_environment = $('input[name="telemetry_environment"]').is(':checked') ? 1 : 0;
                        data.telemetry_theme = $('input[name="telemetry_theme"]').is(':checked') ? 1 : 0;
                        data.telemetry_content = $('input[name="telemetry_content"]').is(':checked') ? 1 : 0;
                        data.telemetry_plugins = $('input[name="telemetry_plugins"]').is(':checked') ? 1 : 0;
                    }
                    break;
            }

            return data;
        },

        /**
         * Handle API key input
         */
        handleApiKeyInput: function(e) {
            const $input = $(e.target);
            const provider = $input.data('provider');

            // Clear validation timeout
            clearTimeout(this.validationTimeout);

            // Remove previous validation status
            this.updateApiKeyStatus(provider, '');
            delete this.validatedKeys[provider];
            this.unverifiedAcknowledged = false;

            // An edited key makes a rejection/warning about the old one stale.
            this.resetKeyNotice();
        },

        /**
         * Validate API key on blur
         */
        validateApiKey: function(e) {
            const $input = $(e.target);
            const provider = $input.data('provider');
            const apiKey = $input.val().trim();

            if (!apiKey) {
                this.updateApiKeyStatus(provider, '');
                return;
            }

            this.runKeyTest(provider, apiKey);
        },

        /**
         * Handle the explicit Test Key button on a provider card.
         *
         * Tests the typed value; with an empty field the server tests the
         * key already stored for the provider (so users can verify an
         * existing key without re-pasting it).
         *
         * @since 1.0.6
         */
        handleTestKey: function(e) {
            e.preventDefault();
            const provider = $(e.currentTarget).data('provider');
            const apiKey = ($('#infyp-api-' + provider).val() || '').trim();
            this.runKeyTest(provider, apiKey);
        },

        /**
         * Run the live provider verification and paint the badge.
         *
         * Shared by input blur and the Test Key button.
         *
         * @since 1.0.6
         * @param {string} provider Provider slug.
         * @param {string} apiKey   Key to test; empty tests the stored key.
         */
        runKeyTest: function(provider, apiKey) {
            // Show validating status
            this.updateApiKeyStatus(provider, 'validating');

            $.ajax({
                url: infyp_wizard.ajax_url,
                type: 'POST',
                data: {
                    action: 'infyp_wizard_validate_api_key',
                    nonce: infyp_wizard.nonce,
                    provider: provider,
                    api_key: apiKey
                }
            }).then(function(response) {
                if (response.success) {
                    // 'valid' = provider accepted the key; 'unverified' =
                    // network trouble — neutral badge, never red.
                    const state = (response.data && response.data.state) || 'valid';
                    WizardController.updateApiKeyStatus(provider, state === 'unverified' ? 'unverified' : 'connected');
                    WizardController.validatedKeys[provider] = true;
                } else {
                    WizardController.updateApiKeyStatus(provider, 'invalid', response.data && response.data.message);
                    delete WizardController.validatedKeys[provider];
                }
            }).catch(function() {
                // Transport failure — could not verify, not proven invalid.
                WizardController.updateApiKeyStatus(provider, 'unverified');
            });
        },

        /**
         * Update API key status badge
         */
        updateApiKeyStatus: function(provider, status, detail) {
            const $statusContainer = $('#infyp-status-' + provider);
            const $card = $statusContainer.closest('.infyp-api-card');

            $card.removeClass('has-key');

            if (!status) {
                $statusContainer.html('');
                return;
            }

            let html = '';
            switch (status) {
                case 'validating':
                    html = '<span class="infyp-status-badge validating">' + infyp_wizard.strings.validating + '</span>';
                    break;
                case 'connected':
                    html = '<span class="infyp-status-badge connected">' +
                           '<svg width="16" height="16" viewBox="0 0 16 16" fill="none">' +
                           '<path d="M13.5 4.5L6 12L2.5 8.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
                           '</svg>' +
                           infyp_wizard.strings.valid +
                           '</span>';
                    $card.addClass('has-key');
                    break;
                case 'invalid':
                    html = '<span class="infyp-status-badge invalid">' + infyp_wizard.strings.invalid + '</span>';
                    break;
                case 'unverified':
                    // Neutral: the provider could not be reached — the key
                    // may still be perfectly fine.
                    html = '<span class="infyp-status-badge unverified">' + (infyp_wizard.strings.unverified || 'Saved — not verified') + '</span>';
                    break;
            }

            $statusContainer.html(html);

            // Surface the provider's own rejection reason as a tooltip so
            // e.g. "Identity verification is required" is not lost.
            if (detail) {
                $statusContainer.find('.infyp-status-badge').attr('title', detail);
            }
        },

        /**
         * Toggle data disclosure panel
         */
        toggleDisclosure: function(e) {
            e.preventDefault();

            const $trigger = $(e.currentTarget);
            const $content = $trigger.siblings('.infyp-disclosure-content');
            const isExpanded = $trigger.attr('aria-expanded') === 'true';

            $trigger.attr('aria-expanded', !isExpanded);
            $content.attr('aria-hidden', isExpanded);
        },

        /**
         * Toggle password visibility
         */
        toggleVisibility: function(e) {
            e.preventDefault();

            const $btn = $(e.currentTarget);
            const $input = $btn.siblings('.infyp-api-input');
            const isPassword = $input.attr('type') === 'password';

            $input.attr('type', isPassword ? 'text' : 'password');
            $btn.find('.eye-open').toggle(!isPassword);
            $btn.find('.eye-closed').toggle(isPassword);
        },

        /**
         * Show error message
         *
         * @since 1.0.9 Optional type ('error' | 'warning') and persist; the
         *              message is also announced in the live region.
         * @param {string}  message  Plain text.
         * @param {string}  selector Notice element, default the key notice.
         * @param {string}  type     'error' (default) or 'warning'.
         * @param {boolean} persist  Keep the highlight instead of fading after 5 s.
         */
        showError: function(message, selector, type, persist) {
            const $notice = selector ? $(selector) : $('#infyp-api-notice');
            const cls = type === 'warning' ? 'warning' : 'error';

            if ($notice.length) {
                const $text = $notice.find('span');
                if ($text.data('default-text') === undefined) {
                    $text.data('default-text', $text.text());
                }
                $notice.removeClass('error warning').addClass(cls);
                $text.text(message);
                $('#infyp-wizard-live').text(message);

                // Scroll to notice
                $('html, body').animate({
                    scrollTop: $notice.offset().top - 100
                }, 300);

                // Remove error class after 5 seconds
                if (!persist) {
                    clearTimeout(this.noticeTimeout);
                    this.noticeTimeout = setTimeout(function() {
                        $notice.removeClass('error warning');
                    }, 5000);
                }
            } else {
                alert(message);
            }
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        WizardController.init();
    });

})(jQuery);
