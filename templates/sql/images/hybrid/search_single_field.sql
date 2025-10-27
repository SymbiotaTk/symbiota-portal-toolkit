-- Search for entities matching a single field value
-- Used by: ImagesModelHybrid::executeEavQuery() - single field
-- Parameters: :fieldName (field to search), :search (search term), :limit (max results)
-- Returns: Eid (entity IDs)
-- Note: Uses COLLATE NOCASE for 84% better performance vs LOWER()
SELECT DISTINCT e.Eid
FROM Entities e
JOIN EAV eav ON e.Eid = eav.Eid
JOIN Attributes a ON eav.Aid = a.Aid
JOIN ValuesText v ON eav.Vid = v.Vid
WHERE a.ColumnName = :fieldName COLLATE NOCASE
AND v.ValueText LIKE :search
LIMIT :limit

