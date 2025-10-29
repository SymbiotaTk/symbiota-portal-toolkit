-- ============================================================================
-- Create Optimized Collection Lookup Index
-- ============================================================================
-- OPTIMIZED VERSION: Uses collid-based lookup instead of text tokenization
--
-- This creates an optimized collection search index that:
-- 1. Stores each collection ONCE in CollectionLookup (not once per occurrence)
-- 2. Maps occid -> collid in OccurrenceCollectionIndex (integer-only, no text)
-- 3. Enables fast LIKE search on small CollectionLookup table (~100-1000 rows)
-- 4. Enables fast integer lookup for occid -> collid mapping
--
-- Performance benefits over token-based approach:
-- - 10-20x faster collection searches (LIKE on 100 rows vs 6M rows)
-- - Smaller index size (one collid per occurrence vs multiple token_ids)
-- - No duplication (collection data stored once, not per occurrence)
--
-- Structure:
-- - CollectionLookup: collid, collectionName, collectionCode, institutionCode
-- - OccurrenceCollectionIndex: occid, collid (simple mapping)
--
-- Query flow:
-- 1. Find collid WHERE collectionName/Code/Institution LIKE 'query%' (indexed, small table)
-- 2. Find occid WHERE collid IN (...) (indexed, integer-only)
-- 3. Apply additional filters on occid set
-- 4. Join to media
--
-- Note: This replaces the token-based approach in create_collection_inverted_index.sql
-- The old CollectionTokens table is kept for backward compatibility with autocomplete
-- but should eventually be migrated to use CollectionLookup as well.
-- ============================================================================

-- Step 1: Create CollectionLookup table (stores unique collections)
DROP TABLE IF EXISTS CollectionLookup;
CREATE TABLE CollectionLookup (
    collid INTEGER PRIMARY KEY,
    collectionName TEXT,
    collectionCode TEXT,
    institutionCode TEXT
);

-- Step 2: Populate CollectionLookup from omcollections
-- This is a small table (~100-1000 rows) that stores each collection once
INSERT INTO CollectionLookup (collid, collectionName, collectionCode, institutionCode)
SELECT collid, collectionName, collectionCode, institutionCode
FROM omcollections
WHERE collid IS NOT NULL;

-- Step 3: Create indexes on CollectionLookup for fast text searches
-- These indexes enable fast LIKE searches on collection fields
CREATE INDEX idx_collection_lookup_name ON CollectionLookup(collectionName COLLATE NOCASE);
CREATE INDEX idx_collection_lookup_code ON CollectionLookup(collectionCode COLLATE NOCASE);
CREATE INDEX idx_collection_lookup_institution ON CollectionLookup(institutionCode COLLATE NOCASE);

-- Step 4: Create OccurrenceCollectionIndex table (maps occid -> collid)
-- This is much simpler than the token-based approach (one row per occurrence, not per token)
DROP TABLE IF EXISTS OccurrenceCollectionIndex;
CREATE TABLE OccurrenceCollectionIndex (
    occid INTEGER PRIMARY KEY,
    collid INTEGER NOT NULL
);

-- Step 5: Populate OccurrenceCollectionIndex from omoccurrences
-- Simple mapping: each occurrence has one collid
INSERT INTO OccurrenceCollectionIndex (occid, collid)
SELECT occid, collid
FROM omoccurrences
WHERE collid IS NOT NULL;

-- Step 6: Create indexes for fast lookups in both directions
-- Index on collid: enables fast "find all occurrences for this collection" queries
CREATE INDEX idx_occ_collection_collid ON OccurrenceCollectionIndex(collid);
-- Note: occid is PRIMARY KEY so it already has an implicit index

-- ============================================================================
-- Legacy CollectionTokens table (for backward compatibility with autocomplete)
-- ============================================================================
-- TODO: Migrate autocomplete to use CollectionLookup instead of CollectionTokens
-- For now, we keep both tables to maintain backward compatibility
-- ============================================================================

-- Step 7: Create CollectionTokens table with distinct values from all 4 fields
-- This is kept for backward compatibility with existing autocomplete code
DROP TABLE IF EXISTS CollectionTokens;
CREATE TABLE CollectionTokens (
    token_id INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_value TEXT NOT NULL UNIQUE COLLATE NOCASE,
    count INTEGER DEFAULT 0
);

-- Step 8: Populate CollectionTokens with distinct values and counts from all 4 collection fields
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

-- Step 9: Create index on collection_value for fast LIKE searches (autocomplete)
CREATE INDEX idx_collection_tokens_value ON CollectionTokens(collection_value COLLATE NOCASE);
CREATE INDEX idx_collection_tokens_count ON CollectionTokens(count DESC);

