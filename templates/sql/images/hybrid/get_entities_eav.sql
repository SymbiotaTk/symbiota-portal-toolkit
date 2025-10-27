-- Get EAV attributes for entities (indexed fields from cache)
-- Used by: ImagesModelHybrid::getEavEntities() - EAV data
-- Parameters: Dynamic placeholders for entity IDs
-- Returns: Eid, ColumnName, TokenStrategy, Value
-- Note: {PLACEHOLDERS} will be replaced with actual placeholders at runtime
-- Note: Hybrid schema only supports :whole tokenization (no VidOrder)
SELECT
    e.Eid,
    a.ColumnName,
    a.TokenStrategy,
    COALESCE(v.ValueText, CAST(eav.ValueNumber AS TEXT)) as Value
FROM Entities e
JOIN EAV eav ON e.Eid = eav.Eid
JOIN Attributes a ON eav.Aid = a.Aid
LEFT JOIN ValuesText v ON eav.Vid = v.Vid
WHERE e.Eid IN ({PLACEHOLDERS})
ORDER BY e.Eid, a.ColumnName

