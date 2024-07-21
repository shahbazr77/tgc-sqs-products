jQuery(document).ready(function ($) {
    $('#import-products-form').on('submit', function (e) {
        e.preventDefault();
        jQuery(".sqsoverlay_loader").show();
        $('#progress-container').show();
        $('#progress-bar').css('width', '0%');
        $('#progress-message').text('Importing products...');

        processStep(0);
    });

    function processStep(step) {
        $.ajax({
            url: object_squarespace.ajax_url,
            type: 'POST',
            data: {
                action: 'vendor_squarespace_import_products',
                nonce: object_squarespace.nonce,
                step: step,
            },
            success: function (response) {
                if (response.success) {
                    var progress = response.data.progress;
                    var message = response.data.message;

                    $('#progress-bar').css('width', progress + '%');
                    $('#progress-message').text(message);

                    if (response.data.step !== 'done') {
                        processStep(response.data.step);
                    } else {
                        jQuery(".sqsoverlay_loader").hide();
                        $('#progress-message').text('Import completed!');
                        jQuery("#progress-container").hide(2000);
                    }
                } else {
                    jQuery(".sqsoverlay_loader").hide();
                    jQuery("#progress-container").hide();
                    $('#progress-message').text('Error: ' + response.data);
                }
            },
            error: function () {
                jQuery(".sqsoverlay_loader").hide();
                jQuery("#progress-container").hide();
                $('#progress-message').text('An error occurred while processing.');
            }
        });
    }



    $('.sync-with-squarespace').on('click', function() {
        var button = $(this);
        var productId = button.data('product-id');
        var nonce = button.data('nonce');

        button.prop('disabled', true);
        button.text('Syncing...');

        $.ajax({
            url: object_squarespace.ajax_url,
            type: 'POST',
            data: {
                action: 'sync_single_product_with_squarespace',
                product_id: productId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    button.text('Synced');
                } else {
                    button.prop('disabled', false);
                    button.text('Sync');
                    alert('Sync failed: ' + response.data);
                }
            }
        });
    });



});