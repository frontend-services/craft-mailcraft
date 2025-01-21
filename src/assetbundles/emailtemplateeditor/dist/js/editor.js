(function($) {
    'use strict';

    var EditorInstance = null;

    Craft.MailCraftEditor = Garnish.Base.extend({
        init: function(settings) {
            this.settings = settings;
            this.$editor = $('#template');

            // Initialize CodeMirror
            // this.initCodeMirror();

            // Initialize variable suggestions
            this.initVariableSuggestions();

            // Initialize event handlers
            if (settings.templateId) {
                new Craft.MailCraftPreview(settings.templateId);
            }
            // new Craft.MailCraftEventFields();

            // Initialize example selector
            this.initExampleSelector();
        },

        initCodeMirror: function() {
            if (this.$editor.length) {
                this.editor = CodeMirror.fromTextArea(this.$editor[0], {
                    mode: 'twig',
                    theme: 'craft',
                    lineNumbers: true,
                    lineWrapping: true,
                    viewportMargin: Infinity,
                    indentUnit: 4,
                    indentWithTabs: false,
                    matchBrackets: true,
                    autoCloseBrackets: true,
                    autoCloseTags: true,
                    extraKeys: {
                        'Ctrl-Space': 'autocomplete'
                    }
                });
            }
        },

        initVariableSuggestions: function() {
            if (this.editor) {
                this.editor.on('keyup', function(cm, event) {
                    if (!cm.state.completionActive && event.key === '{') {
                        CodeMirror.commands.autocomplete(cm, null, {completeSingle: false});
                    }
                });
            }
        },

        initExampleSelector: function() {
            var $select = $('#example-selector');

            if ($select.length) {
                $select.on('change', function() {
                    var example = $(this).val();
                    if (!example) return;

                    Craft.postActionRequest('mailcraft/email-templates/get-examples', { example: example }, function(response) {
                        if (response && response[example]) {
                            $('#title').val(response[example].title);
                            $('#subject').val(response[example].subject);
                            $('#event').val(response[example].event).trigger('change');
                            EditorInstance.editor.setValue(response[example].template);
                        }
                    });
                });
            }
        }
    });

    // Initialize editor when document is ready
    $(document).ready(function() {
        if (typeof Craft.MailCraftEditor === 'undefined') {
            return;
        }

        EditorInstance = new Craft.MailCraftEditor(window.mailCraftEditorSettings || {});
    });

})(jQuery);