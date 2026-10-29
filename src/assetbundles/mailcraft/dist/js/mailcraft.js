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

    Craft.MailCraftEditor = Garnish.Base.extend({
        settings: null,
        $form: null,
        $event: null,
        $condition1: null,
        $condition2: null,
        previewModal: null,
        previewChannel: '',

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
                currentUserId: null,
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
            this.initChannels();
            this.initOverrides();

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

        // --- Event + conditions ---

        initEvent: function () {
            var eventSelect = this.$event[0].selectize;
            var self = this;

            eventSelect.on('change', function () {
                self.onEventChange();
                self.resetConditions();
                self.populateCondition1(self.$event.val());
            });

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

        // --- Delay ---

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

        // --- Variables panel ---

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
                        var fieldDef = def.fields[field];
                        var label = typeof fieldDef === 'string' ? fieldDef : (fieldDef && fieldDef.label) || field;
                        var snippet = (fieldDef && fieldDef.example) || ('{{ ' + key + '.' + field + ' }}');
                        add(snippet, label);
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

        // --- Quick picks ("To" of each channel, private message type) ---
        initQuickPicks: function () {
            var self = this;
            $(document).on('click', '.mailcraft-quick-picks button', function () {
                var target = $(this).closest('.mailcraft-quick-picks').data('target') || 'to';
                $('#' + target).val($(this).attr('data-to')).trigger('input');
            });
            $(document).on('click', '.mailcraft-vars__var', function () {
                self.copyVariable($(this).text(), $(this));
            });
        },

        renderQuickPicks: function (event) {
            var $picks = $('#mailcraft-to-picks');
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

            this.fillQuickPicks($picks, picks);

            var pmPicks = [];
            if (vars.user) {
                pmPicks.push({ label: Craft.t('mailcraft', 'The user'), to: '{{ user.id }}' });
            }
            if (vars.recipient) {
                pmPicks.push({ label: Craft.t('mailcraft', 'The user'), to: '{{ recipient.id }}' });
            }
            if (vars.order) {
                pmPicks.push({ label: Craft.t('mailcraft', 'The customer'), to: '{{ order.customer.id }}' });
            }
            if (vars.entry) {
                pmPicks.push({ label: Craft.t('mailcraft', 'The entry author'), to: '{{ entry.author.id }}' });
            }
            if (this.settings.currentUserId) {
                pmPicks.push({ label: Craft.t('mailcraft', 'Me (#{id})', { id: this.settings.currentUserId }), to: String(this.settings.currentUserId) });
            }
            this.fillQuickPicks($('#mailcraft-pm-to-picks'), pmPicks);

            var pmFromPicks = [];
            if (vars.sender) {
                pmFromPicks.push({ label: Craft.t('mailcraft', 'The sender'), to: '{{ sender.id ?? "" }}' });
            }
            if (this.settings.currentUserId) {
                pmFromPicks.push({ label: Craft.t('mailcraft', 'Me (#{id})', { id: this.settings.currentUserId }), to: String(this.settings.currentUserId) });
            }
            this.fillQuickPicks($('#mailcraft-pm-from-picks'), pmFromPicks);
        },

        fillQuickPicks: function ($picks, picks) {
            if (!$picks.length) {
                return;
            }
            $picks.find('button').remove();
            picks.forEach(function (pick) {
                $('<button type="button" class="btn small"/>').text(pick.label).attr('data-to', pick.to).appendTo($picks);
            });
            $picks.toggleClass('hidden', picks.length === 0);
        },

        // --- Example loading ---

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

        getCkeditor: function (id) {
            var $field = $(document.getElementById(id || 'template')).closest('.field');
            var editable = $field.find('.ck-editor__editable')[0];
            return editable && editable.ckeditorInstance ? editable.ckeditorInstance : null;
        },

        forEachCkeditor: function (fn) {
            this.$form.find('.ck-editor__editable').each(function () {
                if (this.ckeditorInstance) {
                    fn(this.ckeditorInstance);
                }
            });
        },

        getTemplateContent: function (id) {
            var editor = this.getCkeditor(id);
            return editor ? editor.getData() : ($(document.getElementById(id || 'template')).val() || '');
        },

        setTemplateContent: function (html, id) {
            var editor = this.getCkeditor(id);
            var $textarea = $(document.getElementById(id || 'template'));
            if (editor) {
                editor.setData(html);
            } else {
                $textarea.val(html).trigger('input');
            }
            if ($textarea.length) {
                $textarea.val(html);
            }
        },

        // --- Form data for preview / test ---

        collectFormData: function () {
            this.forEachCkeditor(function (editor) {
                if (typeof editor.updateSourceElement === 'function') {
                    editor.updateSourceElement();
                }
            });

            var data = { channels: {} };
            var skip = { action: true, redirect: true };
            var channelKey = /^channels\[([^\]]+)\]\[([^\]]+)\](?:\[([^\]]+)\])?$/;
            this.$form.serializeArray().forEach(function (pair) {
                if (skip[pair.name] || pair.name === Craft.csrfTokenName) {
                    return;
                }
                var match = channelKey.exec(pair.name);
                if (match) {
                    var settings = data.channels[match[1]] = data.channels[match[1]] || {};
                    if (match[3]) {
                        settings[match[2]] = $.isPlainObject(settings[match[2]]) ? settings[match[2]] : {};
                        settings[match[2]][match[3]] = pair.value;
                    } else {
                        settings[match[2]] = pair.value;
                    }
                    return;
                }
                data[pair.name] = pair.value;
            });
            data.template = this.getTemplateContent();
            return data;
        },

        // --- Preview ---

        initPreview: function () {
            this.addListener($('#mailcraft-preview-btn'), 'click', function () {
                this.showPreview(this.previewChannel);
            });
        },

        /**
         * @param {string} channel Handle of a channel whose content override to preview; '' for the main content
         */
        showPreview: function (channel) {
            var $btn = $('#mailcraft-preview-btn').addClass('loading');
            var self = this;

            channel = channel && this.getOverrideChannels()[channel] ? channel : '';
            this.previewChannel = channel;

            var data = this.collectFormData();
            data.channel = channel;

            Craft.sendActionRequest('POST', 'mailcraft/email-templates/preview', { data: data })
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
                var $inner = $('<div class="mailcraft-preview__inner"/>').appendTo($container);
                var $head = $('<div class="mailcraft-preview__head"/>').appendTo($inner);
                $('<h2 class="mailcraft-preview__subject"/>').appendTo($head);
                var $channel = $('<div class="select small mailcraft-preview__channel hidden"/>').appendTo($head);
                var $channelSelect = $('<select/>').attr('aria-label', Craft.t('mailcraft', 'Preview')).appendTo($channel);
                this.addListener($channelSelect, 'change', function () {
                    this.showPreview($channelSelect.val());
                });
                var $close = $('<button type="button" class="btn"/>').text(Craft.t('mailcraft', 'Close')).appendTo($head);
                $('<iframe class="mailcraft-preview__frame" sandbox="allow-same-origin"/>').attr('title', Craft.t('mailcraft', 'Email preview')).appendTo($inner);
                this.previewModal = new Garnish.Modal($container, { autoShow: false, resizable: true });
                this.addListener($close, 'click', function () { this.previewModal.hide(); });
            }

            var $modal = this.previewModal.$container;
            $modal.find('.mailcraft-preview__subject').text(subject ? Craft.t('mailcraft', 'Preview: {subject}', { subject: subject }) : Craft.t('mailcraft', 'Preview'));
            $modal.find('iframe').attr('srcdoc', html);
            this.fillPreviewChannels($modal.find('.mailcraft-preview__channel'));
            this.previewModal.show();
        },

        fillPreviewChannels: function ($wrapper) {
            var $select = $wrapper.find('select').empty();
            var channels = this.getOverrideChannels();
            $('<option value=""/>').text(Craft.t('mailcraft', 'Main content')).appendTo($select);
            $.each(channels, function (handle, name) {
                $('<option/>').attr('value', handle).text(name).appendTo($select);
            });
            $select.val(this.previewChannel);
            $wrapper.toggleClass('hidden', $.isEmptyObject(channels));
        },

        // --- Send test ---

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
                        Craft.cp.displayNotice(Craft.t('mailcraft', 'Test sent via {channels}.', { channels: (data.sent || []).join(', ') }));
                    }
                    if (!data.success || (data.errors && data.errors.length)) {
                        Craft.cp.displayError(data.error || (data.errors || []).join('\n') || Craft.t('mailcraft', 'The test email could not be sent.'));
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

        // --- Delivery channels ---

        initChannels: function () {
            var self = this;
            $('.mailcraft-channel').each(function () {
                var $tab = $(this);
                var $switch = $tab.find('.mailcraft-channel-switch .lightswitch');
                $switch.on('change', function () {
                    self.onChannelToggle($tab, $switch);
                });
            });
        },

        isSwitchOn: function ($switch) {
            var lightswitch = $switch.data('lightswitch');
            if (lightswitch) {
                return !!lightswitch.on;
            }
            return $switch.find('input').val() !== '';
        },

        onChannelToggle: function ($tab, $switch) {
            var handle = $tab.data('channel');
            var on = this.isSwitchOn($switch);

            $('#tabs a[href="#tab-channel-' + handle + '"]').toggleClass('mailcraft-channel-on', on);
            $tab.toggleClass('mailcraft-channel--off', !on);
            $('[data-channel-summary="' + handle + '"]').toggleClass('hidden', !on);

            this.updateChannelSummary();
        },

        updateChannelSummary: function () {
            var self = this;
            var names = [];
            $('.mailcraft-channel').each(function () {
                var $tab = $(this);
                if (self.isSwitchOn($tab.find('.mailcraft-channel-switch .lightswitch'))) {
                    names.push($tab.data('channel-name'));
                }
            });
            $('#mailcraft-summary-channels').text(names.length ? names.join(', ') : Craft.t('mailcraft', 'None'));
            $('#mailcraft-no-channel-warning').toggleClass('hidden', names.length > 0);
        },

        // --- Per-channel content overrides ---

        initOverrides: function () {
            var self = this;
            $('.mailcraft-override').each(function () {
                var $override = $(this);
                var handle = $override.data('override');
                var $switch = $override.closest('.mailcraft-channel').find('.mailcraft-override-switch .lightswitch');
                var bodyId = self.overrideBodyId(handle);

                $switch.on('change', function () {
                    $override.toggleClass('hidden', !self.isSwitchOn($switch));
                    self.updateOverrideTab(handle);
                });
                $(document.getElementById(self.overrideSubjectId(handle))).on('input change', function () {
                    self.updateOverrideTab(handle);
                });
                $(document.getElementById(bodyId)).on('input change', function () {
                    self.updateOverrideTab(handle);
                });
                // CKEditor starts asynchronously; listen to it once it is there
                self.whenReady(
                    function () { return self.getCkeditor(bodyId); },
                    function () {
                        self.getCkeditor(bodyId).model.document.on('change:data', function () {
                            self.updateOverrideTab(handle);
                        });
                    }
                );
            });

            this.addListener($('[data-override-copy]'), 'click', function (ev) {
                this.copyMainContent($(ev.currentTarget).data('override-copy'));
            });
        },

        overrideSubjectId: function (handle) {
            return 'channels-' + handle + '-_content-subject';
        },

        overrideBodyId: function (handle) {
            return 'channels-' + handle + '-_content-body';
        },

        /**
         * Whether a channel's override is switched on and has a subject or body.
         */
        hasOverride: function (handle) {
            var $override = $('.mailcraft-override[data-override="' + handle + '"]');
            if (!$override.length) {
                return false;
            }
            if (!this.isSwitchOn($override.closest('.mailcraft-channel').find('.mailcraft-override-switch .lightswitch'))) {
                return false;
            }
            var subject = $.trim($(document.getElementById(this.overrideSubjectId(handle))).val() || '');
            var body = $.trim(this.getTemplateContent(this.overrideBodyId(handle)));
            return subject !== '' || body !== '';
        },

        updateOverrideTab: function (handle) {
            $('#tabs a[href="#tab-channel-' + handle + '"]').toggleClass('mailcraft-channel-override', this.hasOverride(handle));
        },

        /**
         * Channels with an active override, `handle => name`.
         */
        getOverrideChannels: function () {
            var self = this;
            var channels = {};
            $('.mailcraft-override').each(function () {
                var handle = $(this).data('override');
                if (self.hasOverride(handle)) {
                    channels[handle] = $(this).closest('.mailcraft-channel').data('channel-name');
                }
            });
            return channels;
        },

        copyMainContent: function (handle) {
            var $subject = $(document.getElementById(this.overrideSubjectId(handle)));
            var bodyId = this.overrideBodyId(handle);
            var hasContent = $.trim($subject.val() || '') !== '' || $.trim(this.getTemplateContent(bodyId)) !== '';
            if (hasContent && !confirm(Craft.t('mailcraft', 'Replace this channel’s subject and body with the main content?'))) {
                return;
            }

            $subject.val($('#subject').val()).trigger('input');
            this.setTemplateContent(this.getTemplateContent(), bodyId);
            this.updateOverrideTab(handle);
        },

        // --- Sidebar summary ---

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
