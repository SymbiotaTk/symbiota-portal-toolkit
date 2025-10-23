-- Numeric field search query
-- Used for numeric fields with exact match or range queries
-- Example: occid:12345, decimalLatitude:>40.5
--
-- Placeholders:
--   {joins} - Dynamic JOIN clauses based on field location
--   {field_condition} - WHERE condition for the numeric field (with operator)
--   {limit} - Result limit
--   {offset} - Result offset
--
-- Example interpolation for exact match:
--   joins = "LEFT JOIN omoccurrences o ON m.occid = o.occid"
--   field_condition = "o.occid = 12345"
--   limit = 100
--   offset = 0
--
-- Example interpolation for range:
--   joins = "LEFT JOIN omoccurrences o ON m.occid = o.occid"
--   field_condition = "o.decimalLatitude > 40.5"
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

