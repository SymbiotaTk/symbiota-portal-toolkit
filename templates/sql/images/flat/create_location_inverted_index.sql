-- ============================================================================
-- Create Location Inverted Index
-- ============================================================================
-- This creates an inverted index for fast location searches across 4 fields:
-- 1. omoccurrences.country
-- 2. omoccurrences.stateProvince
-- 3. omoccurrences.county
-- 4. omoccurrences.locality
--
-- Structure:
-- - LocationTokens: token_id, location_value (distinct location values)
-- - OccurrenceLocationIndex: occid, token_id (inverted index)
--
-- Query flow:
-- 1. Find token_id WHERE location_value LIKE 'query%' (indexed)
-- 2. Find occid WHERE token_id IN (...) (indexed)
-- 3. Apply additional filters on occid set
-- 4. Join to media
-- ============================================================================

-- Step 1: Create LocationTokens table with distinct values from all 4 fields
DROP TABLE IF EXISTS LocationTokens;
CREATE TABLE LocationTokens (
    token_id INTEGER PRIMARY KEY AUTOINCREMENT,
    location_value TEXT NOT NULL UNIQUE COLLATE NOCASE,
    count INTEGER DEFAULT 0
);

-- Step 2: Populate LocationTokens with distinct values and counts from all 4 location fields
-- Use LOWER() to normalize case before grouping to avoid UNIQUE constraint violations
INSERT INTO LocationTokens (location_value, count)
SELECT value, SUM(cnt) as count
FROM (
    SELECT country AS value, COUNT(*) AS cnt
    FROM omoccurrences
    WHERE country IS NOT NULL AND country != '' AND LENGTH(country) >= 2
    GROUP BY country COLLATE NOCASE

    UNION ALL

    SELECT stateProvince AS value, COUNT(*) AS cnt
    FROM omoccurrences
    WHERE stateProvince IS NOT NULL AND stateProvince != '' AND LENGTH(stateProvince) >= 2
    GROUP BY stateProvince COLLATE NOCASE

    UNION ALL

    SELECT county AS value, COUNT(*) AS cnt
    FROM omoccurrences
    WHERE county IS NOT NULL AND county != '' AND LENGTH(county) >= 2
    GROUP BY county COLLATE NOCASE

    UNION ALL

    SELECT locality AS value, COUNT(*) AS cnt
    FROM omoccurrences
    WHERE locality IS NOT NULL AND locality != '' AND LENGTH(locality) >= 2
    GROUP BY locality COLLATE NOCASE
)
GROUP BY value COLLATE NOCASE;

-- Step 3: Create index on location_value for fast LIKE searches (autocomplete and search)
CREATE INDEX idx_location_tokens_value ON LocationTokens(location_value COLLATE NOCASE);
CREATE INDEX idx_location_tokens_count ON LocationTokens(count DESC);

-- Step 4: Create OccurrenceLocationIndex table (inverted index)
DROP TABLE IF EXISTS OccurrenceLocationIndex;
CREATE TABLE OccurrenceLocationIndex (
    occid INTEGER NOT NULL,
    token_id INTEGER NOT NULL,
    PRIMARY KEY (occid, token_id)
);

-- Step 5: Populate inverted index from country field
INSERT OR IGNORE INTO OccurrenceLocationIndex (occid, token_id)
SELECT o.occid, t.token_id
FROM omoccurrences o
INNER JOIN LocationTokens t ON o.country = t.location_value COLLATE NOCASE
WHERE o.country IS NOT NULL AND o.country != '';

-- Step 6: Populate inverted index from stateProvince field
INSERT OR IGNORE INTO OccurrenceLocationIndex (occid, token_id)
SELECT o.occid, t.token_id
FROM omoccurrences o
INNER JOIN LocationTokens t ON o.stateProvince = t.location_value COLLATE NOCASE
WHERE o.stateProvince IS NOT NULL AND o.stateProvince != '';

-- Step 7: Populate inverted index from county field
INSERT OR IGNORE INTO OccurrenceLocationIndex (occid, token_id)
SELECT o.occid, t.token_id
FROM omoccurrences o
INNER JOIN LocationTokens t ON o.county = t.location_value COLLATE NOCASE
WHERE o.county IS NOT NULL AND o.county != '';

-- Step 8: Populate inverted index from locality field
INSERT OR IGNORE INTO OccurrenceLocationIndex (occid, token_id)
SELECT o.occid, t.token_id
FROM omoccurrences o
INNER JOIN LocationTokens t ON o.locality = t.location_value COLLATE NOCASE
WHERE o.locality IS NOT NULL AND o.locality != '';

-- Step 9: Create indexes on inverted index table for fast lookups
CREATE INDEX idx_occ_location_occid ON OccurrenceLocationIndex(occid);
CREATE INDEX idx_occ_location_token ON OccurrenceLocationIndex(token_id);

