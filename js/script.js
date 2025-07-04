jQuery(function($) {
    const $uploadArea = $('#doc2pdf-upload-area');
    const $fileInput = $('#doc2pdf-file');
    const $convertBtn = $('#doc2pdf-convert-btn');
    const $progress = $('#doc2pdf-progress');
    const $result = $('#doc2pdf-result');
    const $error = $('#doc2pdf-error');
    const $downloadBtn = $('#download-pdf-btn');
    const $modal = $('#doc2pdf-modal');
    const $modalMessage = $('#modal-message');
    let pdfUrl = null;

    // Drag and drop handling
    $uploadArea
        .on('dragover', function(e) {
            e.preventDefault();
            $(this).addClass('dragover');
        })
        .on('dragleave', function(e) {
            e.preventDefault();
            $(this).removeClass('dragover');
        })
        .on('drop', function(e) {
            e.preventDefault();
            $(this).removeClass('dragover');
            if (e.originalEvent.dataTransfer.files.length) {
                $fileInput[0].files = e.originalEvent.dataTransfer.files;
                $convertBtn.prop('disabled', false);
                $error.hide();
            }
        })
        .on('click', function() {
            console.log('Drop box area clicked');
            // Use native click method on the DOM element
            $fileInput[0].click();
        });

    // File selection
    $fileInput.on('change', function() {
        if (this.files.length) {
            $convertBtn.prop('disabled', false);
            $error.hide();
            // Display selected file name inside the drop box area
            const fileName = this.files[0].name;
            $uploadArea.text(fileName);
        }
    });

    // Conversion handler
    $convertBtn.on('click', function() {
        if (!$fileInput[0].files.length) return;

        $progress.show();
        $result.hide();
        $convertBtn.prop('disabled', true);

        const formData = new FormData();
        formData.append('file', $fileInput[0].files[0]);
        formData.append('action', 'doc2pdf_convert');
        formData.append('security', doc2pdf_vars.nonce);

        $.ajax({
            url: doc2pdf_vars.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    pdfUrl = response.data.pdf_url;
                    $('#result-message').text(response.data.message);
                    $result.show();
                    
                    // Initialize download button
                    $downloadBtn.off('click').on('click', function() {
                        downloadPDF(response.data.pdf_name);
                    });
                } else {
                    showModal(response.data.message || 'Conversion failed');
                }
            },
            error: function() {
                showModal('Server error occurred');
            },
            complete: function() {
                $progress.hide();
                $convertBtn.prop('disabled', false);
            }
        });
    });

    // Trigger file input when "Select WORD files" button is clicked
    $convertBtn.on('click', function(e) {
        if ($fileInput[0].files.length === 0) {
            e.preventDefault();
            $fileInput[0].click();
        }
    });

    // Google Drive button click handler
    let googlePickerApiLoaded = false;
    let oauthToken;

    function loadGooglePicker() {
        gapi.load('auth', {'callback': onAuthApiLoad});
        gapi.load('picker', {'callback': onPickerApiLoad});
    }

    function onAuthApiLoad() {
        window.gapi.auth.authorize(
            {
                'client_id': 'YOUR_GOOGLE_CLIENT_ID',
                'scope': ['https://www.googleapis.com/auth/drive.file'],
                'immediate': false
            },
            handleAuthResult);
    }

    function onPickerApiLoad() {
        googlePickerApiLoaded = true;
        createPicker();
    }

    function handleAuthResult(authResult) {
        if (authResult && !authResult.error) {
            oauthToken = authResult.access_token;
            createPicker();
        }
    }

    function createPicker() {
        if (googlePickerApiLoaded && oauthToken) {
            const picker = new google.picker.PickerBuilder()
                .addView(google.picker.ViewId.DOCS)
                .setOAuthToken(oauthToken)
                .setDeveloperKey('YOUR_GOOGLE_DEVELOPER_KEY')
                .setCallback(pickerCallback)
                .build();
            picker.setVisible(true);
        }
    }

    function pickerCallback(data) {
        if (data.action === google.picker.Action.PICKED) {
            const fileId = data.docs[0].id;
            alert('Selected file ID: ' + fileId);
            // TODO: Download file and trigger conversion
        }
    }

    $('#google-drive-btn').on('click', function() {
        loadGooglePicker();
    });

    // Dropbox button click handler
    var dropboxOptions = {
        success: function(files) {
            alert("Selected file: " + files[0].name);
            // TODO: Download file and trigger conversion
        },
        linkType: "direct",
        multiselect: false,
        extensions: ['.doc', '.docx', '.odt', '.rtf', '.txt']
    };

    $('#dropbox-btn').on('click', function() {
        Dropbox.choose(dropboxOptions);
    });

    // Download PDF function
    function downloadPDF(filename) {
        if (!pdfUrl) {
            showModal('No PDF available for download');
            return;
        }

        const link = document.createElement('a');
        link.href = pdfUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        // Verify if download was successful
        setTimeout(function() {
            $.get(pdfUrl)
                .fail(function() {
                    showModal('Could not download PDF - file may have been removed');
                });
        }, 2000);
    }

    // Show modal message
    function showModal(message) {
        $modalMessage.text(message);
        $modal.fadeIn();
        
        $('.close-modal, #doc2pdf-modal').on('click', function() {
            $modal.fadeOut();
        });
    }
});
