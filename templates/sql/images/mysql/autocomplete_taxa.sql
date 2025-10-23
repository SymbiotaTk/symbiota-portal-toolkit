-- Autocomplete query for taxa/scientific names (MySQL fallback)
-- Used when autocomplete cache is not available
--
-- Placeholders:
--   {SEARCH_TERM} - Search query
--   {LIMIT} - Maximum number of results
--
-- Returns: tid, sciname, author, display_name

SELECT DISTINCT
    t.tid,
    t.sciname,
    t.author,
    CASE
        WHEN t.author IS NOT NULL AND t.author != ''
        THEN CONCAT(t.sciname, ' ', t.author)
        ELSE t.sciname
    END as display_name
FROM taxa t
WHERE t.sciname LIKE '%{SEARCH_TERM}%'
    AND t.sciname IS NOT NULL
    AND t.sciname != ''
ORDER BY t.sciname ASC
LIMIT {LIMIT}
