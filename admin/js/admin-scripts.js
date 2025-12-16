/**
 * Jezweb Email Double Opt-in Admin Scripts
 */

(function($) {
    'use strict';

    // Toast notification
    function showToast(message, type) {
        var $toast = $('#jedo-toast');
        $toast.text(message)
            .removeClass('success error')
            .addClass(type)
            .addClass('show');

        setTimeout(function() {
            $toast.removeClass('show');
        }, 3000);
    }

    // Tab switching
    function initTabs() {
        $('.jedo-tab').on('click', function() {
            var tabId = $(this).data('tab');

            // Update tab buttons
            $('.jedo-tab').removeClass('active');
            $(this).addClass('active');

            // Update tab panes
            $('.jedo-tab-pane').removeClass('active');
            $('#tab-' + tabId).addClass('active');

            // Load stats if stats tab
            if (tabId === 'stats') {
                loadStats();
            }
        });
    }

    // Collect all settings
    function collectSettings() {
        var settings = {};

        // Text inputs
        $('.jedo-admin-wrap input[type="text"], .jedo-admin-wrap input[type="email"], .jedo-admin-wrap input[type="url"], .jedo-admin-wrap input[type="number"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        // Select dropdowns
        $('.jedo-admin-wrap select').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        // Textareas
        $('.jedo-admin-wrap textarea').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        // Checkboxes (switches)
        $('.jedo-admin-wrap input[type="checkbox"]').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).is(':checked') ? 'yes' : 'no';
            }
        });

        // Radio buttons (toggle groups)
        $('.jedo-admin-wrap input[type="radio"]:checked').each(function() {
            var name = $(this).attr('name');
            if (name) {
                settings[name] = $(this).val();
            }
        });

        return settings;
    }

    // Toggle OTP settings visibility based on verification method
    function initVerificationMethodToggle() {
        var $methodSelect = $('select[name="jedo_verification_method"]');

        console.log('JEDO Admin: Initializing verification method toggle');
        console.log('JEDO Admin: Found method select:', $methodSelect.length > 0);

        function toggleOtpSettings() {
            var selectedValue = $methodSelect.val();
            var isOtp = selectedValue === 'otp';
            console.log('JEDO Admin: Selected method:', selectedValue, 'Is OTP:', isOtp);
            console.log('JEDO Admin: OTP settings found:', $('.jedo-otp-setting').length);

            if (isOtp) {
                $('.jedo-otp-setting').slideDown(200);
            } else {
                $('.jedo-otp-setting').slideUp(200);
            }
        }

        $methodSelect.on('change', function() {
            console.log('JEDO Admin: Dropdown changed to:', $(this).val());
            toggleOtpSettings();
        });

        // Set initial state
        toggleOtpSettings();
    }

    // Initialize toggle button groups
    function initToggleButtons() {
        $('.jedo-toggle-btn input[type="radio"]').on('change', function() {
            var $group = $(this).closest('.jedo-toggle-group');
            $group.find('.jedo-toggle-btn').removeClass('active');
            $(this).closest('.jedo-toggle-btn').addClass('active');
        });
    }

    // Save settings
    function initSaveSettings() {
        $('#jedo-save-settings').on('click', function() {
            var $btn = $(this);
            var originalHtml = $btn.html();

            $btn.html('<span class="jedo-loading"></span> ' + jedoAdmin.strings.saving);
            $btn.prop('disabled', true);

            var settings = collectSettings();

            $.ajax({
                url: jedoAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jedo_save_settings',
                    nonce: jedoAdmin.nonce,
                    settings: settings
                },
                success: function(response) {
                    if (response.success) {
                        showToast(jedoAdmin.strings.saved, 'success');
                    } else {
                        showToast(response.data.message || jedoAdmin.strings.error, 'error');
                    }
                },
                error: function() {
                    showToast(jedoAdmin.strings.error, 'error');
                },
                complete: function() {
                    $btn.html(originalHtml);
                    $btn.prop('disabled', false);
                }
            });
        });
    }

    // Send test email
    function initTestEmail() {
        $('#jedo-send-test').on('click', function() {
            var $btn = $(this);
            var originalHtml = $btn.html();

            $btn.html('<span class="jedo-loading"></span> ' + jedoAdmin.strings.sending);
            $btn.prop('disabled', true);

            $.ajax({
                url: jedoAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jedo_send_test_email',
                    nonce: jedoAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showToast(response.data.message || jedoAdmin.strings.testSent, 'success');
                    } else {
                        showToast(response.data.message || jedoAdmin.strings.testFailed, 'error');
                    }
                },
                error: function() {
                    showToast(jedoAdmin.strings.testFailed, 'error');
                },
                complete: function() {
                    $btn.html(originalHtml);
                    $btn.prop('disabled', false);
                }
            });
        });
    }

    // Load statistics
    function loadStats() {
        $.ajax({
            url: jedoAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'jedo_get_stats',
                nonce: jedoAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;

                    // Update stat cards
                    $('#stat-total').text(data.total);
                    $('#stat-verified').text(data.verified);
                    $('#stat-pending').text(data.pending);
                    $('#stat-rate').text(data.rate);

                    // Update recent verifications table
                    var $tbody = $('#jedo-recent-verifications tbody');
                    $tbody.empty();

                    if (data.recent && data.recent.length > 0) {
                        data.recent.forEach(function(user) {
                            var statusClass = user.status === 'verified' ? 'jedo-status-verified' : 'jedo-status-pending';
                            var statusText = user.status === 'verified' ? 'Verified' : 'Pending';

                            $tbody.append(
                                '<tr>' +
                                '<td>' + escapeHtml(user.username) + '</td>' +
                                '<td>' + escapeHtml(user.email) + '</td>' +
                                '<td><span class="' + statusClass + '">' + statusText + '</span></td>' +
                                '<td>' + escapeHtml(user.registered) + '</td>' +
                                '</tr>'
                            );
                        });
                    } else {
                        $tbody.append('<tr><td colspan="4" style="text-align: center; color: #6b7280;">No users found</td></tr>');
                    }
                }
            }
        });
    }

    // Escape HTML helper
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize color picker
    function initColorPicker() {
        if ($.fn.wpColorPicker) {
            $('.jedo-color-picker').wpColorPicker();
        }
    }

    // Initialize on document ready
    $(document).ready(function() {
        initTabs();
        initSaveSettings();
        initTestEmail();
        initColorPicker();
        initVerificationMethodToggle();
        initToggleButtons();

        // Load stats if starting on stats tab
        if ($('.jedo-tab[data-tab="stats"]').hasClass('active')) {
            loadStats();
        }
    });

})(jQuery);
