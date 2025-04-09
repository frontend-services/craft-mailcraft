(function($) {
    'use strict';

    var EditorInstance = null;

    // ignore phpstorm warning "Void function return value is used "
    // noinspection JSUnusedGlobalSymbols
    Craft.MailCraftEditor = Garnish.Base.extend({
        init: function(settings) {
            this.settings = settings;
            this.$editor = $('#template');

            // Initialize variable suggestions
            this.initVariableSuggestions();

            // Initialize event handlers
            if (settings.templateId) {
                new Craft.MailCraftPreview(settings.templateId);
            }

            // Initialize example selector
            this.initExampleSelector();
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
                            $('#event')[0].selectize.setValue(response[example].id);
                            if (response[example].to ?? false) {
                                $('#to').val(response[example].to);
                            }

                            const template = document.querySelector('#template');
                            // if template is textarea, set value directly
                            if (template.tagName === 'TEXTAREA') {
                                template.innerHTML = response[example].template;
                                // check if there's a ckeditor next to the textarea
                                const ckeditorWrapper = template.nextElementSibling;
                                if (ckeditorWrapper && ckeditorWrapper.classList.contains('ck-editor')) {
                                    const editor = ckeditorWrapper.querySelector('.ck-editor__editable');
                                    if (editor && editor.ckeditorInstance) {
                                        editor.ckeditorInstance.setData(response[example].template);
                                    }
                                }
                            }
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