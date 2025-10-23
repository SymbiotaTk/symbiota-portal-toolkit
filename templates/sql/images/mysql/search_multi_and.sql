-- Multi-field AND search query
-- Used when user provides multiple search criteria that must ALL match
-- Example: --queryAnd[]=family:Liceaceae --queryAnd[]=country:USA
--
-- Placeholders:
--   {joins} - Dynamic JOIN clauses based on fields queried
--   {conditions} - WHERE conditions joined with AND
--   {limit} - Result limit
--   {offset} - Result offset
--
-- Example interpolation:
--   joins = "LEFT JOIN omoccurrences o ON m.occid = o.occid"
--   conditions = "LOWER(o.family) LIKE 'liceaceae%' AND LOWER(o.country) LIKE 'usa%'"
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

