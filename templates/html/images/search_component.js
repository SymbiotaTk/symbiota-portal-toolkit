/**
 * EAV Search Component with localStorage
 * GitHub-style selector pills interface
 */

// localStorage key
const STORAGE_KEY = 'symbiota_images_search';

// Search state
let searchFilters = [];
let recentSearches = [];
let preferences = {
    results_per_page: 20,
    sort_by: 'eventDate,catalogNumber',
    search_logic: 'and'
};

// Autocomplete state (global so we can cancel from anywhere)
let autocompleteTimeout = null;

/**
 * Initialize search component
 */
function initSearchComponent() {
    loadFromLocalStorage();
    renderPills();
    renderRecentSearches();
    applyPreferences();

    // Show recent searches if available
    if (recentSearches.length > 0) {
        document.getElementById('recent-searches').style.display = 'block';
    }

    // Setup keyboard navigation
    setupKeyboardNavigation();

    // Setup autocomplete
    setupAutocomplete();
}

/**
 * Load data from localStorage
 */
function loadFromLocalStorage() {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
            const data = JSON.parse(stored);
            searchFilters = data.filters || [];
            recentSearches = data.recent_searches || [];
            preferences = { ...preferences, ...(data.preferences || {}) };
        }
    } catch (e) {
        console.error('Error loading from localStorage:', e);
    }
}

/**
 * Save data to localStorage
 */
function saveToLocalStorage() {
    try {
        const data = {
            filters: searchFilters,
            recent_searches: recentSearches.slice(0, 10), // Keep last 10
            preferences: preferences,
            last_updated: new Date().toISOString()
        };
        localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
    } catch (e) {
        console.error('Error saving to localStorage:', e);
    }
}

/**
 * Add a search filter
 */
function addSearchFilter() {
    const input = document.getElementById('eav-search-input');
    const value = input.value.trim();

    if (!value) return;

    // Check if value contains multiple field:value pairs (space-separated)
    // Match pattern: field1:value1 field2:value2
    const multiPattern = /([a-zA-Z_]+:[^\s]+)/g;
    const matches = value.match(multiPattern);

    if (matches && matches.length > 1) {
        // Multiple field:value pairs found - add each as a separate filter
        matches.forEach(pair => {
            const pairMatch = pair.match(/^([a-zA-Z_]+):(.+)$/);
            if (pairMatch) {
                const [, field, filterValue] = pairMatch;
                addFilter(field, filterValue);
            }
        });
        input.value = '';
        hideAutocomplete();
        return;
    }

    // Single field:value format
    const match = value.match(/^([a-zA-Z_]+):(.+)$/);
    if (match) {
        const [, field, filterValue] = match;
        addFilter(field, filterValue);
        input.value = '';
        hideAutocomplete();
    } else {
        // If no field specified, treat as general search
        addFilter('search', value);
        input.value = '';
    }
}

/**
 * Add a filter to the list
 */
function addFilter(field, value) {
    // Check if filter already exists
    const exists = searchFilters.some(f => f.field === field && f.value === value);
    if (exists) return;
    
    searchFilters.push({ field, value });
    saveToLocalStorage();
    renderPills();
}

/**
 * Remove a filter
 */
function removeFilter(index) {
    searchFilters.splice(index, 1);
    saveToLocalStorage();
    renderPills();
}

/**
 * Clear all filters
 */
function clearAllFilters() {
    searchFilters = [];
    saveToLocalStorage();
    renderPills();
    document.getElementById('eav-search-input').value = '';
}

/**
 * Render search pills
 */
function renderPills() {
    const container = document.getElementById('search-pills');
    const placeholder = document.getElementById('pill-placeholder');
    
    // Remove existing pills (keep placeholder)
    const existingPills = container.querySelectorAll('.search-pill');
    existingPills.forEach(pill => pill.remove());
    
    if (searchFilters.length === 0) {
        placeholder.style.display = 'flex';
    } else {
        placeholder.style.display = 'none';
        
        searchFilters.forEach((filter, index) => {
            const pill = document.createElement('div');
            pill.className = 'search-pill';
            pill.innerHTML = `
                <span class="pill-field">${escapeHtml(filter.field)}:</span>
                <span class="pill-value">${escapeHtml(filter.value)}</span>
                <button class="pill-remove" onclick="removeFilter(${index})" title="Remove filter">
                    <i class="fas fa-times"></i>
                </button>
            `;
            container.appendChild(pill);
        });
    }
}

/**
 * Execute search
 */
function executeSearch() {
    if (searchFilters.length === 0) {
        alert('Please add at least one search filter');
        return;
    }

    // Hide autocomplete when executing search
    hideAutocomplete();

    // Get search logic preference (AND or OR)
    const logic = document.querySelector('input[name="search-logic"]:checked')?.value || 'and';

    // Get preferences
    const limit = preferences.results_per_page;
    const sort = preferences.sort_by;

    // Build URL based on logic
    const searchBtn = document.querySelector('.search-execute-btn');
    const baseUrl = searchBtn.getAttribute('data-search-url');

    let url = `${baseUrl}?limit=${limit}&sort=${sort}`;

    if (searchFilters.length === 1) {
        // Single filter: use simple query parameter
        const filter = searchFilters[0];
        url += `&query=${encodeURIComponent(filter.field + ':' + filter.value)}`;
    } else if (logic === 'and') {
        // Multiple filters with AND logic: use queryAnd[]
        searchFilters.forEach(f => {
            url += `&queryAnd[]=${encodeURIComponent(f.field + ':' + f.value)}`;
        });
    } else {
        // Multiple filters with OR logic: use queryOr[]
        searchFilters.forEach(f => {
            url += `&queryOr[]=${encodeURIComponent(f.field + ':' + f.value)}`;
        });
    }

    // Build query string for recent searches display
    const queryParts = searchFilters.map(f => `${f.field}:${f.value}`);
    const queryDisplay = searchFilters.length > 1
        ? `(${logic.toUpperCase()}) ${queryParts.join(' ')}`
        : queryParts[0];

    // Save to recent searches
    addToRecentSearches(queryDisplay);

    // Trigger HTMX request
    htmx.ajax('GET', url, {
        target: '#image-gallery',
        swap: 'innerHTML',
        indicator: '#loading-indicator'
    });
}

/**
 * Add to recent searches
 */
function addToRecentSearches(query) {
    // Remove if already exists
    recentSearches = recentSearches.filter(s => s !== query);
    
    // Add to beginning
    recentSearches.unshift(query);
    
    // Keep only last 10
    recentSearches = recentSearches.slice(0, 10);
    
    saveToLocalStorage();
    renderRecentSearches();
    
    // Show recent searches panel
    document.getElementById('recent-searches').style.display = 'block';
}

/**
 * Render recent searches
 */
function renderRecentSearches() {
    const list = document.getElementById('recent-searches-list');
    list.innerHTML = '';
    
    if (recentSearches.length === 0) {
        document.getElementById('recent-searches').style.display = 'none';
        return;
    }
    
    recentSearches.forEach(query => {
        const item = document.createElement('div');
        item.className = 'recent-search-item';
        item.textContent = query;
        item.onclick = () => loadRecentSearch(query);
        list.appendChild(item);
    });
}

/**
 * Load a recent search
 */
function loadRecentSearch(query) {
    // Clear current filters
    searchFilters = [];
    
    // Parse query and add filters
    const parts = query.split(/\s+/);
    parts.forEach(part => {
        const match = part.match(/^([a-zA-Z_]+):(.+)$/);
        if (match) {
            const [, field, value] = match;
            searchFilters.push({ field, value });
        }
    });
    
    saveToLocalStorage();
    renderPills();
    executeSearch();
}

/**
 * Clear recent searches
 */
function clearRecentSearches() {
    if (confirm('Clear all recent searches?')) {
        recentSearches = [];
        saveToLocalStorage();
        renderRecentSearches();
    }
}

/**
 * Save preference
 */
function savePreference(key, value) {
    preferences[key] = value;
    saveToLocalStorage();
}

/**
 * Apply preferences to UI
 */
function applyPreferences() {
    document.getElementById('results-per-page').value = preferences.results_per_page;
    document.getElementById('sort-by').value = preferences.sort_by;

    // Apply search logic preference
    const logicRadio = document.querySelector(`input[name="search-logic"][value="${preferences.search_logic}"]`);
    if (logicRadio) {
        logicRadio.checked = true;
    }
}

/**
 * Select a field from autocomplete
 */
function selectField(field) {
    const input = document.getElementById('eav-search-input');
    input.value = field + ':';
    input.focus();
    hideAutocomplete();

    // Trigger value autocomplete for this field
    triggerValueAutocomplete(field);
}

/**
 * Select a value from autocomplete
 */
function selectValue(field, value) {
    // Add the filter
    addFilter(field, value);

    // Clear input
    const input = document.getElementById('eav-search-input');
    input.value = '';

    hideAutocomplete();
}

/**
 * Hide autocomplete dropdown
 */
function hideAutocomplete() {
    const dropdown = document.getElementById('autocomplete-dropdown');
    const spinner = document.getElementById('autocomplete-spinner');

    if (dropdown) {
        dropdown.style.display = 'none';
    }

    // Hide the spinner
    if (spinner) {
        spinner.style.display = 'none';
    }

    // Cancel any pending autocomplete requests
    if (autocompleteTimeout) {
        clearTimeout(autocompleteTimeout);
        autocompleteTimeout = null;
    }
}

/**
 * Show autocomplete dropdown
 */
function showAutocomplete() {
    const dropdown = document.getElementById('autocomplete-dropdown');
    if (dropdown && dropdown.innerHTML.trim()) {
        dropdown.style.display = 'block';
    }
}

/**
 * Setup keyboard navigation
 */
function setupKeyboardNavigation() {
    const input = document.getElementById('eav-search-input');

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            addSearchFilter();
        } else if (e.key === 'Escape') {
            hideAutocomplete();
        }
    });
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', initSearchComponent);

// Also initialize after HTMX swaps
document.body.addEventListener('htmx:afterSwap', (event) => {
    if (event.detail.target.id === 'image-gallery') {
        // Re-initialize if needed
    }

    // Show autocomplete dropdown after field autocomplete loads
    if (event.detail.target.id === 'autocomplete-dropdown') {
        const dropdown = event.detail.target;
        if (dropdown.innerHTML.trim()) {
            showAutocomplete();
        } else {
            hideAutocomplete();
        }
    }
});

// Hide autocomplete when clicking outside
document.addEventListener('click', (event) => {
    const dropdown = document.getElementById('autocomplete-dropdown');
    const input = document.getElementById('eav-search-input');

    if (dropdown && input &&
        !dropdown.contains(event.target) &&
        !input.contains(event.target)) {
        hideAutocomplete();
    }
});

/**
 * Setup autocomplete - HTMX handles the requests
 * This just manages show/hide behavior
 */
function setupAutocomplete() {
    const input = document.getElementById('eav-search-input');
    const dropdown = document.getElementById('autocomplete-dropdown');

    if (!input || !dropdown) return;

    // Hide autocomplete when input loses focus
    input.addEventListener('blur', (e) => {
        // Delay hiding to allow clicking on autocomplete items
        setTimeout(() => {
            hideAutocomplete();
        }, 200);
    });

    // Show autocomplete when input gains focus (if there's content)
    input.addEventListener('focus', (e) => {
        if (dropdown.innerHTML.trim()) {
            showAutocomplete();
        }
    });

    // Hide on empty input
    input.addEventListener('keyup', (e) => {
        if (input.value.trim().length === 0) {
            hideAutocomplete();
        }
    });
}
