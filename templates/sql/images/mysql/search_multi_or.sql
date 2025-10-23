-- Multi-field OR search query
-- Used when user provides multiple search criteria where ANY can match
-- Example: --queryOr[]=family:Liceaceae --queryOr[]=family:Russulaceae
--
-- Placeholders:
--   {joins} - Dynamic JOIN clauses based on fields queried
--   {conditions} - WHERE conditions joined with OR
--   {limit} - Result limit
--   {offset} - Result offset
--
-- Example interpolation:
--   joins = "LEFT JOIN omoccurrences o ON m.occid = o.occid"
--   conditions = "LOWER(o.family) LIKE 'liceaceae%' OR LOWER(o.family) LIKE 'russulaceae%'"
--   limit = 100
--   offset = 0

SELECT DISTINCT
    m.mediaID,
    m.url,
    m.thumbnailUrl
FROM media m
{joins}
WHERE m.occid IS NOT NULL
    AND ({conditions})
ORDER BY m.mediaID
LIMIT {limit} OFFSET {offset}

