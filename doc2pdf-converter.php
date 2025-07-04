<?php
/**
 * Plugin Name: Doc2PDF Converter
 * Description: Convert DOC, DOCX, TXT, RTF, ODT to PDF
 * Version: 1.1
 * Author: Dilmi Senevirathna
 */




if (!defined('ABSPATH')) exit;

// Constants
define('DOC2PDF_TEMP_DIR', wp_upload_dir()['basedir'] . '/doc2pdf_temp/');
define('DOC2PDF_TEMP_URL', wp_upload_dir()['baseurl'] . '/doc2pdf_temp/');
define('ALLOWED_TYPES', [
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'odt'  => 'application/vnd.oasis.opendocument.text',
    'rtf'  => 'application/rtf',
    'txt'  => 'text/plain'
]);

// Ensure temp directory exists
if (!file_exists(DOC2PDF_TEMP_DIR)) {
    wp_mkdir_p(DOC2PDF_TEMP_DIR);
}

// Enqueue assets
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('doc2pdf-css', plugin_dir_url(__FILE__) . 'css/style.css');
    wp_enqueue_script('doc2pdf-js', plugin_dir_url(__FILE__) . 'js/script.js', ['jquery'], null, true);
    wp_localize_script('doc2pdf-js', 'doc2pdf_vars', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('doc2pdf_nonce')
    ]);

    wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css');

    // Enqueue Dropbox Chooser SDK
    wp_enqueue_script('dropbox-chooser', 'https://www.dropbox.com/static/api/2/dropins.js', [], null, true);
    wp_add_inline_script('dropbox-chooser', 'Dropbox.appKey = "";');

    // Enqueue Google API client and picker
    wp_enqueue_script('google-api-client', 'https://apis.google.com/js/api.js', [], null, true);
});

// Shortcode
add_shortcode('doc2pdf', function() {
    ob_start(); ?>
    <div class="doc2pdf-container">
        <h2>Convert WORD to PDF</h2>
        <p class="subtitle">Make DOC and DOCX files easy to read by converting them to PDF.</p>
        <div id="doc2pdf-upload-area" class="upload-area">
            <input type="file" id="doc2pdf-file" accept=".doc,.docx,.odt,.rtf,.txt">
            <p1>Drag or drop WORD documents here</p>
        </div>
        <div class="button-group">
            <button id="doc2pdf-convert-btn" disabled> Convert_files</button>
        </div>

        
        <div class="cloud-buttons-horizontal">
  <button class="cloud-btn" title="Google Drive"> <i class="fa fa-google drive-icon"></i>
  </button>
  <button class="cloud-btn" title="Dropbox"><i class="fa fa-dropbox dropbox-icon" aria-hidden="true"></i>
  </button>
</div>


        <div id="doc2pdf-progress" style="display:none;">
            <div class="progress-bar"></div>
            <span class="progress-text">Converting...</span>
        </div>
        <div id="doc2pdf-result" style="display:none;">
            <p id="result-message"></p>
            <button id="download-pdf-btn" class="button">Download PDF</button>
        </div>
        <div id="doc2pdf-error" class="error-message" style="display:none;"></div>
    </div>
    <div id="doc2pdf-modal" style="display:none;">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <p id="modal-message"></p>
        </div>
    </div>
    <?php return ob_get_clean();
});

// Handle conversion
add_action('wp_ajax_doc2pdf_convert', 'handle_conversion');
add_action('wp_ajax_nopriv_doc2pdf_convert', 'handle_conversion');

function handle_conversion() {
    check_ajax_referer('doc2pdf_nonce', 'security');
    
    if (!isset($_FILES['file'])) {
        wp_send_json_error(['message' => 'No file uploaded']);
    }

    $file = $_FILES['file'];
    
    // Validate file type
    if (!in_array($file['type'], ALLOWED_TYPES)) {
        wp_send_json_error(['message' => 'Invalid file type']);
    }

    try {
        $original_name = sanitize_file_name($file['name']);
        $temp_name = uniqid() . '_' . $original_name;
        $input_path = DOC2PDF_TEMP_DIR . $temp_name;
        $pdf_path = DOC2PDF_TEMP_DIR . pathinfo($temp_name, PATHINFO_FILENAME) . '.pdf';

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $input_path)) {
            throw new Exception('File upload failed');
        }

        // Try LibreOffice first
        if (file_exists('C:\Program Files\LibreOffice\program\soffice.exe')) {
            $cmd = '"C:\Program Files\LibreOffice\program\soffice" --headless --convert-to pdf "' . 
                   $input_path . '" --outdir "' . DOC2PDF_TEMP_DIR . '"';
            exec($cmd, $output, $return_code);
            
            if ($return_code !== 0 || !file_exists($pdf_path)) {
                // Try PHPWord fallback if LibreOffice fails
                $pdf_path = convert_via_phpword($input_path);
            }
        } else {
            $pdf_path = convert_via_phpword($input_path);
        }

        // Verify PDF was created
        if (!file_exists($pdf_path)) {
            throw new Exception('Conversion failed - no output file');
        }

        wp_send_json_success([
            'pdf_url' => DOC2PDF_TEMP_URL . basename($pdf_path),
            'pdf_name' => pathinfo($original_name, PATHINFO_FILENAME) . '.pdf',
            'message' => 'Conversion successful!'
        ]);

    } catch (Exception $e) {
        // Clean up any files
        if (isset($input_path) && file_exists($input_path)) unlink($input_path);
        if (isset($pdf_path) && file_exists($pdf_path)) unlink($pdf_path);
        
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// PHPWord conversion fallback
function convert_via_phpword($input_path) {
    if (!class_exists('PhpOffice\PhpWord\IOFactory')) {
        throw new Exception('PHPWord not available');
    }

    $phpWord = \PhpOffice\PhpWord\IOFactory::load($input_path);
    
    $htmlWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'HTML');
    ob_start();
    $htmlWriter->save('php://output');
    $html = ob_get_clean();

    require_once 'vendor/dompdf/autoload.inc.php';
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $output_path = pathinfo($input_path, PATHINFO_DIRNAME) . '/' . 
                  pathinfo($input_path, PATHINFO_FILENAME) . '.pdf';
    
    file_put_contents($output_path, $dompdf->output());
    
    return $output_path;
}

// Clean temp files hourly
add_action('doc2pdf_cleanup', 'clean_temp_files');
if (!wp_next_scheduled('doc2pdf_cleanup')) {
    wp_schedule_event(time(), 'hourly', 'doc2pdf_cleanup');
}

function clean_temp_files() {
    $files = glob(DOC2PDF_TEMP_DIR . '*');
    $now = time();
    
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file) >= 3600)) {
            unlink($file);
        }
    }
}

// Handle plugin deactivation
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('doc2pdf_cleanup');
    clean_temp_files();
});
