-- ============================================================================
-- Create Autocomplete Table for Location
-- ============================================================================
-- This template creates the AutocompleteLocation table with values from:
--   - omoccurrences.country
--   - omoccurrences.stateProvince
--   - omoccurrences.county
-- ============================================================================

DROP TABLE IF EXISTS AutocompleteLocation;

CREATE TABLE AutocompleteLocation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    value TEXT NOT NULL,
    field TEXT NOT NULL,
    count INTEGER DEFAULT 1,
    UNIQUE(value, field)
);

-- Insert from omoccurrences.country
INSERT OR IGNORE INTO AutocompleteLocation (value, field, count)
SELECT 
    country AS value,
    'country' AS field,
    COUNT(*) AS count
FROM omoccurrences
WHERE country IS NOT NULL 
  AND LENGTH(country) >= 2
GROUP BY country;

-- Insert from omoccurrences.stateProvince
INSERT OR IGNORE INTO AutocompleteLocation (value, field, count)
SELECT 
    stateProvince AS value,
    'stateProvince' AS field,
    COUNT(*) AS count
FROM omoccurrences
WHERE stateProvince IS NOT NULL 
  AND LENGTH(stateProvince) >= 2
GROUP BY stateProvince;

-- Insert from omoccurrences.county
INSERT OR IGNORE INTO AutocompleteLocation (value, field, count)
SELECT 
    county AS value,
    'county' AS field,
    COUNT(*) AS count
FROM omoccurrences
WHERE county IS NOT NULL 
  AND LENGTH(county) >= 2
GROUP BY county;

-- Create index for fast autocomplete lookups
CREATE INDEX IF NOT EXISTS idx_autocomplete_location_value ON AutocompleteLocation(value COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_autocomplete_location_field ON AutocompleteLocation(field);

