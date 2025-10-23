<?php

namespace Symbiota\Helpers\Interfaces;

/**
 * ImagesSearchInterface - Common interface for Images search implementations
 *
 * Defines the contract that both EAV and Hybrid search models must implement.
 * This ensures they are truly interchangeable from the perspective of ImagesModel.
 *
 * @version 1.0.0
 * @author Symbiota Portal Helpers
 */
interface ImagesSearchInterface
{
    /**
     * Search for images based on query parameters
     *
     * @param array $params Search parameters including:
     *   - 'query' or 'q': Search query string (may include field:value patterns)
     *   - 'limit': Maximum number of results to return
     *   - 'offset': Offset for pagination
     *   - 'sort': Sort order
     * @return array Search results in standard format:
     *   - 'type': 'success' or 'error'
     *   - 'results': Array of image records
     *   - 'total': Total number of matching records
     *   - 'message': Error message if type is 'error'
     */
    public function search(array $params): array;

    /**
     * Autocomplete field names based on partial input
     *
     * @param array $params Autocomplete parameters including:
     *   - 'q' or 'query': Partial field name to match
     *   - 'limit': Maximum number of suggestions
     * @return array Autocomplete results in HTMX format:
     *   - 'type': 'htmx'
     *   - 'content': HTML content with field suggestions
     */
    public function autocompleteFields(array $params): array;

    /**
     * Autocomplete field values based on partial input
     *
     * @param array $params Autocomplete parameters including:
     *   - 'field': Field name to autocomplete values for
     *   - 'q' or 'query': Partial value to match
     *   - 'limit': Maximum number of suggestions
     * @return array Autocomplete results in HTMX format:
     *   - 'type': 'htmx'
     *   - 'content': HTML content with value suggestions
     */
    public function autocompleteValues(array $params): array;

    /**
     * Get cache/index information and statistics
     *
     * @param array $params Optional parameters for info display
     * @return array Information array with:
     *   - 'type': 'success' or 'error'
     *   - 'stats': Array of statistics (entity count, index size, etc.)
     *   - 'message': Status message
     */
    public function getInfo(array $params = []): array;

    /**
     * Check if the cache/index is available and valid
     *
     * @return bool True if cache/index exists and is valid
     */
    public function isAvailable(): bool;

    /**
     * Get the search mode identifier
     *
     * @return string 'eav' or 'hybrid'
     */
    public function getSearchMode(): string;
}

