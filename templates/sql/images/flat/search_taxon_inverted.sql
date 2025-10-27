-- ============================================================================
-- Search Taxon Using Inverted Index (Triple Store Concept)
-- ============================================================================
-- This template performs a 3-step inverted index search for taxon queries:
-- 1. Find matching token_id values from TaxonTokens WHERE taxon_value LIKE 'query%'
-- 2. Find occid values from OccurrenceTaxonIndex WHERE token_id IN (...)
-- 3. Join to media using occid list
--
-- This is much faster than OR queries across multiple fields.
--
-- Placeholders:
--   {token_placeholders} - Comma-separated ? for token_id IN clause
--   {occid_placeholders} - Comma-separated ? for occid IN clause
-- ============================================================================

-- Step 1: Find matching token_id values (executed separately in PHP)
-- SELECT token_id FROM TaxonTokens WHERE taxon_value LIKE ? COLLATE NOCASE

-- Step 2: Find occids from inverted index (executed separately in PHP)
-- SELECT DISTINCT occid FROM OccurrenceTaxonIndex WHERE token_id IN ({token_placeholders})

-- Step 3: Get media records for matching occids
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
INNER JOIN omoccurrences o ON m.occid = o.occid
LEFT JOIN omcollections c ON o.collid = c.collid
LEFT JOIN taxa t ON o.tidinterpreted = t.tid
WHERE m.occid IN ({occid_placeholders})
LIMIT ? OFFSET ?

