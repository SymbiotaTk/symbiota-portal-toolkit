-- Field-specific search query
-- Used when user searches with field prefix (e.g., family:Liceaceae)
--
-- Placeholders:
--   {joins} - Dynamic JOIN clauses based on field location
--   {field_condition} - WHERE condition for the specific field
--   {limit} - Result limit
--   {offset} - Result offset
--
-- Example interpolation:
--   joins = "LEFT JOIN omoccurrences o ON m.occid = o.occid"
--   field_condition = "LOWER(o.family) LIKE 'liceaceae%'"
--   limit = 100
--   offset = 0

SELECT DISTINCT
    m.mediaID,
    m.url,
    m.thumbnailUrl
FROM media m
{joins}
WHERE m.occid IS NOT NULL
    AND {field_condition}
ORDER BY m.mediaID
LIMIT {limit} OFFSET {offset}

