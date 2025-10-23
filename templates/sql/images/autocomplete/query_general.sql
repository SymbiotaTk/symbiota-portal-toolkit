-- Autocomplete query for general fields from hybrid autocomplete cache
-- Queries the AutocompleteIndex table for fields not in specialized tables
--
-- Database: SQLite (autocomplete_cache.db)
-- Method: PDO prepared statement with parameter binding
--
-- Parameters (bound in order):
--   1. field name (e.g., "country", "stateProvince")
--   2. query string with % suffix for prefix matching (e.g., "united%")
--   3. limit integer (e.g., 30)
--
-- Returns:
--   - value: field value token
--   - count: number of media records with this value
--
-- Example usage:
--   $stmt = $db->prepare($sqlTemplate);
--   $stmt->execute([$field, $query . '%', $limit]);
--   $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

SELECT ac.Token as value, ac.MediaCount as count
FROM AutocompleteIndex ac
JOIN Attributes a ON a.Aid = ac.Aid
WHERE a.ColumnName = ? AND LOWER(ac.Token) LIKE LOWER(?)
ORDER BY ac.MediaCount DESC, ac.Token ASC
LIMIT ?

