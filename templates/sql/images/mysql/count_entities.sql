-- Count total entities (media records with occid)
-- Used for auto-detection of dataset size to determine search mode
--
-- Placeholders: None
--
-- Returns: Single integer value (entity count)

SELECT COUNT(DISTINCT m.mediaID) as entity_count
FROM media m
WHERE m.occid IS NOT NULL

