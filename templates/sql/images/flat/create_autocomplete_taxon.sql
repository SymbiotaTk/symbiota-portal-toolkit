-- ============================================================================
-- Create Autocomplete Table for Taxonomy
-- ============================================================================
-- This template creates the AutocompleteTaxon table with values from:
--   - omoccurrences.sciname (original determination)
--   - omoccurrences.scientificname (current determination)
--   - omoccurrences.family
--   - omoccurrences.genus
--   - taxa.sciname (interpreted taxonomy via tidinterpreted)
-- ============================================================================

DROP TABLE IF EXISTS AutocompleteTaxon;

CREATE TABLE AutocompleteTaxon (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    value TEXT NOT NULL,
    field TEXT NOT NULL,
    count INTEGER DEFAULT 1,
    UNIQUE(value, field)
);

-- Insert from omoccurrences.sciname
INSERT OR IGNORE INTO AutocompleteTaxon (value, field, count)
SELECT 
    sciname AS value,
    'sciname' AS field,
    COUNT(*) AS count
FROM omoccurrences
WHERE sciname IS NOT NULL 
  AND LENGTH(sciname) >= 2
GROUP BY sciname;

-- Insert from omoccurrences.scientificname
INSERT OR IGNORE INTO AutocompleteTaxon (value, field, count)
SELECT 
    scientificname AS value,
    'scientificname' AS field,
    COUNT(*) AS count
FROM omoccurrences
WHERE scientificname IS NOT NULL 
  AND LENGTH(scientificname) >= 2
GROUP BY scientificname;

-- Insert from omoccurrences.family
INSERT OR IGNORE INTO AutocompleteTaxon (value, field, count)
SELECT 
    family AS value,
    'family' AS field,
    COUNT(*) AS count
FROM omoccurrences
WHERE family IS NOT NULL 
  AND LENGTH(family) >= 2
GROUP BY family;

-- Insert from omoccurrences.genus
INSERT OR IGNORE INTO AutocompleteTaxon (value, field, count)
SELECT 
    genus AS value,
    'genus' AS field,
    COUNT(*) AS count
FROM omoccurrences
WHERE genus IS NOT NULL 
  AND LENGTH(genus) >= 2
GROUP BY genus;

-- Insert from taxa.sciname (via tidinterpreted)
INSERT OR IGNORE INTO AutocompleteTaxon (value, field, count)
SELECT 
    t.sciname AS value,
    'taxa_sciname' AS field,
    COUNT(*) AS count
FROM taxa t
WHERE t.sciname IS NOT NULL 
  AND LENGTH(t.sciname) >= 2
GROUP BY t.sciname;

-- Create index for fast autocomplete lookups
CREATE INDEX IF NOT EXISTS idx_autocomplete_taxon_value ON AutocompleteTaxon(value COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_autocomplete_taxon_field ON AutocompleteTaxon(field);

