// GenBank Tool Theme JavaScript
(function() {
    'use strict';
    
    // Theme management functions
    function initTheme() {
        var currentTheme = localStorage.getItem('genbank-theme');
        if (!currentTheme) currentTheme = 'light';
        var themeToggle = document.querySelector('.theme-toggle');

        // Always set the data-theme attribute explicitly
        document.documentElement.setAttribute('data-theme', currentTheme);

        if (currentTheme === 'dark') {
            if (themeToggle) themeToggle.textContent = '☀️';
        } else {
            if (themeToggle) themeToggle.textContent = '🌙';
        }
    }

    function toggleTheme() {
        var currentTheme = document.documentElement.getAttribute('data-theme');
        var themeToggle = document.querySelector('.theme-toggle');

        if (currentTheme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'light');
            localStorage.setItem('genbank-theme', 'light');
            if (themeToggle) themeToggle.textContent = '🌙';
        } else {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('genbank-theme', 'dark');
            if (themeToggle) themeToggle.textContent = '☀️';
        }
    }

    // TSV download functionality
    function downloadTSV(tableId, filename) {
        var table = document.getElementById(tableId);
        if (!table) return;

        var tsv = '';
        var rows = table.querySelectorAll('tr');
        
        rows.forEach(function(row) {
            var cells = row.querySelectorAll('th, td');
            var rowData = Array.from(cells).map(function(cell) {
                return cell.textContent.trim();
            });
            tsv += rowData.join('\t') + '\n';
        });

        var blob = new Blob([tsv], { type: 'text/tab-separated-values' });
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }



    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        initTheme();

        // Add event listener for theme toggle
        var themeToggle = document.querySelector('.theme-toggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', toggleTheme);
        }

        // Initialize backup module handlers if on backup page
        if (document.querySelector('.collection-status-details') || document.querySelector('#snackbar')) {
            initAccordionHandlers();
            initBackupHTMXHandlers();
        }
    });

    // Make functions available globally for HTMX and inline usage
    window.initTheme = initTheme;
    window.toggleTheme = toggleTheme;
    window.downloadTSV = downloadTSV;

    // Snackbar notification function
    function showSnackbar(message, type) {
        type = type || 'warning';

        // Remove any existing snackbar
        var existingSnackbar = document.getElementById('snackbar');
        if (existingSnackbar) {
            existingSnackbar.remove();
        }

        // Create snackbar element
        var snackbar = document.createElement('div');
        snackbar.id = 'snackbar';
        snackbar.className = 'snackbar ' + type;
        snackbar.textContent = message;

        // Add to page
        document.body.appendChild(snackbar);

        // Show snackbar
        setTimeout(function() {
            snackbar.classList.add('show');
        }, 100);

        // Hide snackbar after 3 seconds
        setTimeout(function() {
            snackbar.classList.remove('show');
            setTimeout(function() {
                if (snackbar.parentNode) {
                    snackbar.parentNode.removeChild(snackbar);
                }
            }, 300);
        }, 3000);
    }

    // GenBank-specific download function with validation
    function action_download_file(elementId) {
        var element = document.getElementById(elementId);
        if (!element) {
            showSnackbar('Download button not found', 'error');
            return;
        }

        var targetId = element.getAttribute('data-value');
        var target = document.getElementById(targetId);

        // Check if search results exist
        if (!target) {
            showSnackbar('No search results available. Please search first.', 'warning');
            return;
        }

        // Find the table within the target element
        var table = target.querySelector('table');
        if (!table) {
            showSnackbar('No data table found. Please perform a search first.', 'warning');
            return;
        }

        // Check if table has data rows
        var dataRows = table.querySelectorAll('tbody tr');
        if (dataRows.length === 0) {
            showSnackbar('No data available to download. Please search for records first.', 'warning');
            return;
        }

        // Proceed with download
        var filename = element.getAttribute('data-filename') || 'genbank_results.tsv';
        var tsv = '';

        // Get headers
        var headers = table.querySelectorAll('thead th');
        if (headers.length > 0) {
            var headerRow = Array.from(headers).map(function(th) {
                return th.textContent.trim();
            }).join('\t');
            tsv += headerRow + '\n';
        }

        // Get data rows
        dataRows.forEach(function(row) {
            var cells = row.querySelectorAll('td');
            var rowData = Array.from(cells).map(function(td) {
                return td.textContent.trim();
            }).join('\t');
            tsv += rowData + '\n';
        });

        // Create and trigger download
        var blob = new Blob([tsv], { type: 'text/tab-separated-values' });
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);

        // Show success message
        showSnackbar('Download started successfully!', 'success');
    }

    // Export functions to global scope
    window.GenBankTheme = {
        init: initTheme,
        toggle: toggleTheme,
        downloadTSV: downloadTSV
    };

    // Make GenBank functions globally accessible
    window.action_download_file = action_download_file;
    window.showSnackbar = showSnackbar;

    // Backup module specific functions
    function showBackupSnackbar(message, type) {
        type = type || 'success';
        var snackbar = document.getElementById('backup-snackbar');
        var messageEl = document.getElementById('snackbar-message');
        var iconEl = document.getElementById('snackbar-icon');

        if (snackbar && messageEl && iconEl) {
            messageEl.innerHTML = message;

            // Reset classes
            snackbar.className = 'snackbar';

            // Set type and icon
            switch(type) {
                case 'error':
                    snackbar.classList.add('error');
                    iconEl.className = 'fas fa-exclamation-circle me-2';
                    break;
                case 'warning':
                    snackbar.classList.add('warning');
                    iconEl.className = 'fas fa-exclamation-triangle me-2';
                    break;
                case 'info':
                    snackbar.classList.add('info');
                    iconEl.className = 'fas fa-info-circle me-2';
                    break;
                default: // success
                    iconEl.className = 'fas fa-check-circle me-2';
                    break;
            }

            // Show snackbar
            snackbar.classList.add('show');

            // Auto-hide after 5 seconds (except for errors)
            if (type !== 'error') {
                setTimeout(hideSnackbar, 5000);
            }
        }
    }

    function hideSnackbar() {
        var snackbar = document.getElementById('backup-snackbar');
        if (snackbar) {
            snackbar.classList.remove('show');
        }
    }

    // Bootstrap collapse event handlers for accordion icons
    function initAccordionHandlers() {
        document.addEventListener('shown.bs.collapse', function(event) {
            var button = document.querySelector('[data-bs-target="#' + event.target.id + '"]');
            if (button) {
                var icon = button.querySelector('.fas');
                if (icon) {
                    icon.classList.remove('fa-chevron-down');
                    icon.classList.add('fa-chevron-up');
                }
            }
        });

        document.addEventListener('hidden.bs.collapse', function(event) {
            var button = document.querySelector('[data-bs-target="#' + event.target.id + '"]');
            if (button) {
                var icon = button.querySelector('.fas');
                if (icon) {
                    icon.classList.remove('fa-chevron-up');
                    icon.classList.add('fa-chevron-down');
                }
            }
        });
    }

    // HTMX event handlers for backup module
    function initBackupHTMXHandlers() {
        document.addEventListener('htmx:afterRequest', function(event) {
            // Only handle backup-related requests
            var target = event.detail.target;
            var triggeringElement = event.detail.elt;

            // Check if this is a backup-related request
            var isBackupRequest = false;

            // Check if the triggering element or target is backup-related
            if (triggeringElement) {
                isBackupRequest = triggeringElement.closest('.backup-controls') !== null ||
                                triggeringElement.closest('[id*="backup"]') !== null ||
                                triggeringElement.hasAttribute('hx-post') &&
                                triggeringElement.getAttribute('hx-post').includes('backup');
            }

            // Check if target is backup-related
            if (target) {
                isBackupRequest = isBackupRequest ||
                                target.id.includes('backup') ||
                                target.closest('.backup-controls') !== null;
            }

            // Only process if this is a backup request
            if (!isBackupRequest) {
                return;
            }

            var response = event.detail.xhr.response;

            // Check if response contains success/error alerts and show snackbar
            if (response.includes('alert-success')) {
                var match = response.match(/<div class="alert alert-success"[^>]*>(.*?)<\/div>/);
                if (match) {
                    showBackupSnackbar(match[1].trim(), 'success');
                }
            } else if (response.includes('alert-danger')) {
                var match = response.match(/<div class="alert alert-danger"[^>]*>(.*?)<\/div>/);
                if (match) {
                    showBackupSnackbar(match[1].trim(), 'error');
                }
            } else if (response.includes('alert-warning')) {
                var match = response.match(/<div class="alert alert-warning"[^>]*>(.*?)<\/div>/);
                if (match) {
                    showBackupSnackbar(match[1].trim(), 'warning');
                }
            }
        });
    }

    // Export backup functions
    window.showBackupSnackbar = showBackupSnackbar;
    window.hideSnackbar = hideSnackbar;
    window.initAccordionHandlers = initAccordionHandlers;
    window.initBackupHTMXHandlers = initBackupHTMXHandlers;
})();

// GenBank HTMX event handlers (simplified - no button state management needed)
document.addEventListener('DOMContentLoaded', function() {
    // Listen for HTMX events to handle form submissions
    document.body.addEventListener('htmx:beforeRequest', function(evt) {
        if (evt.detail.elt.closest('form')) {
            // This is a form submission - show results container
            var resultsContainer = document.getElementById('search-results');
            if (resultsContainer) {
                var card = resultsContainer.closest('.card');
                if (card) {
                    card.style.display = 'block';
                }
            }
        }
    });

    document.body.addEventListener('htmx:afterRequest', function(evt) {
        if (evt.detail.target.id === 'search-results') {
            // Results have been loaded, update download button filename if possible
            var resultsContainer = evt.detail.target;
            var downloadButton = document.getElementById('download_tsv');

            if (downloadButton) {
                // Extract filename from the result if available
                var resultDiv = resultsContainer.querySelector('#genbank-result');
                var filename = 'genbank-results.tsv';

                if (resultDiv) {
                    // Try to extract occid or catalog number for filename
                    var occidLink = resultDiv.querySelector('a[href*="occid="]');
                    if (occidLink) {
                        var occidMatch = occidLink.href.match(/occid=(\d+)/);
                        if (occidMatch) {
                            filename = 'symb-genbank-' + occidMatch[1] + '.tsv';
                        }
                    }
                }

                // Update download button filename
                downloadButton.setAttribute('data-filename', filename);
            }

            // Scroll to results
            setTimeout(function() {
                var resultsContainer = document.getElementById('search-results');
                if (resultsContainer) {
                    var card = resultsContainer.closest('.card');
                    if (card) {
                        card.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                }
            }, 100);
        }

        // Form toggle state is now handled by pure CSS radio buttons - no JavaScript needed
    });

    // Image error handling functions - prevents OpaqueResponseBlocking errors
    window.handleImageError = function(img) {
        // Check if this image has already failed
        if (img.dataset.failed === 'true') {
            // Already tried fallback, show placeholder instead
            showImagePlaceholder(img);
            reportFailedImage(img.src);
            return;
        }

        // Mark as failed to prevent future attempts
        img.dataset.failed = 'true';

        // Try fallback URL if available
        const fallbackUrl = img.dataset.fallbackUrl;
        if (fallbackUrl && fallbackUrl !== img.src) {
            img.src = fallbackUrl;
            return;
        }

        // No fallback available or fallback also failed, show placeholder
        showImagePlaceholder(img);
        reportFailedImage(img.src);
    };

    // Report failed image to server for caching
    function reportFailedImage(url) {
        // Debounce multiple reports - collect URLs and send in batches
        if (!window.failedImageUrls) {
            window.failedImageUrls = new Set();
        }

        window.failedImageUrls.add(url);

        // Clear any existing timeout
        if (window.reportFailedImagesTimeout) {
            clearTimeout(window.reportFailedImagesTimeout);
        }

        // Send batch report after 2 seconds of no new failures
        window.reportFailedImagesTimeout = setTimeout(() => {
            const urlsToReport = Array.from(window.failedImageUrls);
            window.failedImageUrls.clear();

            if (urlsToReport.length > 0) {
                // Get CSRF token from page
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                                 window.imagesCsrfToken || '';

                fetch('/?/images/invalid', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        urls: urlsToReport,
                        csrf_token: csrfToken
                    })
                }).catch(error => {
                    // Silently fail - this is just optimization, not critical
                    console.debug('Failed to report invalid images:', error);
                });
            }
        }, 2000);
    }

    function showImagePlaceholder(img) {
        // Create a simple placeholder
        const placeholder = document.createElement('div');
        placeholder.className = 'image-placeholder d-flex align-items-center justify-content-center';
        placeholder.style.cssText = `
            min-height: 150px;
            background-color: #f8f9fa;
            border: 1px dashed #dee2e6;
            border-radius: 0.375rem;
            color: #6c757d;
        `;
        placeholder.innerHTML = `
            <div class="text-center">
                <i class="fas fa-image fa-2x mb-2"></i>
                <div class="small">Image unavailable</div>
            </div>
        `;

        // Replace the img with the placeholder
        img.parentNode.replaceChild(placeholder, img);
    }
});
