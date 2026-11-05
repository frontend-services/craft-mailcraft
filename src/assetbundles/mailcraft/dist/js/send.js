(function ($) {
    'use strict';

    if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
        return;
    }

    /**
     * The Send page
     */
    Craft.MailCraftSend = Garnish.Base.extend({
        settings: null,
        $form: null,
        $audience: null,
        $count: null,
        $sample: null,
        $sampleToggle: null,
        sampleOpen: false,
        countTimeout: null,
        countRequest: 0,
        countLabel: '',
        allowSubmit: false,
        submitting: false,
        previewModal: null,
        confirmModal: null,

        init: function (settings) {
            this.settings = $.extend({
                countUrl: null,
                variables: {},
                templates: [],
                channels: {},
                currentUserEmail: '',
            }, settings || {});

            this.$audience = $('#mailcraft-audience');
            this.$form = this.$audience.closest('form');
            this.$count = this.$audience.find('.mailcraft-audience-count__label');
            this.$sample = this.$audience.find('.mailcraft-audience-sample');
            this.$sampleToggle = this.$sample.find('button');
            this.sampleOpen = false;

            // changes in the audience form
            this.$audience.on('change input htmx:afterSwap', $.proxy(this.scheduleCount, this));
            this.addListener($('input[name="mode"]'), 'change', 'onModeChange');
            this.addListener($('#templateId'), 'change', 'onTemplateChange');
            this.addListener($('input[name="channels[]"]'), 'change', 'onChannelChange');
            this.addListener($('#mailcraft-preview-btn'), 'click', 'showPreview');
            this.addListener($('#mailcraft-send-test'), 'click', 'sendTest');
            this.addListener($('.mailcraft-send-cancel'), 'click', 'cancelSend');
            this.addListener(this.$form, 'submit', 'onSubmit');
            this.addListener(this.$sampleToggle, 'click', 'toggleSample');

            this.renderVariables();
            this.count();
        },

        // --- Segmented users count ---

        scheduleCount: function () {
            clearTimeout(this.countTimeout);
            this.countTimeout = setTimeout($.proxy(this.count, this), 400);
        },

        /**
         * @return {Promise} resolves with the response data
         */
        count: function () {
            var self = this;
            var request = ++this.countRequest;
            this.$count.text(Craft.t('mailcraft', 'Counting...'));

            return Craft.sendActionRequest('POST', this.settings.countUrl || 'mailcraft/send/count', {
                data: this.$audience.find(':input').serialize(),
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            })
                .then(function (response) {
                    var data = response.data || {};
                    if (request === self.countRequest) {
                        self.countLabel = data.success ? data.label : '';
                        self.$count.text(data.success ? data.label : Craft.t('mailcraft', 'Cannot count: fix the audience above.'));
                        self.renderSample(data.success ? (data.sample || []) : []);
                    }
                    return data;
                })
                .catch(function () {
                    if (request === self.countRequest) {
                        self.countLabel = '';
                        self.$count.text(Craft.t('mailcraft', 'Could not count the audience.'));
                        self.renderSample([]);
                    }
                    return { success: false };
                });
        },

        /**
         * Sample of the audience.
         */
        renderSample: function (sample) {
            var $list = this.$sample.find('ul').empty();
            if (!sample.length) {
                this.$sampleToggle.addClass('hidden');
                $list.addClass('hidden');
                return;
            }
            sample.forEach(function (row) {
                var $li = $('<li/>').append($('<strong/>').text(row.name || row.email));
                if (row.email && row.email !== row.name) {
                    $('<span class="light"/>').text(' [' + row.email + ']').appendTo($li);
                }
                $list.append($li);
            });
            this.$sampleToggle.removeClass('hidden');
            this.updateSampleToggle(sample.length);
        },

        toggleSample: function () {
            this.sampleOpen = !this.sampleOpen;
            this.updateSampleToggle(this.$sample.find('li').length);
        },

        updateSampleToggle: function (n) {
            this.$sample.find('ul').toggleClass('hidden', !this.sampleOpen);
            this.$sampleToggle
                .text(this.sampleOpen ? Craft.t('mailcraft', 'Hide') : Craft.t('mailcraft', 'Show the first {n}', { n: n }))
                .attr('aria-expanded', this.sampleOpen ? 'true' : 'false');
        },

        // --- Variables ---

        renderVariables: function () {
            var $list = $('#mailcraft-send-vars-list');
            if (!$list.length) {
                return;
            }
            var vars = this.settings.variables || {};
            var add = function (variable, description) {
                var $li = $('<li/>');
                $('<button type="button" class="mailcraft-vars__var code"/>').text(variable).appendTo($li);
                if (description) {
                    $('<span class="mailcraft-vars__desc light"/>').text(description).appendTo($li);
                }
                $list.append($li);
            };
            Object.keys(vars).forEach(function (key) {
                var def = vars[key] || {};
                if (def.fields && typeof def.fields === 'object') {
                    Object.keys(def.fields).forEach(function (field) {
                        var fieldDef = def.fields[field];
                        var label = typeof fieldDef === 'string' ? fieldDef : (fieldDef && fieldDef.label) || field;
                        add((fieldDef && fieldDef.example) || ('{{ ' + key + '.' + field + ' }}'), label);
                    });
                } else {
                    add(def.example || ('{{ ' + key + ' }}'), def.description || '');
                }
            });
            $list.on('click', '.mailcraft-vars__var', function () {
                Craft.MailCraftEditor.prototype.copyVariable($(this).text(), $(this));
            });
        },

        // --- What ---

        getMode: function () {
            return $('input[name="mode"]:checked').val() || 'custom';
        },

        getSelectedTemplate: function () {
            var id = parseInt($('#templateId').val(), 10);
            for (var i = 0; i < this.settings.templates.length; i++) {
                if (this.settings.templates[i].id === id) {
                    return this.settings.templates[i];
                }
            }
            return null;
        },

        onModeChange: function () {
            var mode = this.getMode();
            $('#mailcraft-mode-template').toggleClass('hidden', mode !== 'template');
            $('#mailcraft-mode-custom').toggleClass('hidden', mode !== 'custom');

            // Email template can only send to its own channels
            var template = mode === 'template' ? this.getSelectedTemplate() : null;
            $('input[name="channels[]"]').each(function () {
                var disabled = !!template && template.channels.indexOf(this.value) === -1;
                $(this).prop('disabled', disabled);
                if (disabled) {
                    $(this).prop('checked', false);
                }
            });
            this.onChannelChange();
        },

        onTemplateChange: function () {
            var template = this.getSelectedTemplate();
            this.onModeChange();

            var $edit = $('#mailcraft-edit-template');
            $edit.toggleClass('hidden', !template);
            if (template) {
                $edit.attr('href', Craft.getCpUrl('mailcraft/email-templates/' + template.id));
            }
        },

        onChannelChange: function () {
            $('#mailcraft-pm-type, #mailcraft-pm-meta').toggleClass('hidden', !$('#mailcraft-channel-privateMessage').prop('checked'));
        },

        getCheckedChannelNames: function () {
            var names = [];
            var channels = this.settings.channels;
            $('input[name="channels[]"]:checked').each(function () {
                names.push(channels[this.value] || this.value);
            });
            return names;
        },

        // --- Body editor ---

        getCkeditor: function () {
            var editable = $(document.getElementById('template')).closest('.field').find('.ck-editor__editable')[0];
            return editable && editable.ckeditorInstance ? editable.ckeditorInstance : null;
        },

        getTemplateContent: function () {
            var editor = this.getCkeditor();
            return editor ? editor.getData() : ($(document.getElementById('template')).val() || '');
        },

        /**
         * The page's fields for the test and preview requests
         */
        collectFormData: function () {
            var pairs = this.$form.serializeArray().filter(function (pair) {
                return pair.name !== 'action' && pair.name !== 'template' && pair.name !== Craft.csrfTokenName;
            });
            pairs.push({ name: 'template', value: this.getTemplateContent() });
            return $.param(pairs);
        },

        postForm: function (action) {
            return Craft.sendActionRequest('POST', action, {
                data: this.collectFormData(),
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            });
        },

        // --- Preview ---

        showPreview: function () {
            var $btn = $('#mailcraft-preview-btn');
            if ($btn.hasClass('loading')) {
                return;
            }
            $btn.addClass('loading');
            var self = this;

            this.postForm('mailcraft/send/preview')
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
                var $close = $('<button type="button" class="btn"/>').text(Craft.t('mailcraft', 'Close')).appendTo($head);
                $('<iframe class="mailcraft-preview__frame" sandbox="allow-same-origin"/>').attr('title', Craft.t('mailcraft', 'Email preview')).appendTo($inner);
                this.previewModal = new Garnish.Modal($container, { autoShow: false, resizable: true });
                this.addListener($close, 'click', function () { this.previewModal.hide(); });
            }

            var $modal = this.previewModal.$container;
            $modal.find('.mailcraft-preview__subject').text(subject ? Craft.t('mailcraft', 'Preview: {subject}', { subject: subject }) : Craft.t('mailcraft', 'Preview'));
            $modal.find('iframe').attr('srcdoc', html);
            this.previewModal.show();
        },

        // --- Test send ---

        sendTest: function () {
            var $btn = $('#mailcraft-send-test');
            if ($btn.hasClass('loading')) {
                return;
            }
            $btn.addClass('loading');

            this.postForm('mailcraft/send/test')
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

        // --- Submitting ---

        /**
         * Posts the page with the given action.
         */
        submitWith: function (action, params) {
            if (this.submitting) {
                return;
            }
            this.submitting = true;
            this.$form.find('input[name="action"]').val(action);
            var $form = this.$form;
            $.each(params || {}, function (name, value) {
                $('<input type="hidden"/>').attr('name', name).val(value).appendTo($form);
            });
            this.allowSubmit = true;
            this.$form.trigger('submit');
        },

        onSubmit: function (ev) {
            if (this.allowSubmit) {
                return;
            }
            // the Send button, Enter in a field or a save shortcut, confirm first
            ev.preventDefault();
            ev.stopImmediatePropagation();
            this.openConfirmModal();
        },

        getSubject: function () {
            if (this.getMode() === 'template') {
                var template = this.getSelectedTemplate();
                return template ? template.title : '';
            }
            return $('#subject').val() || '';
        },

        openConfirmModal: function () {
            var $btn = $('#mailcraft-send');
            if ($btn.hasClass('loading')) {
                return;
            }
            $btn.addClass('loading');
            var self = this;

            this.count().then(function (data) {
                $btn.removeClass('loading');
                if (!data.success) {
                    Craft.cp.displayError((data.problems || []).join('\n') || Craft.t('mailcraft', 'Could not count the audience.'));
                    return;
                }

                if (!self.confirmModal) {
                    var $container = $('<div class="modal fitted mailcraft-send-confirm"/>');
                    var $body = $('<div class="body"/>').appendTo($container);
                    $('<p class="mailcraft-send-confirm__text"/>').appendTo($body);
                    var $buttons = $('<div class="buttons right"/>').appendTo($('<div class="footer"/>').appendTo($container));
                    var $cancel = $('<button type="button" class="btn"/>').text(Craft.t('mailcraft', 'Cancel')).appendTo($buttons);
                    var $send = $('<button type="button" class="btn submit"/>').text(Craft.t('mailcraft', 'Send')).appendTo($buttons);
                    self.confirmModal = new Garnish.Modal($container, { autoShow: false });
                    self.addListener($cancel, 'click', function () { this.confirmModal.hide(); });
                    self.addListener($send, 'click', function () {
                        $send.addClass('loading');
                        this.submitWith('mailcraft/send/send');
                    });
                }

                self.confirmModal.$container.find('.mailcraft-send-confirm__text').text(Craft.t('mailcraft', 'Send "{subject}" to about {count} via {channels}?', {
                    subject: self.getSubject(),
                    count: data.label,
                    channels: self.getCheckedChannelNames().join(', '),
                }));
                self.confirmModal.show();
            });
        },

        cancelSend: function (ev) {
            var $btn = $(ev.currentTarget);
            if (!window.confirm(String($btn.data('confirm')))) {
                return;
            }
            this.submitWith('mailcraft/send/cancel', { id: $btn.data('id') });
        },
    });
})(jQuery);
