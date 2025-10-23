-- Autocomplete query for collection codes (MySQL fallback)
-- Used when autocomplete cache is not available
--
-- Placeholders:
--   {SEARCH_TERM} - Search query
--   {LIMIT} - Maximum number of results
--
-- Returns: collid, collectioncode, collectionname, institutioncode, display_name

SELECT DISTINCT
    c.collid,
    c.collectioncode,
    c.collectionname,
    c.institutioncode,
    CONCAT(c.collectioncode, ' - ', c.collectionname) as display_name
FROM omcollections c
WHERE c.collectioncode LIKE '%{SEARCH_TERM}%'
    AND c.collectioncode IS NOT NULL
    AND c.collectioncode != ''
ORDER BY c.collectioncode ASC
LIMIT {LIMIT}
