-- Query autocomplete cache tables for field suggestions
-- Used by: ImagesModelHybrid::queryAutocompleteCache()
-- Parameters: :query (search term), :field (optional, for source_field filtering)
-- Returns: value, count
-- Note: {VALUE_COLUMN}, {TABLE}, {SOURCE_FIELD_CONDITION} are interpolated at runtime
-- Note: Uses prefix match (query%) for index efficiency
SELECT
    {VALUE_COLUMN} as value,
    image_count as count
FROM {TABLE}
WHERE LOWER({VALUE_COLUMN}) LIKE LOWER(:query)
AND {SOURCE_FIELD_CONDITION}
ORDER BY image_count DESC, value ASC
LIMIT 10

