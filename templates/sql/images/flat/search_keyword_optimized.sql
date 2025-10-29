-- ============================================================================
-- Optimized Keyword Search Using Triple Inverted Index
-- ============================================================================
-- This template performs keyword searches across ALL searchable fields using
-- three inverted indexes instead of 11 LIKE operations with OR logic.
--
-- Strategy:
-- 1. Search TaxonTokens for matching values
-- 2. Search LocationTokens for matching values
-- 3. Search CollectionTokens for matching values
-- 4. UNION all matching occids from the three inverted indexes
-- 5. Apply additional filters (if any)
-- 6. JOIN to media and related tables
--
-- Placeholders:
--   {SEARCH_TERM} - Search term with wildcards (e.g., '%maple%')
--   {ADDITIONAL_WHERE} - Additional WHERE conditions (optional)
--   {LIMIT} - LIMIT clause (e.g., "LIMIT ?")
--   {OFFSET} - OFFSET clause (e.g., "OFFSET ?")
-- ============================================================================

-- Step 1: Create temporary table for matching occids
DROP TABLE IF EXISTS temp_keyword_occids;
CREATE TEMPORARY TABLE temp_keyword_occids (occid INTEGER PRIMARY KEY);

-- Step 2: Insert occids from taxon matches
INSERT OR IGNORE INTO temp_keyword_occids (occid)
SELECT DISTINCT idx.occid
FROM TaxonTokens t
INNER JOIN OccurrenceTaxonIndex idx ON t.token_id = idx.token_id
WHERE t.taxon_value LIKE {SEARCH_TERM} COLLATE NOCASE;

-- Step 3: Insert occids from location matches
INSERT OR IGNORE INTO temp_keyword_occids (occid)
SELECT DISTINCT idx.occid
FROM LocationTokens t
INNER JOIN OccurrenceLocationIndex idx ON t.token_id = idx.token_id
WHERE t.location_value LIKE {SEARCH_TERM} COLLATE NOCASE;

-- Step 4: Insert occids from collection matches
INSERT OR IGNORE INTO temp_keyword_occids (occid)
SELECT DISTINCT idx.occid
FROM CollectionTokens t
INNER JOIN OccurrenceCollectionIndex idx ON t.token_id = idx.token_id
WHERE t.collection_value LIKE {SEARCH_TERM} COLLATE NOCASE;

-- Step 5: Apply additional filters (if any)
{ADDITIONAL_FILTERS}

-- Step 6: Get media records using temporary occid table
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
INNER JOIN temp_keyword_occids tmp ON m.occid = tmp.occid
INNER JOIN omoccurrences o ON m.occid = o.occid
LEFT JOIN omcollections c ON o.collid = c.collid
LEFT JOIN taxa t ON o.tidinterpreted = t.tid
{LIMIT}
{OFFSET};

