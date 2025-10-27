-- ============================================================================
-- Create Taxon Inverted Index (Triple Store Concept)
-- ============================================================================
-- This creates a proper inverted index for fast taxon searches across 5 fields:
-- 1. omoccurrences.family
-- 2. omoccurrences.genus
-- 3. omoccurrences.sciname
-- 4. omoccurrences.scientificname
-- 5. taxa.sciname (via tidinterpreted)
--
-- Structure:
-- - TaxonTokens: token_id, taxon_value (distinct taxon values)
-- - OccurrenceTaxonIndex: occid, token_id (inverted index)
--
-- Query flow:
-- 1. Find token_id WHERE taxon_value LIKE 'query%' (indexed)
-- 2. Find occid WHERE token_id IN (...) (indexed)
-- 3. Apply additional filters on occid set
-- 4. Join to media
-- ============================================================================

-- Step 1: Create TaxonTokens table with distinct values from all 5 fields
-- This replaces AutocompleteTaxon and serves both autocomplete and search purposes
DROP TABLE IF EXISTS TaxonTokens;
CREATE TABLE TaxonTokens (
    token_id INTEGER PRIMARY KEY AUTOINCREMENT,
    taxon_value TEXT NOT NULL UNIQUE COLLATE NOCASE,
    count INTEGER DEFAULT 0
);

-- Step 2: Populate TaxonTokens with distinct values and counts from all 5 taxon fields
-- Combine all sources with counts
-- Use LOWER() to normalize case before grouping to avoid UNIQUE constraint violations
INSERT INTO TaxonTokens (taxon_value, count)
SELECT value, SUM(cnt) as count
FROM (
    SELECT family AS value, COUNT(*) AS cnt
    FROM omoccurrences
    WHERE family IS NOT NULL AND family != '' AND LENGTH(family) >= 2
    GROUP BY family COLLATE NOCASE

    UNION ALL

    SELECT genus AS value, COUNT(*) AS cnt
    FROM omoccurrences
    WHERE genus IS NOT NULL AND genus != '' AND LENGTH(genus) >= 2
    GROUP BY genus COLLATE NOCASE

    UNION ALL

    SELECT sciname AS value, COUNT(*) AS cnt
    FROM omoccurrences
    WHERE sciname IS NOT NULL AND sciname != '' AND LENGTH(sciname) >= 2
    GROUP BY sciname COLLATE NOCASE

    UNION ALL

    SELECT scientificname AS value, COUNT(*) AS cnt
    FROM omoccurrences
    WHERE scientificname IS NOT NULL AND scientificname != '' AND LENGTH(scientificname) >= 2
    GROUP BY scientificname COLLATE NOCASE

    UNION ALL

    SELECT t.sciname AS value, COUNT(*) AS cnt
    FROM taxa t
    INNER JOIN omoccurrences o ON o.tidinterpreted = t.tid
    WHERE t.sciname IS NOT NULL AND t.sciname != '' AND LENGTH(t.sciname) >= 2
    GROUP BY t.sciname COLLATE NOCASE
)
GROUP BY value COLLATE NOCASE;

-- Step 3: Create index on taxon_value for fast LIKE searches (autocomplete and search)
CREATE INDEX idx_taxon_tokens_value ON TaxonTokens(taxon_value COLLATE NOCASE);
CREATE INDEX idx_taxon_tokens_count ON TaxonTokens(count DESC);

-- Step 4: Create OccurrenceTaxonIndex table (inverted index)
DROP TABLE IF EXISTS OccurrenceTaxonIndex;
CREATE TABLE OccurrenceTaxonIndex (
    occid INTEGER NOT NULL,
    token_id INTEGER NOT NULL,
    PRIMARY KEY (occid, token_id)
);

-- Step 5: Populate inverted index from family field
INSERT OR IGNORE INTO OccurrenceTaxonIndex (occid, token_id)
SELECT o.occid, t.token_id
FROM omoccurrences o
INNER JOIN TaxonTokens t ON o.family = t.taxon_value COLLATE NOCASE
WHERE o.family IS NOT NULL AND o.family != '';

-- Step 6: Populate inverted index from genus field
INSERT OR IGNORE INTO OccurrenceTaxonIndex (occid, token_id)
SELECT o.occid, t.token_id
FROM omoccurrences o
INNER JOIN TaxonTokens t ON o.genus = t.taxon_value COLLATE NOCASE
WHERE o.genus IS NOT NULL AND o.genus != '';

-- Step 7: Populate inverted index from sciname field
INSERT OR IGNORE INTO OccurrenceTaxonIndex (occid, token_id)
SELECT o.occid, t.token_id
FROM omoccurrences o
INNER JOIN TaxonTokens t ON o.sciname = t.taxon_value COLLATE NOCASE
WHERE o.sciname IS NOT NULL AND o.sciname != '';

-- Step 8: Populate inverted index from scientificname field
INSERT OR IGNORE INTO OccurrenceTaxonIndex (occid, token_id)
SELECT o.occid, t.token_id
FROM omoccurrences o
INNER JOIN TaxonTokens t ON o.scientificname = t.taxon_value COLLATE NOCASE
WHERE o.scientificname IS NOT NULL AND o.scientificname != '';

-- Step 9: Populate inverted index from taxa.sciname field
INSERT OR IGNORE INTO OccurrenceTaxonIndex (occid, token_id)
SELECT o.occid, t.token_id
FROM omoccurrences o
INNER JOIN taxa tx ON o.tidinterpreted = tx.tid
INNER JOIN TaxonTokens t ON tx.sciname = t.taxon_value COLLATE NOCASE
WHERE tx.sciname IS NOT NULL AND tx.sciname != '';

-- Step 10: Create indexes on inverted index table for fast lookups
CREATE INDEX idx_occ_taxon_occid ON OccurrenceTaxonIndex(occid);
CREATE INDEX idx_occ_taxon_token ON OccurrenceTaxonIndex(token_id);

