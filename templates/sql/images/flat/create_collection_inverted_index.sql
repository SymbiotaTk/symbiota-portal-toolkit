-- ============================================================================
-- Create Collection Inverted Index
-- ============================================================================
-- This creates an inverted index for fast collection searches across 4 fields:
-- 1. omoccurrences.catalogNumber
-- 2. omcollections.collectionCode
-- 3. omcollections.institutionCode
-- 4. omcollections.collectionName
--
-- Structure:
-- - CollectionTokens: token_id, collection_value (distinct collection values)
-- - OccurrenceCollectionIndex: occid, token_id (inverted index)
--
-- Query flow:
-- 1. Find token_id WHERE collection_value LIKE 'query%' (indexed)
-- 2. Find occid WHERE token_id IN (...) (indexed)
-- 3. Apply additional filters on occid set
-- 4. Join to media
-- ============================================================================

-- Step 1: Create CollectionTokens table with distinct values from all 4 fields
DROP TABLE IF EXISTS CollectionTokens;
CREATE TABLE CollectionTokens (
    token_id INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_value TEXT NOT NULL UNIQUE COLLATE NOCASE,
    count INTEGER DEFAULT 0
);

-- Step 2: Populate CollectionTokens with distinct values and counts from all 4 collection fields
-- Use LOWER() to normalize case before grouping to avoid UNIQUE constraint violations
INSERT INTO CollectionTokens (collection_value, count)
SELECT value, SUM(cnt) as count
FROM (
    SELECT catalogNumber AS value, COUNT(*) AS cnt
    FROM omoccurrences
    WHERE catalogNumber IS NOT NULL AND catalogNumber != '' AND LENGTH(catalogNumber) >= 1
    GROUP BY catalogNumber COLLATE NOCASE

    UNION ALL

    SELECT collectionCode AS value, COUNT(*) AS cnt
    FROM omcollections
    WHERE collectionCode IS NOT NULL AND collectionCode != '' AND LENGTH(collectionCode) >= 1
    GROUP BY collectionCode COLLATE NOCASE

    UNION ALL

    SELECT institutionCode AS value, COUNT(*) AS cnt
    FROM omcollections
    WHERE institutionCode IS NOT NULL AND institutionCode != '' AND LENGTH(institutionCode) >= 1
    GROUP BY institutionCode COLLATE NOCASE

    UNION ALL

    SELECT collectionName AS value, COUNT(*) AS cnt
    FROM omcollections
    WHERE collectionName IS NOT NULL AND collectionName != '' AND LENGTH(collectionName) >= 2
    GROUP BY collectionName COLLATE NOCASE
)
GROUP BY value COLLATE NOCASE;

-- Step 3: Create index on collection_value for fast LIKE searches (autocomplete and search)
CREATE INDEX idx_collection_tokens_value ON CollectionTokens(collection_value COLLATE NOCASE);
CREATE INDEX idx_collection_tokens_count ON CollectionTokens(count DESC);

-- Step 4: Create OccurrenceCollectionIndex table (inverted index)
DROP TABLE IF EXISTS OccurrenceCollectionIndex;
CREATE TABLE OccurrenceCollectionIndex (
    occid INTEGER NOT NULL,
    token_id INTEGER NOT NULL,
    PRIMARY KEY (occid, token_id)
);

-- Step 5: Populate inverted index from catalogNumber field
INSERT OR IGNORE INTO OccurrenceCollectionIndex (occid, token_id)
SELECT o.occid, t.token_id
FROM omoccurrences o
INNER JOIN CollectionTokens t ON o.catalogNumber = t.collection_value COLLATE NOCASE
WHERE o.catalogNumber IS NOT NULL AND o.catalogNumber != '';

-- Step 6: Populate inverted index from collectionCode field
-- Need to join through omoccurrences to get occid
INSERT OR IGNORE INTO OccurrenceCollectionIndex (occid, token_id)
SELECT o.occid, t.token_id
FROM omoccurrences o
INNER JOIN omcollections c ON o.collid = c.collid
INNER JOIN CollectionTokens t ON c.collectionCode = t.collection_value COLLATE NOCASE
WHERE c.collectionCode IS NOT NULL AND c.collectionCode != '';

-- Step 7: Populate inverted index from institutionCode field
INSERT OR IGNORE INTO OccurrenceCollectionIndex (occid, token_id)
SELECT o.occid, t.token_id
FROM omoccurrences o
INNER JOIN omcollections c ON o.collid = c.collid
INNER JOIN CollectionTokens t ON c.institutionCode = t.collection_value COLLATE NOCASE
WHERE c.institutionCode IS NOT NULL AND c.institutionCode != '';

-- Step 8: Populate inverted index from collectionName field
INSERT OR IGNORE INTO OccurrenceCollectionIndex (occid, token_id)
SELECT o.occid, t.token_id
FROM omoccurrences o
INNER JOIN omcollections c ON o.collid = c.collid
INNER JOIN CollectionTokens t ON c.collectionName = t.collection_value COLLATE NOCASE
WHERE c.collectionName IS NOT NULL AND c.collectionName != '';

-- Step 9: Create indexes on inverted index table for fast lookups
CREATE INDEX idx_occ_collection_occid ON OccurrenceCollectionIndex(occid);
CREATE INDEX idx_occ_collection_token ON OccurrenceCollectionIndex(token_id);

