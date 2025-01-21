(function($) {
    'use strict';

    // Initialize preview functionality
    Craft.MailCraftPreview = Garnish.Base.extend({
        init: function(templateId) {
            this.templateId = templateId;
            this.$previewBtn = $('#preview-btn');
            this.$previewContainer = $('#preview-container');

            this.addListener(this.$previewBtn, 'click', 'showPreview');
        },

        showPreview: function() {
            var data = {
                id: this.templateId
            };

            Craft.postActionRequest('mailcraft/email-templates/preview', data, $.proxy(function(response) {
                if (response.success) {
                    this.$previewContainer.html(response.html);
                    this.$previewContainer.removeClass('hidden');
                } else {
                    Craft.cp.displayError(response.error || Craft.t('mailcraft', 'Could not generate preview.'));
                }
            }, this));
        }
    });

    // Initialize event-specific fields
    Craft.MailCraftEventFields = Garnish.Base.extend({
        init: function() {
            this.$eventSelect = $('#event');
            this.$templateField = $('#template');

            this.addListener(this.$eventSelect, 'change', 'updateHelpText');
        },

        updateHelpText: function() {
            var event = this.$eventSelect.val();
            var variables = this.getEventVariables(event);

            // Update the template field help text with available variables
            var helpText = Craft.t('mailcraft', 'Available variables:') + '\n';
            for (var key in variables) {
                helpText += '{{ ' + key + ' }} - ' + variables[key] + '\n';
            }

            this.$templateField.siblings('.instructions').text(helpText);
        },

        getEventVariables: function(event) {
            var variables = {
                'now': 'Current date/time',
                'siteUrl': 'Site URL',
                'siteName': 'Site name'
            };

            switch (event) {
                case 'create_user':
                case 'update_user':
                    variables.user = 'User model';
                    break;

                case 'create_entry':
                case 'update_entry':
                case 'delete_entry':
                    variables.entry = 'Entry model';
                    break;

                case 'commerce_order_complete':
                    variables.order = 'Order model';
                    break;

                case 'commerce_order_status_change':
                    variables.order = 'Order model';
                    variables.oldStatus = 'Previous order status';
                    variables.newStatus = 'New order status';
                    break;
            }

            return variables;
        }
    });

})(jQuery);