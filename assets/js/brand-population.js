jQuery(document).ready(function($) {
    console.log('Script loaded and ready');

    // Debug button presence
    console.log('Start button exists:', $('#start-population').length > 0);
    
    // Add click listener to body to verify event propagation
    $('body').on('click', '#start-population', function(e) {
        console.log('Button clicked through body delegation');
    });

    let isProcessing = false;
    let processedCount = 0;
    let totalBrands = 0;

    $('#start-population').on('click', function(e) {
        console.log('Direct button click detected');
        e.preventDefault();
        
        console.log('Ajax URL:', brandPopulation.ajaxurl);
        console.log('Nonce:', brandPopulation.nonce);
        
        if (isProcessing) {
            console.log('Already processing');
            return;
        }
        
        isProcessing = true;
        processedCount = 0;
        $('.brand-population-progress').show();
        $(this).hide();
        $('#stop-population').show();
        $('.log-entries').empty();

        populateBrandsBatch();
    });

    function populateBrandsBatch() {
        console.log('Starting batch with offset:', processedCount);
        
        if (!isProcessing) {
            console.log('Processing stopped');
            return;
        }

        $.ajax({
            url: brandPopulation.ajaxurl,
            type: 'POST',
            data: {
                action: 'populate_brands_batch',
                nonce: brandPopulation.nonce,
                offset: processedCount
            },
            beforeSend: function() {
                console.log('Sending AJAX request');
            },
            success: function(response) {
                console.log('AJAX response received:', response);
                
                if (response.success) {
                    processedCount += response.data.processed;
                    totalBrands = response.data.total;
                    
                    console.log(`Processed ${processedCount} of ${totalBrands} brands`);
                    
                    updateProgress(processedCount, totalBrands);
                    
                    response.data.log.forEach(function(entry) {
                        addLogEntry(entry);
                    });

                    if (processedCount < totalBrands && isProcessing) {
                        console.log('Scheduling next batch');
                        setTimeout(populateBrandsBatch, 1000);
                    } else {
                        console.log('Process complete');
                        isProcessing = false;
                        $('#stop-population').hide();
                        $('#start-population').show();
                        addLogEntry('Process completed');
                    }
                } else {
                    console.error('AJAX error response:', response);
                    addLogEntry('Error: ' + response.data);
                    isProcessing = false;
                    $('#stop-population').hide();
                    $('#start-population').show();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX error:', textStatus, errorThrown);
                console.error('Response:', jqXHR.responseText);
                addLogEntry('Error occurred');
                isProcessing = false;
                $('#stop-population').hide();
                $('#start-population').show();
            }
        });
    }

    function updateProgress(processed, total) {
        const percentage = (processed / total) * 100;
        console.log(`Updating progress bar to ${percentage}%`);
        $('.progress').css('width', percentage + '%');
        $('.processed-count').text(processed);
        $('.total-count').text(total);
    }

    function addLogEntry(message) {
        console.log('Adding log entry:', message);
        $('.log-entries').prepend('<p>' + message + '</p>');
    }
});