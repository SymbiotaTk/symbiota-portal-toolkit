-- ============================================================================
-- Exact Occid Search Query for Flat Index
-- ============================================================================
-- This template performs exact occid searches using indexed lookup.
--
-- Strategy:
-- 1. Create temp table with matching occids (uses indexed lookup on media.occid)
-- 2. Apply additional filters if provided
-- 3. JOIN to get full metadata
--
-- Placeholders:
--   {LIMIT} - LIMIT value (integer)
--   {OFFSET} - OFFSET value (integer)
--
-- Performance: Uses indexed lookup on media.occid, should be near-instant
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
INNER JOIN temp_matching_occids tmp ON m.occid = tmp.occid
INNER JOIN omoccurrences o ON m.occid = o.occid
LEFT JOIN omcollections c ON o.collid = c.collid
LEFT JOIN taxa t ON o.tidinterpreted = t.tid
LIMIT {LIMIT} OFFSET {OFFSET};

