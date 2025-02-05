jQuery(document).ready(function($) {
    // Handle file upload result display (success/error)
    $("form").on('submit', function(e) {
        var fileInput = $("input[name='service_account_key']");

        // Check if a file is selected and validate before submitting
        if (fileInput.val() && !fileInput.val().endsWith('.json')) {
            e.preventDefault();  // Prevent form submission
            $("#upload-result").html('<div class="error-message">Please upload a valid JSON file.</div>');
        }
    });

    // If error parameter exists in the URL, show error message
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('error') && urlParams.get('error') === 'invalid_file') {
        $(".error-message").show().html('Invalid file type. Please upload a valid .json file.');
    }

    // Select2 for metadata field mapping (optional)
    if (typeof $.fn.select2 !== "undefined") {
        $("select").select2({
            placeholder: "Select Metadata Field",
            width: "100%"
        });
    }

    // More JS for better form UX can go here (e.g., file upload validation on the fly)
});
