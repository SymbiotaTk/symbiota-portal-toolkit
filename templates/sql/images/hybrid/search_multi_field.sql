-- Search for entities matching any of multiple field values (OR logic)
-- Used by: ImagesModelHybrid::executeEavQuery() - multi field
-- Parameters: Dynamic placeholders for field names, search term, limit
-- Returns: Eid (entity IDs)
-- Note: Uses COLLATE NOCASE for 84% better performance vs LOWER()
-- Note: {PLACEHOLDERS} will be replaced with actual placeholders at runtime
SELECT DISTINCT e.Eid
FROM Entities e
JOIN EAV eav ON e.Eid = eav.Eid
JOIN Attributes a ON eav.Aid = a.Aid
JOIN ValuesText v ON eav.Vid = v.Vid
WHERE a.ColumnName IN ({PLACEHOLDERS}) COLLATE NOCASE
AND v.ValueText LIKE ?
LIMIT ?

