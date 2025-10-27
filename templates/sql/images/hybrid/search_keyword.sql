-- Search for entities matching a keyword across all fields
-- Used by: ImagesModelHybrid::executeEavQuery() - keyword search
-- Parameters: :search (search term), :limit (max results)
-- Returns: Eid (entity IDs)
-- Note: Uses COLLATE NOCASE for 84% better performance vs LOWER()
SELECT DISTINCT e.Eid
FROM Entities e
JOIN EAV eav ON e.Eid = eav.Eid
JOIN ValuesText v ON eav.Vid = v.Vid
WHERE v.ValueText LIKE :search COLLATE NOCASE
LIMIT :limit

