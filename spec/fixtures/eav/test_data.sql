-- ============================================================================
-- EAV Test Data - Schema for TSV Fixtures
-- ============================================================================
-- This file creates the schema for loading the TSV fixture files.
-- The actual data is loaded from media.tsv and omoccurrences.tsv
-- ============================================================================

-- Collections (will be populated from occurrence data)
CREATE TABLE omcollections (
    collID INTEGER PRIMARY KEY,
    collectionName TEXT,
    collectionCode TEXT,
    institutionCode TEXT
);

-- Taxa (will be populated from occurrence data)
CREATE TABLE taxa (
    tid INTEGER PRIMARY KEY,
    sciName TEXT,
    family TEXT
);

-- Occurrences (schema matches TSV file)
CREATE TABLE omoccurrences (
    occid INTEGER PRIMARY KEY,
    collid INTEGER,
    catalogNumber TEXT,
    otherCatalogNumbers TEXT,
    family TEXT,
    genus TEXT,
    scientificName TEXT,
    sciname TEXT,
    tidInterpreted INTEGER,
    eventDate TEXT,
    continent TEXT,
    country TEXT,
    stateProvince TEXT,
    county TEXT,
    municipality TEXT,
    locality TEXT,
    decimalLatitude REAL,
    decimalLongitude REAL,
    dateLastModified TEXT
);

-- Media (schema matches TSV file)
CREATE TABLE media (
    mediaID INTEGER PRIMARY KEY,
    occid INTEGER,
    url TEXT,
    originalUrl TEXT,
    thumbnailUrl TEXT
);

