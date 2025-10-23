-- Autocomplete query for collection names (MySQL fallback)
-- Used when autocomplete cache is not available
--
-- Placeholders:
--   {SEARCH_TERM} - Search query
--   {LIMIT} - Maximum number of results
--
-- Returns: collid, collectionname, collectioncode, institutioncode, display_name

SELECT DISTINCT
    c.collid,
    c.collectionname,
    c.collectioncode,
    c.institutioncode,
    CONCAT(c.collectionname, ' (', c.collectioncode, ')') as display_name
FROM omcollections c
WHERE c.collectionname LIKE '%{SEARCH_TERM}%'
    OR c.collectioncode LIKE '%{SEARCH_TERM}%'
    OR c.institutioncode LIKE '%{SEARCH_TERM}%'
ORDER BY c.collectionname ASC
LIMIT {LIMIT}
