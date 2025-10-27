-- ============================================================================
-- Search Query for Flat Index (Normalized Schema)
-- ============================================================================
-- This template performs searches across the normalized tables with JOINs.
--
-- Placeholders:
--   {where_clauses} - WHERE conditions (e.g., "o.family LIKE '%term%'")
--   {order_by} - ORDER BY clause (e.g., "o.family, o.genus")
--   {limit} - LIMIT clause (e.g., "LIMIT 30")
--   {offset} - OFFSET clause (e.g., "OFFSET 0")
-- ============================================================================

SELECT 
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
LEFT JOIN omoccurrences o ON m.occid = o.occid
LEFT JOIN omcollections c ON o.collid = c.collid
LEFT JOIN taxa t ON o.tidinterpreted = t.tid
WHERE {where_clauses}
{order_by}
{limit}
{offset};

