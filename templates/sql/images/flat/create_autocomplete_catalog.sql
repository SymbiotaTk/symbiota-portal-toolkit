-- ============================================================================
-- Create Autocomplete Table for Catalog Numbers
-- ============================================================================
-- This template creates the AutocompleteCatalog table with values from:
--   - omoccurrences.catalogNumber
-- ============================================================================

DROP TABLE IF EXISTS AutocompleteCatalog;

CREATE TABLE AutocompleteCatalog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    value TEXT NOT NULL,
    count INTEGER DEFAULT 1,
    UNIQUE(value)
);

-- Insert from omoccurrences.catalogNumber
INSERT OR IGNORE INTO AutocompleteCatalog (value, count)
SELECT 
    catalogNumber AS value,
    COUNT(*) AS count
FROM omoccurrences
WHERE catalogNumber IS NOT NULL 
  AND LENGTH(catalogNumber) >= 2
GROUP BY catalogNumber;

-- Create index for fast autocomplete lookups
CREATE INDEX IF NOT EXISTS idx_autocomplete_catalog_value ON AutocompleteCatalog(value COLLATE NOCASE);

