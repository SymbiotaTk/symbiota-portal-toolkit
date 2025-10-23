-- Create specialized autocomplete indexes for fast lookups
-- These pre-aggregate distinct values and image counts for frequently searched fields

-- Drop existing autocomplete tables and views if they exist
DROP VIEW IF EXISTS autocomplete_summary;
DROP TABLE IF EXISTS autocomplete_taxon;
DROP TABLE IF EXISTS autocomplete_collection;
DROP TABLE IF EXISTS autocomplete_location;
DROP TABLE IF EXISTS autocomplete_date;

-- ============================================================================
-- Taxon Autocomplete Index
-- Aggregates: sciname, sciName, scientificName
-- These are :whole text attributes, so VidArray contains a single Vid (no parsing needed)
-- ============================================================================
CREATE TABLE autocomplete_taxon (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    taxon_name TEXT NOT NULL COLLATE NOCASE,
    image_count INTEGER NOT NULL DEFAULT 0,
    source_field TEXT NOT NULL,  -- Which field it came from (sciname, sciName, scientificName)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Populate taxon autocomplete from all taxon-related fields
-- OPTIMIZED: Direct JOIN on Vid (normalized schema)
INSERT INTO autocomplete_taxon (taxon_name, image_count, source_field)
SELECT
    vt.ValueText as taxon_name,
    COUNT(DISTINCT eav.Eid) as image_count,
    attr.ColumnName as source_field
FROM EAV eav
JOIN Attributes attr ON eav.Aid = attr.Aid
JOIN ValuesText vt ON vt.Vid = eav.Vid
WHERE attr.ColumnName IN (
    SELECT ColumnName FROM Attributes
    WHERE ColumnName IN ('sciname', 'sciName', 'scientificName')
)
    AND eav.Vid IS NOT NULL
    AND vt.ValueText IS NOT NULL
    AND vt.ValueText != ''
GROUP BY vt.ValueText, attr.ColumnName
HAVING image_count > 0
ORDER BY image_count DESC, taxon_name ASC;

-- Create indexes for fast lookups
CREATE INDEX idx_autocomplete_taxon_name ON autocomplete_taxon(taxon_name COLLATE NOCASE);
CREATE INDEX idx_autocomplete_taxon_count ON autocomplete_taxon(image_count DESC);

-- ============================================================================
-- Collection Autocomplete Index
-- Aggregates: collectionName, collectionCode, institutionCode, collid
-- These are :whole text attributes, so VidArray contains a single Vid (no parsing needed)
-- ============================================================================
CREATE TABLE autocomplete_collection (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_value TEXT NOT NULL COLLATE NOCASE,
    image_count INTEGER NOT NULL DEFAULT 0,
    source_field TEXT NOT NULL,  -- collectionName, collectionCode, institutionCode, or collid
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Populate collection autocomplete from all collection-related TEXT fields
-- OPTIMIZED: Direct JOIN on Vid (normalized schema)
INSERT INTO autocomplete_collection (collection_value, image_count, source_field)
SELECT
    vt.ValueText as collection_value,
    COUNT(DISTINCT eav.Eid) as image_count,
    attr.ColumnName as source_field
FROM EAV eav
JOIN Attributes attr ON eav.Aid = attr.Aid
JOIN ValuesText vt ON vt.Vid = eav.Vid
WHERE attr.ColumnName IN (
    SELECT ColumnName FROM Attributes
    WHERE ColumnName IN ('collectionName', 'collectionCode', 'institutionCode')
)
    AND eav.Vid IS NOT NULL
    AND vt.ValueText IS NOT NULL
    AND vt.ValueText != ''
GROUP BY vt.ValueText, attr.ColumnName
HAVING image_count > 0
ORDER BY image_count DESC, collection_value ASC;

-- Add numeric collid values
INSERT INTO autocomplete_collection (collection_value, image_count, source_field)
SELECT
    CAST(eav.ValueNumber AS TEXT) as collection_value,
    COUNT(DISTINCT eav.Eid) as image_count,
    'collid' as source_field
FROM EAV eav
JOIN Attributes attr ON eav.Aid = attr.Aid
WHERE attr.ColumnName = 'collid'
    AND eav.ValueNumber IS NOT NULL
GROUP BY eav.ValueNumber
HAVING image_count > 0
ORDER BY image_count DESC;

-- Create indexes for fast lookups
CREATE INDEX idx_autocomplete_collection_value ON autocomplete_collection(collection_value COLLATE NOCASE);
CREATE INDEX idx_autocomplete_collection_count ON autocomplete_collection(image_count DESC);
CREATE INDEX idx_autocomplete_collection_field ON autocomplete_collection(source_field);

-- ============================================================================
-- Location Autocomplete Index
-- Aggregates: county, country, stateProvince
-- These are :whole text attributes, so VidArray contains a single Vid (no parsing needed)
-- ============================================================================
CREATE TABLE autocomplete_location (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    location_value TEXT NOT NULL COLLATE NOCASE,
    image_count INTEGER NOT NULL DEFAULT 0,
    source_field TEXT NOT NULL,  -- county, country, or stateProvince
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Populate location autocomplete from all location-related fields
-- OPTIMIZED: Direct JOIN on Vid (normalized schema)
INSERT INTO autocomplete_location (location_value, image_count, source_field)
SELECT
    vt.ValueText as location_value,
    COUNT(DISTINCT eav.Eid) as image_count,
    attr.ColumnName as source_field
FROM EAV eav
JOIN Attributes attr ON eav.Aid = attr.Aid
JOIN ValuesText vt ON vt.Vid = eav.Vid
WHERE attr.ColumnName IN (
    SELECT ColumnName FROM Attributes
    WHERE ColumnName IN ('county', 'country', 'stateProvince')
)
    AND eav.Vid IS NOT NULL
    AND vt.ValueText IS NOT NULL
    AND vt.ValueText != ''
GROUP BY vt.ValueText, attr.ColumnName
HAVING image_count > 0
ORDER BY image_count DESC, location_value ASC;

-- Create indexes for fast lookups
CREATE INDEX idx_autocomplete_location_value ON autocomplete_location(location_value COLLATE NOCASE);
CREATE INDEX idx_autocomplete_location_count ON autocomplete_location(image_count DESC);
CREATE INDEX idx_autocomplete_location_field ON autocomplete_location(source_field);

-- ============================================================================
-- Date Autocomplete Index
-- Aggregates: eventDate (years only for autocomplete)
-- eventDate is a :whole attribute, so VidArray contains a single Vid (no parsing needed)
-- ============================================================================
CREATE TABLE autocomplete_date (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year TEXT NOT NULL,
    image_count INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Populate date autocomplete with years from eventDate
-- OPTIMIZED: Direct JOIN on Vid (normalized schema), extract year from date string
INSERT INTO autocomplete_date (year, image_count)
SELECT
    SUBSTR(vt.ValueText, 1, 4) as year,
    COUNT(DISTINCT eav.Eid) as image_count
FROM EAV eav
JOIN Attributes attr ON eav.Aid = attr.Aid
JOIN ValuesText vt ON vt.Vid = eav.Vid
WHERE attr.ColumnName IN (
    SELECT ColumnName FROM Attributes WHERE ColumnName = 'eventDate'
)
    AND eav.Vid IS NOT NULL
    AND vt.ValueText IS NOT NULL
    AND vt.ValueText != ''
    AND LENGTH(vt.ValueText) >= 4
    AND SUBSTR(vt.ValueText, 1, 4) GLOB '[0-9][0-9][0-9][0-9]'  -- Ensure it's a valid year
GROUP BY year
HAVING image_count > 0
ORDER BY year DESC;

-- Create indexes for fast lookups
CREATE INDEX idx_autocomplete_date_year ON autocomplete_date(year);
CREATE INDEX idx_autocomplete_date_count ON autocomplete_date(image_count DESC);

-- ============================================================================
-- Summary Statistics
-- ============================================================================

-- Create a summary view for monitoring
CREATE VIEW autocomplete_summary AS
SELECT 
    'taxon' as category,
    COUNT(*) as distinct_values,
    SUM(image_count) as total_image_references,
    MAX(image_count) as max_images_per_value,
    MIN(image_count) as min_images_per_value
FROM autocomplete_taxon
UNION ALL
SELECT 
    'collection' as category,
    COUNT(*) as distinct_values,
    SUM(image_count) as total_image_references,
    MAX(image_count) as max_images_per_value,
    MIN(image_count) as min_images_per_value
FROM autocomplete_collection
UNION ALL
SELECT 
    'location' as category,
    COUNT(*) as distinct_values,
    SUM(image_count) as total_image_references,
    MAX(image_count) as max_images_per_value,
    MIN(image_count) as min_images_per_value
FROM autocomplete_location
UNION ALL
SELECT 
    'date' as category,
    COUNT(*) as distinct_values,
    SUM(image_count) as total_image_references,
    MAX(image_count) as max_images_per_value,
    MIN(image_count) as min_images_per_value
FROM autocomplete_date;

-- Display summary
SELECT '=== Autocomplete Index Summary ===' as info;
SELECT * FROM autocomplete_summary;

-- Display sample data from each index
SELECT '=== Top 10 Taxa ===' as info;
SELECT taxon_name, image_count, source_field 
FROM autocomplete_taxon 
ORDER BY image_count DESC 
LIMIT 10;

SELECT '=== Top 10 Collections ===' as info;
SELECT collection_value, image_count, source_field 
FROM autocomplete_collection 
ORDER BY image_count DESC 
LIMIT 10;

SELECT '=== Top 10 Locations ===' as info;
SELECT location_value, image_count, source_field 
FROM autocomplete_location 
ORDER BY image_count DESC 
LIMIT 10;

SELECT '=== Recent Years ===' as info;
SELECT year, image_count 
FROM autocomplete_date 
ORDER BY year DESC 
LIMIT 10;

