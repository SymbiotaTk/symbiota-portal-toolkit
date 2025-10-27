-- ============================================================================
-- Create Autocomplete Table for Collection
-- ============================================================================
-- This template creates the AutocompleteCollection table with values from:
--   - omcollections.collectionCode
--   - omcollections.institutionCode
--   - omcollections.collectionName
-- ============================================================================

DROP TABLE IF EXISTS AutocompleteCollection;

CREATE TABLE AutocompleteCollection (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    value TEXT NOT NULL,
    field TEXT NOT NULL,
    count INTEGER DEFAULT 1,
    UNIQUE(value, field)
);

-- Insert from omcollections.collectionCode
INSERT OR IGNORE INTO AutocompleteCollection (value, field, count)
SELECT 
    collectionCode AS value,
    'collectionCode' AS field,
    COUNT(DISTINCT collid) AS count
FROM omcollections
WHERE collectionCode IS NOT NULL 
  AND LENGTH(collectionCode) >= 2
GROUP BY collectionCode;

-- Insert from omcollections.institutionCode
INSERT OR IGNORE INTO AutocompleteCollection (value, field, count)
SELECT 
    institutionCode AS value,
    'institutionCode' AS field,
    COUNT(DISTINCT collid) AS count
FROM omcollections
WHERE institutionCode IS NOT NULL 
  AND LENGTH(institutionCode) >= 2
GROUP BY institutionCode;

-- Insert from omcollections.collectionName
INSERT OR IGNORE INTO AutocompleteCollection (value, field, count)
SELECT 
    collectionName AS value,
    'collectionName' AS field,
    COUNT(DISTINCT collid) AS count
FROM omcollections
WHERE collectionName IS NOT NULL 
  AND LENGTH(collectionName) >= 2
GROUP BY collectionName;

-- Create index for fast autocomplete lookups
CREATE INDEX IF NOT EXISTS idx_autocomplete_collection_value ON AutocompleteCollection(value COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_autocomplete_collection_field ON AutocompleteCollection(field);

