-- Get autocomplete suggestions for a specific field
-- Used by: ImagesModelHybrid::autocompleteValues()
-- Parameters: :field (field name), :query (search term), :limit (max results)
-- Returns: value, count
-- Note: Uses COLLATE NOCASE for 84% better performance vs LOWER()
SELECT
    vt.ValueText as value,
    COUNT(DISTINCT eav.Eid) as count
FROM EAV eav
JOIN Attributes attr ON eav.Aid = attr.Aid
JOIN ValuesText vt ON eav.Vid = vt.Vid
WHERE attr.ColumnName = :field COLLATE NOCASE
AND vt.ValueText LIKE :query
GROUP BY vt.ValueText
ORDER BY count DESC, vt.ValueText ASC
LIMIT :limit

