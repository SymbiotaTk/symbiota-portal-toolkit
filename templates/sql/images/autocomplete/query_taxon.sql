-- Autocomplete query for taxa from hybrid autocomplete cache
-- Queries the autocomplete_taxon table built by ImagesModelAutocomplete
--
-- Database: SQLite (autocomplete_cache.db)
-- Method: PDO prepared statement with parameter binding
--
-- Parameters (bound in order):
--   1. query string with % suffix for prefix matching (e.g., "cron%")
--   2. limit integer (e.g., 30)
--
-- Returns:
--   - value: taxon name
--   - count: number of images with this taxon
--
-- Example usage:
--   $stmt = $db->prepare($sqlTemplate);
--   $stmt->execute([$query . '%', $limit]);
--   $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

SELECT taxon_name as value, image_count as count
FROM autocomplete_taxon
WHERE LOWER(taxon_name) LIKE LOWER(?)
ORDER BY image_count DESC, taxon_name ASC
LIMIT ?

