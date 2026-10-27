(function ($) {
    'use strict';

    if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
        return;
    }

    var UNITS = [
        { seconds: 604800, one: 'week', many: 'weeks' },
        { seconds: 86400, one: 'day', many: 'days' },
        { seconds: 3600, one: 'hour', many: 'hours' },
        { seconds: 60, one: 'minute', many: 'minutes' },
        { seconds: 1, one: 'second', many: 'seconds' },
    ];

    /**
     * Behaviour for the email template edit page: tabs are Craft's own, this adds the
     * delay picker, the dynamic condition selects, the variables panel, quick picks,
     * example loading, the sidebar summary, preview and test sending.
     */
    Craft.MailCraftEditor = Garnish.Base.extend({
        settings: null,
        $form: null,
        $event: null,
        $condition1: null,
        $condition2: null,
        previewModal: null,

        init: function (settings) {
            this.settings = $.extend({
                templateId: null,
                event: null,
                condition1: null,
                condition2: null,
                eventLabels: {},
                variables: {},
                examples: {},
                conditions: {},
                currentUserEmail: '',
                systemFromEmail: '',
                systemFromName: '',
            }, settings || {});

            this.$event = $('#event');
            this.$condition1 = $('#condition1');
            this.$condition2 = $('#condition2');
            this.$form = this.$event.closest('form');

            this.initDelay();
            this.initQuickPicks();
            this.initExampleButton();
            this.initPreview();
            this.initSendTest();
            this.initSummaryBindings();

            // Selectize is initialised by Craft on DOM ready; wait for it before wiring events.
            this.whenReady(
                $.proxy(function () {
                    return this.$event.length && this.$event[0].selectize;
                }, this),
                $.proxy(this.initEvent, this)
            );

            this.onEventChange();
        },

        whenReady: function (test, callback, attempts) {
            attempts = attempts || 0;
            if (test()) {
                callback();
            } else if (attempts < 100) {
                setTimeout($.proxy(function () { this.whenReady(test, callback, attempts + 1); }, this), 50);
            }
        },

        // ───────────── Event + conditions ─────────────

        initEvent: function () {
            var eventSelect = this.$event[0].selectize;
            var self = this;

            eventSelect.on('change', function () {
                self.onEventChange();
                self.resetConditions();
                self.populateCondition1(self.$event.val());
            });

            // Restore saved conditions for an existing template, then keep the summary in sync.
            this.populateCondition1(this.$event.val(), this.settings.condition1, this.settings.condition2);
        },

        selectizeOf: function ($el) {
            return $el.length ? $el[0].selectize : null;
        },

        resetConditions: function () {
            var self = this;
            [this.$condition1, this.$condition2].forEach(function ($el) {
                var s = self.selectizeOf($el);
                if (s) {
                    s.off('change');
                    s.clear(true);
                    s.clearOptions();
                }
                $el.closest('.field').addClass('hidden');
            });
            this.updateConditionSummary();
        },

        fillConditionSelect: function ($el, definition, value) {
            var select = this.selectizeOf($el);
            var $field = $el.closest('.field');
            if (!select) {
                return;
            }

            select.off('change');
            select.clear(true);
            select.clearOptions();

            if (!definition || !definition.options) {
                $field.addClass('hidden');
                return;
            }

            var options = Array.isArray(definition.options) ? definition.options : Object.values(definition.options);
            options.forEach(function (option) { select.addOption(option); });
            select.refreshOptions(false);
            select.updatePlaceholder();
            $field.removeClass('hidden');
            $field.find('> .heading label').text(definition.name || '');

            if (value) {
                select.setValue(value, true);
            }
        },

        populateCondition1: function (event, savedValue1, savedValue2) {
            var group = (this.settings.conditions || {})[event] || null;
            var condition1 = group ? group.condition1 : null;
            var condition2 = group ? group.condition2 : null;
            var self = this;

            this.fillConditionSelect(this.$condition1, condition1, savedValue1);

            var select1 = this.selectizeOf(this.$condition1);

            var applyCondition2 = function (value1) {
                if (!condition2) {
                    self.fillConditionSelect(self.$condition2, null);
                    return;
                }
                if (condition1 && condition1.dependant) {
                    var dependent = value1 && condition2.options ? condition2.options[value1] : null;
                    self.fillConditionSelect(self.$condition2, dependent ? {
                        name: condition2.name,
                        options: dependent,
                    } : null, savedValue2);
                } else {
                    self.fillConditionSelect(self.$condition2, condition2, savedValue2);
                }
                savedValue2 = null;
            };

            if (select1) {
                select1.on('change', function (value) {
                    applyCondition2(value);
                    self.updateConditionSummary();
                });
            }

            applyCondition2(savedValue1 || null);
            this.updateConditionSummary();
        },

        updateConditionSummary: function () {
            var parts = [];
            var self = this;
            [this.$condition1, this.$condition2].forEach(function ($el) {
                var select = self.selectizeOf($el);
                var $field = $el.closest('.field');
                if (!select || $field.hasClass('hidden')) {
                    return;
                }
                var value = select.getValue();
                if (!value) {
                    return;
                }
                var option = select.options[value];
                var label = $field.find('> .heading label').text();
                parts.push(label + ': ' + (option ? (option.text || option.label || value) : value));
            });

            var $row = $('#mailcraft-summary-condition-row');
            $('#mailcraft-summary-condition').text(parts.join(' · '));
            $row.toggleClass('hidden', parts.length === 0);
        },

        onEventChange: function () {
            var event = this.$event.val();
            var label = event ? (this.settings.eventLabels[event] || event) : Craft.t('mailcraft', 'No event chosen');
            $('#mailcraft-summary-event').text(label);
            this.renderVariables(event);
            this.renderQuickPicks(event);
            $('#mailcraft-load-example').prop('disabled', !(event && this.settings.examples[event]));
        },

        // ───────────── Delay ─────────────

        initDelay: function () {
            var self = this;
            this.$delay = $('#delay');
            this.$delayAmount = $('#delay-amount');
            this.$delayUnit = $('#delay-unit');

            this.addListener(this.$delayAmount, 'input change', 'syncDelayFromInputs');
            this.addListener(this.$delayUnit, 'change', 'syncDelayFromInputs');

            $('.mailcraft-delay__presets button').on('click', function () {
                self.setDelay(parseInt($(this).data('delay'), 10) || 0);
            });

            this.updateDelaySummary();
        },

        syncDelayFromInputs: function () {
            var amount = Math.max(0, parseInt(this.$delayAmount.val(), 10) || 0);
            var unit = parseInt(this.$delayUnit.val(), 10) || 1;
            this.$delay.val(amount * unit);
            this.updateDelaySummary();
        },

        setDelay: function (seconds) {
            var unit = UNITS[UNITS.length - 2]; // minutes
            if (seconds > 0) {
                for (var i = 0; i < UNITS.length; i++) {
                    if (seconds % UNITS[i].seconds === 0) {
                        unit = UNITS[i];
                        break;
                    }
                }
            }
            this.$delayAmount.val(seconds > 0 ? seconds / unit.seconds : 0);
            this.$delayUnit.val(unit.seconds);
            this.$delay.val(seconds);
            this.updateDelaySummary();
        },

        describeDelay: function (seconds) {
            if (!seconds) {
                return Craft.t('mailcraft', 'Instantly');
            }
            for (var i = 0; i < UNITS.length; i++) {
                if (seconds % UNITS[i].seconds === 0) {
                    var amount = seconds / UNITS[i].seconds;
                    return Craft.t('mailcraft', '{amount} {unit}', {
                        amount: amount,
                        unit: Craft.t('mailcraft', amount === 1 ? UNITS[i].one : UNITS[i].many),
                    });
                }
            }
            return seconds + ' s';
        },

        updateDelaySummary: function () {
            $('#mailcraft-summary-delay').text(this.describeDelay(parseInt(this.$delay.val(), 10) || 0));
        },

        // ───────────── Variables panel ─────────────

        renderVariables: function (event) {
            var $list = $('#mailcraft-vars-list').empty();
            var $title = $('#mailcraft-vars-title');
            var vars = event ? (this.settings.variables[event] || {}) : {};
            var keys = Object.keys(vars);

            if (!event || !keys.length) {
                $title.text(Craft.t('mailcraft', 'Variables'));
                $list.append($('<li class="mailcraft-vars__empty light"/>').text(Craft.t('mailcraft', 'Choose an event to see its variables.')));
                return;
            }

            $title.text(Craft.t('mailcraft', 'Variables for “{event}”', { event: this.settings.eventLabels[event] || event }));

            var add = function (variable, description) {
                var $li = $('<li/>');
                $('<button type="button" class="mailcraft-vars__var code"/>').text(variable).appendTo($li);
                if (description) {
                    $('<span class="mailcraft-vars__desc light"/>').text(description).appendTo($li);
                }
                $list.append($li);
            };

            keys.forEach(function (key) {
                var def = vars[key] || {};
                if (def.fields && typeof def.fields === 'object') {
                    Object.keys(def.fields).forEach(function (field) {
                        var label = def.fields[field];
                        add('{{ ' + key + '.' + field + ' }}', typeof label === 'string' ? label : field);
                    });
                } else {
                    add(def.example || ('{{ ' + key + ' }}'), def.description || '');
                }
            });
        },

        copyVariable: function (text, $button) {
            var done = function () {
                Craft.cp.displayNotice(Craft.t('mailcraft', 'Copied {variable} to the clipboard.', { variable: text }));
            };
            var fallback = function () {
                var range = document.createRange();
                range.selectNodeContents($button[0]);
                var selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                Craft.cp.displayError(Craft.t('mailcraft', 'Could not copy. Select the variable and copy it manually.'));
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, fallback);
            } else {
                fallback();
            }
        },

        // ───────────── Quick picks for "To" ─────────────

        initQuickPicks: function () {
            var self = this;
            $(document).on('click', '#mailcraft-to-picks button', function () {
                $('#to').val($(this).data('to')).trigger('input');
            });
            $(document).on('click', '.mailcraft-vars__var', function () {
                self.copyVariable($(this).text(), $(this));
            });
        },

        renderQuickPicks: function (event) {
            var $picks = $('#mailcraft-to-picks');
            $picks.find('button').remove();
            var vars = event ? (this.settings.variables[event] || {}) : {};
            var picks = [];

            if (vars.user) {
                picks.push({ label: Craft.t('mailcraft', 'The user'), to: '{{ user.email }}' });
            }
            if (vars.order) {
                picks.push({ label: Craft.t('mailcraft', 'The customer'), to: '{{ order.email }}' });
            }
            if (vars.entry) {
                picks.push({ label: Craft.t('mailcraft', 'The entry author'), to: '{{ entry.author.email }}' });
            }
            if (this.settings.currentUserEmail) {
                picks.push({ label: Craft.t('mailcraft', 'Me ({email})', { email: this.settings.currentUserEmail }), to: this.settings.currentUserEmail });
            }
            if (this.settings.systemFromEmail && this.settings.systemFromEmail !== this.settings.currentUserEmail) {
                picks.push({ label: Craft.t('mailcraft', 'System address ({email})', { email: this.settings.systemFromEmail }), to: this.settings.systemFromEmail });
            }

            picks.forEach(function (pick) {
                $('<button type="button" class="btn small"/>').text(pick.label).attr('data-to', pick.to).appendTo($picks);
            });
            $picks.toggleClass('hidden', picks.length === 0);
        },

        // ───────────── Example loading ─────────────

        initExampleButton: function () {
            this.addListener($('#mailcraft-load-example'), 'click', 'loadExample');
        },

        loadExample: function () {
            var event = this.$event.val();
            var example = event ? this.settings.examples[event] : null;
            if (!example) {
                return;
            }

            var hasContent = $.trim($('#subject').val()) !== '' || $.trim(this.getTemplateContent()) !== '';
            if (hasContent && !confirm(Craft.t('mailcraft', 'Replace the current title, subject and email body with the example for this event?'))) {
                return;
            }

            if (example.title) {
                $('#title').val(example.title).trigger('input');
            }
            if (example.subject) {
                $('#subject').val(example.subject).trigger('input');
            }
            if (example.to && $.trim($('#to').val()) === '') {
                $('#to').val(example.to).trigger('input');
            }
            this.setTemplateContent(example.template || '');
            Craft.cp.displayNotice(Craft.t('mailcraft', 'Example loaded. Review the content and save when you are happy with it.'));
        },

        getCkeditor: function () {
            var $field = $('#template').closest('.field');
            var editable = $field.find('.ck-editor__editable')[0];
            return editable && editable.ckeditorInstance ? editable.ckeditorInstance : null;
        },

        getTemplateContent: function () {
            var editor = this.getCkeditor();
            return editor ? editor.getData() : ($('#template').val() || '');
        },

        setTemplateContent: function (html) {
            var editor = this.getCkeditor();
            var $textarea = $('#template');
            if (editor) {
                editor.setData(html);
            } else {
                $textarea.val(html).trigger('input');
            }
            if ($textarea.length) {
                $textarea.val(html);
            }
        },

        // ───────────── Form data for preview / test ─────────────

        collectFormData: function () {
            var editor = this.getCkeditor();
            if (editor && typeof editor.updateSourceElement === 'function') {
                editor.updateSourceElement();
            }

            var data = {};
            var skip = { action: true, redirect: true };
            this.$form.serializeArray().forEach(function (pair) {
                if (skip[pair.name] || pair.name === Craft.csrfTokenName) {
                    return;
                }
                data[pair.name] = pair.value;
            });
            data.template = this.getTemplateContent();
            return data;
        },

        // ───────────── Preview ─────────────

        initPreview: function () {
            this.addListener($('#mailcraft-preview-btn'), 'click', 'showPreview');
        },

        showPreview: function () {
            var $btn = $('#mailcraft-preview-btn').addClass('loading');
            var self = this;

            Craft.sendActionRequest('POST', 'mailcraft/email-templates/preview', { data: this.collectFormData() })
                .then(function (response) {
                    var data = response.data || {};
                    if (!data.success) {
                        Craft.cp.displayError(data.error || Craft.t('mailcraft', 'Could not generate preview.'));
                        return;
                    }
                    self.openPreviewModal(data.subject || '', data.html || '');
                })
                .catch(function (error) {
                    var message = error && error.response && error.response.data && error.response.data.error;
                    Craft.cp.displayError(message || Craft.t('mailcraft', 'Could not generate preview.'));
                })
                .finally(function () {
                    $btn.removeClass('loading');
                });
        },

        openPreviewModal: function (subject, html) {
            if (!this.previewModal) {
                var $container = $('<div class="modal mailcraft-preview-modal"/>');
                var $head = $('<div class="mailcraft-preview__head"/>').appendTo($container);
                $('<h2 class="mailcraft-preview__subject"/>').appendTo($head);
                var $close = $('<button type="button" class="btn"/>').text(Craft.t('mailcraft', 'Close')).appendTo($head);
                $('<iframe class="mailcraft-preview__frame" sandbox="allow-same-origin"/>').attr('title', Craft.t('mailcraft', 'Email preview')).appendTo($container);
                this.previewModal = new Garnish.Modal($container, { autoShow: false, resizable: true });
                this.addListener($close, 'click', function () { this.previewModal.hide(); });
            }

            var $modal = this.previewModal.$container;
            $modal.find('.mailcraft-preview__subject').text(subject ? Craft.t('mailcraft', 'Preview: {subject}', { subject: subject }) : Craft.t('mailcraft', 'Preview'));
            $modal.find('iframe').attr('srcdoc', html);
            this.previewModal.show();
        },

        // ───────────── Send test ─────────────

        initSendTest: function () {
            this.addListener($('#mailcraft-send-test'), 'click', 'sendTest');
        },

        sendTest: function () {
            var $btn = $('#mailcraft-send-test');
            if ($btn.hasClass('loading')) {
                return;
            }
            $btn.addClass('loading');

            Craft.sendActionRequest('POST', 'mailcraft/email-templates/send-test', { data: this.collectFormData() })
                .then(function (response) {
                    var data = response.data || {};
                    if (data.success) {
                        Craft.cp.displayNotice(Craft.t('mailcraft', 'Test email sent to {email}.', { email: data.to }));
                    } else {
                        Craft.cp.displayError(data.error || Craft.t('mailcraft', 'The test email could not be sent.'));
                    }
                })
                .catch(function (error) {
                    var message = error && error.response && error.response.data && error.response.data.error;
                    Craft.cp.displayError(message || Craft.t('mailcraft', 'The test email could not be sent.'));
                })
                .finally(function () {
                    $btn.removeClass('loading');
                });
        },

        // ───────────── Sidebar summary ─────────────

        initSummaryBindings: function () {
            var self = this;
            $('#to').on('input change', function () {
                $('#mailcraft-summary-to').text($(this).val());
            });
            var updateFrom = function () {
                var from = $.trim($('#from').val());
                var name = $.trim($('#fromName').val());
                var text = from ? (name ? name + ' <' + from + '>' : from) : Craft.t('mailcraft', 'System default');
                $('#mailcraft-summary-from').text(text);
            };
            $('#from, #fromName').on('input change', updateFrom);
            updateFrom();
            self.updateDelaySummary();
        },
    });
})(jQuery);
