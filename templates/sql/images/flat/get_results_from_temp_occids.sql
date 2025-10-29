-- ============================================================================
-- Get Results from Temporary Occid Table
-- ============================================================================
-- This template retrieves full media records for occids in a temporary table.
--
-- Parameters:
-- - {TEMP_TABLE}: Name of the temporary table containing occids
-- - {LIMIT}: Maximum number of results to return
-- - {OFFSET}: Number of results to skip
--
-- Returns:
-- All media fields plus related occurrence, collection, and taxa data
-- ============================================================================

SELECT DISTINCT
    m.mediaID,
    m.url,
    m.originalUrl,
    m.thumbnailUrl,
    o.occid,
    o.family,
    o.genus,
    o.sciname,
    o.scientificname,
    o.catalogNumber,
    o.country,
    o.stateProvince,
    o.county,
    o.locality,
    c.collid,
    c.collectionCode,
    c.institutionCode,
    c.collectionName,
    t.sciname AS taxa_sciname
FROM media m
INNER JOIN {TEMP_TABLE} tmp ON m.occid = tmp.occid
INNER JOIN omoccurrences o ON m.occid = o.occid
LEFT JOIN omcollections c ON o.collid = c.collid
LEFT JOIN taxa t ON o.tidinterpreted = t.tid
LIMIT {LIMIT} OFFSET {OFFSET};

