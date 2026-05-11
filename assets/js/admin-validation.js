jQuery(document).ready(function($){

    // Title Validation on Publish
    $('#publish, #save-post').on('click', function(e){
        var title = $('#title').val();
        if ( typeof title !== 'undefined' && title.trim() === '' ) {
            if ( $('#title').is(':visible') ) {
                alert( 'Error: The Title field is required.' );
                $('#title').focus();
                e.preventDefault();
                $(this).removeClass('disabled');
                $('.spinner').removeClass('is-active');
                return false;
            }
        }
    });

});
