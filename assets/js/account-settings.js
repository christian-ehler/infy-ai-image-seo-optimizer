/**
 * Account Settings Page - User preferences and data management.
 *
 * This script handles the Account Settings admin page functionality,
 * allowing users to manage their preferences, export data, and reset
 * usage statistics.
 *
 * ## Features
 *
 * - Save account settings via AJAX
 * - Auto-save on field changes (debounced)
 * - Export account data (JSON/CSV)
 * - Reset usage statistics
 * - Help tooltips for settings
 *
 * ## Settings Fields
 *
 * | Field                  | Type      | Description                    |
 * |------------------------|-----------|--------------------------------|
 * | email_notifications    | checkbox  | Enable email notifications     |
 * | dashboard_widgets      | checkbox  | Dashboard widgets to show      |
 * | notification_threshold | number    | Alert when score below value   |
 * | data_retention_days    | select    | How long to keep data          |
 * | export_format          | select    | Data export format (JSON/CSV)  |
 *
 * ## AJAX Actions
 *
 * | Action                        | Purpose                    |
 * |-------------------------------|----------------------------|
 * | infyp_save_account_settings | Save settings              |
 * | infyp_export_account_data   | Export data                |
 * | infyp_reset_usage_stats     | Reset statistics           |
 *
 * ## Localized Data
 *
 * Uses `infyp_account` global object with:
 * - `strings` - Translated UI strings
 * - `help` - Help text for tooltips
 *
 * @file Account settings page handler.
 * @author AI Image SEO
 * @since 1.0.0
 * @requires jQuery
 */

(function($) {
    'use strict';

    /** Escape HTML entities to prevent XSS when inserting into .html(). */
    function escapeHtml(text) {
        if (typeof text !== 'string') return '';
        var map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
        return text.replace(/[&<>"']/g, function(c) { return map[c]; });
    }

    /**
     * Account Settings controller object.
     *
     * Manages all account settings functionality including form handling,
     * data export, and statistics reset.
     *
     * @namespace AccountSettings
     */
    const AccountSettings = {
        /**
         * Initialize the account settings page.
         *
         * @since 1.0.0
         * @memberof AccountSettings
         */
        init: function() {
            this.bindEvents();
            this.initializeTooltips();
        },

        /**
         * Bind all event handlers.
         *
         * @since 1.0.0
         * @memberof AccountSettings
         */
        bindEvents: function() {
            $('#infyp-account-settings-form').on('submit', this.handleFormSubmit.bind(this));
            $('#infyp-export-data').on('click', this.handleExportData.bind(this));
            $('#infyp-reset-stats').on('click', this.handleResetStats.bind(this));
            $('#infyp-account-settings-form input,#infyp-account-settings-form select').on('change', this.debounce(this.autoSave.bind(this), 2000));
        },
        handleFormSubmit: function(e) {
            e.preventDefault();
            const $form = $(e.target);
            const $submitBtn = $form.find('button[type="submit"]');
            const originalText = $submitBtn.text();
            $submitBtn.prop('disabled', true).text(infyp_account.strings.saving);
            const formData = {
                action: 'infyp_save_account_settings',
                nonce: $('#infyp_account_settings_nonce').val(),
                email_notifications: $('#infyp-account-settings-form input[name="email_notifications"]').is(':checked') ? 1 : 0,
                dashboard_widgets: [],
                notification_threshold: $('#infyp-account-settings-form input[name="notification_threshold"]').val(),
                data_retention_days: $('#infyp-account-settings-form select[name="data_retention_days"]').val(),
                export_format: $('#infyp-account-settings-form select[name="export_format"]').val()
            };
            $('#infyp-account-settings-form input[name="dashboard_widgets[]"]:checked').each(function() {
                formData.dashboard_widgets.push($(this).val());
            });
            $.post(ajaxurl, formData).done(function(response) {
                if (response.success) {
                    AccountSettings.showNotice(response.data.message, 'success');
                } else {
                    AccountSettings.showNotice(response.data.message || infyp_account.strings.error, 'error');
                }
            }).fail(function() {
                AccountSettings.showNotice(infyp_account.strings.error, 'error');
            }).always(function() {
                $submitBtn.prop('disabled', false).text(originalText);
            });
        },
        handleExportData: function(e) {
            e.preventDefault();
            const $btn = $(e.target);
            const originalText = $btn.text();
            const format = $('#export_format').val() || 'json';
            $btn.prop('disabled', true).text(infyp_account.strings.exporting);
            const requestData = {
                action: 'infyp_export_account_data',
                nonce: $('#infyp_account_settings_nonce').val(),
                format: format
            };
            $.post(ajaxurl, requestData).done(function(response) {
                if (response.success) {
                    const blob = new Blob([response.data.data], {
                        type: response.data.mime_type
                    });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = response.data.filename;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                    AccountSettings.showNotice(infyp_account.strings.export_success, 'success');
                } else {
                    AccountSettings.showNotice(response.data.message || infyp_account.strings.export_error, 'error');
                }
            }).fail(function() {
                AccountSettings.showNotice(infyp_account.strings.export_error, 'error');
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        },
        handleResetStats: function(e) {
            e.preventDefault();
            if (!confirm(infyp_account.strings.reset_confirm)) {
                return;
            }
            const $btn = $(e.target);
            const originalText = $btn.text();
            $btn.prop('disabled', true).text(infyp_account.strings.resetting);
            const requestData = {
                action: 'infyp_reset_usage_stats',
                nonce: $('#infyp_account_settings_nonce').val()
            };
            $.post(ajaxurl, requestData).done(function(response) {
                if (response.success) {
                    AccountSettings.showNotice(response.data.message, 'success');
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    AccountSettings.showNotice(response.data.message || infyp_account.strings.error, 'error');
                }
            }).fail(function() {
                AccountSettings.showNotice(infyp_account.strings.error, 'error');
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        },
        autoSave: function() {
            const autoSaveFields = ['notification_threshold', 'data_retention_days', 'export_format'];
            const formData = {
                action: 'infyp_save_account_settings',
                nonce: $('#infyp_account_settings_nonce').val()
            };
            let hasChanges = false;
            autoSaveFields.forEach(function(field) {
                const $field = $(`[name="${field}"]`);
                if ($field.length) {
                    formData[field] = $field.val();
                    hasChanges = true;
                }
            });
            if (!hasChanges) return;
            this.showSavingIndicator();
            $.post(ajaxurl, formData).done(function(response) {
                if (response.success) {
                    AccountSettings.showSavingIndicator(false);
                }
            }).fail(function() {
                AccountSettings.showSavingIndicator(false);
            });
        },
        showSavingIndicator: function(show = true) {
            let $indicator = $('.infyp-saving-indicator');
            if (show) {
                if (!$indicator.length) {
                    $indicator = $('<span class="infyp-saving-indicator" style="color:#666;font-size:12px;margin-left:10px;">' + infyp_account.strings.auto_saving + '</span>');
                    $('.infyp-form-actions').prepend($indicator);
                }
                $indicator.show();
            } else {
                $indicator.fadeOut().remove();
            }
        },
        showNotice: function(message, type = 'info') {
            const $notice = $('#infyp-account-notice');
            $notice.removeClass('notice-success notice-error notice-warning notice-info').addClass(`notice notice-${type}`).html(`<p>${escapeHtml(message)}</p>`).show();
            if (type === 'success') {
                setTimeout(function() {
                    $notice.fadeOut();
                }, 5000);
            }
            $('html,body').animate({
                scrollTop: $notice.offset().top - 50
            }, 300);
        },
        initializeTooltips: function() {
            const helpTexts = {
                'notification_threshold': infyp_account.help.notification_threshold,
                'data_retention_days': infyp_account.help.data_retention
            };
            Object.keys(helpTexts).forEach(function(fieldName) {
                const $field = $(`[name="${fieldName}"]`);
                if ($field.length && helpTexts[fieldName]) {
                    const $helpIcon = $(`<span class="infyp-help-icon dashicons dashicons-editor-help" title="${helpTexts[fieldName]}" style="cursor:help;color:#666;margin-left:5px;"></span>`);
                    $field.after($helpIcon);
                }
            });
        },
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },
        formatBytes: function(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    };
    $(document).ready(function() {
        AccountSettings.init();
    });
})(jQuery);