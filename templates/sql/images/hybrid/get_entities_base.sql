-- Get base entity data (mediaID, occid) for display
-- Used by: ImagesModelHybrid::getEavEntities() - display fields
-- Parameters: Dynamic placeholders for entity IDs
-- Returns: Eid, mediaID, occid
-- Note: {PLACEHOLDERS} will be replaced with actual placeholders at runtime
SELECT Eid, mediaID, occid
FROM Entities
WHERE Eid IN ({PLACEHOLDERS})

