-- Date field search query
-- Used for date fields with exact match, operators, or ranges
-- Example: eventDate:1974, eventDate:>2000, eventDate:1970-1980
--
-- Placeholders:
--   {joins} - Dynamic JOIN clauses based on field location
--   {field_condition} - WHERE condition for the date field (with operator)
--   {limit} - Result limit
--   {offset} - Result offset
--
-- Example interpolation for exact year:
--   joins = "LEFT JOIN omoccurrences o ON m.occid = o.occid"
--   field_condition = "o.eventDate LIKE '1974%'"
--   limit = 100
--   offset = 0
--
-- Example interpolation for operator:
--   joins = "LEFT JOIN omoccurrences o ON m.occid = o.occid"
--   field_condition = "o.eventDate > '2000'"
--   limit = 100
--   offset = 0
--
-- Example interpolation for range:
--   joins = "LEFT JOIN omoccurrences o ON m.occid = o.occid"
--   field_condition = "o.eventDate BETWEEN '1970' AND '1980'"
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

