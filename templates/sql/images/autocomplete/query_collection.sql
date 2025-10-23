-- Autocomplete query for collections from hybrid autocomplete cache
-- Queries the autocomplete_collection table built by ImagesModelAutocomplete
--
-- Database: SQLite (autocomplete_cache.db)
-- Method: PDO prepared statement with parameter binding
--
-- Parameters (bound in order):
--   1. query string with % suffix for prefix matching (e.g., "cup%")
--   2. limit integer (e.g., 30)
--
-- Returns:
--   - value: collection name/code/institution
--   - count: number of images in this collection
--
-- Example usage:
--   $stmt = $db->prepare($sqlTemplate);
--   $stmt->execute([$query . '%', $limit]);
--   $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

SELECT collection_value as value, image_count as count
FROM autocomplete_collection
WHERE LOWER(collection_value) LIKE LOWER(?)
ORDER BY image_count DESC, collection_value ASC
LIMIT ?

