// Minimal JavaScript for Images Gallery - HTMX handles most functionality
document.addEventListener('DOMContentLoaded', function() {

    // Only essential JavaScript that can't be done with HTMX/CSS

    // Keyboard handler for modal close (ESC key)
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.querySelector('.image-modal.active');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
    });

});


