-- ============================================================================
-- Symbiota Portal Toolkit - Autocomplete Cache Schema
--
-- Lightweight token-count design for fast autocomplete on high-value fields only.
-- Excludes high-cardinality fields (catalogNumber, locality) to minimize storage.
-- Only stores tokens and counts, NOT full inverted index (search uses MySQL direct).
--
-- @package   Symbiota
-- @author    Super Developer <superdev@one.com>
-- @author    Augment Agent (AI Assistant)
-- @copyright 2025
-- @license   NCSA
-- @version   2.0
-- @since     2025-10-21
--
-- Target: < 100MB for 6M records across 13 fields
-- ============================================================================

-- ============================================================================
-- PRAGMA Settings for Performance
-- ============================================================================
-- Enable WAL mode for better concurrency and crash recovery
PRAGMA journal_mode = WAL;

-- Increase cache size to 64MB for faster queries
PRAGMA cache_size = -64000;

-- Use memory for temp storage
PRAGMA temp_store = MEMORY;

-- Optimize for read performance (autocomplete is read-heavy)
PRAGMA synchronous = NORMAL;

-- Enable memory-mapped I/O for faster reads (256MB)
PRAGMA mmap_size = 268435456;

-- Optimize page size for read performance
PRAGMA page_size = 4096;

-- ============================================================================
-- Attributes Table
-- ============================================================================
-- Stores metadata about indexed attributes (high-value fields only)
DROP TABLE IF EXISTS Attributes;

CREATE TABLE Attributes (
    Aid INTEGER PRIMARY KEY,
    ColumnName TEXT NOT NULL UNIQUE,
    FieldType TEXT NOT NULL  -- 'collection', 'taxon', 'geography'
);

CREATE UNIQUE INDEX idx_attributes_column ON Attributes(ColumnName);

-- ============================================================================
-- AutocompleteIndex Table
-- ============================================================================
-- Stores tokens (distinct values) with media counts for autocomplete
-- This is a lightweight index: just token + field + count
-- No media IDs stored (search uses MySQL direct queries)
DROP TABLE IF EXISTS AutocompleteIndex;

CREATE TABLE AutocompleteIndex (
    Aid INTEGER NOT NULL,           -- Foreign key to Attributes.Aid
    Token TEXT NOT NULL,            -- Normalized token (lowercase, trimmed)
    MediaCount INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (Aid, Token),
    FOREIGN KEY (Aid) REFERENCES Attributes(Aid)
);

CREATE INDEX idx_autocomplete_aid ON AutocompleteIndex(Aid);
CREATE INDEX idx_autocomplete_token ON AutocompleteIndex(Token);
CREATE INDEX idx_autocomplete_count ON AutocompleteIndex(MediaCount DESC);

-- ============================================================================
-- Metadata Table
-- ============================================================================
-- Stores cache metadata for auto-detection and freshness checks
DROP TABLE IF EXISTS Metadata;

CREATE TABLE Metadata (
    Key TEXT PRIMARY KEY,
    Value TEXT NOT NULL
);

-- Insert default metadata
INSERT INTO Metadata (Key, Value) VALUES
    ('schema_version', '1.0'),
    ('created_at', datetime('now')),
    ('entity_count', '0'),
    ('attribute_count', '0'),
    ('distinct_value_count', '0'),
    ('recommended_mode', 'auto');

-- ============================================================================
-- Taxon Autocomplete Index
-- ============================================================================
-- Aggregates: sciname, scientificName, family, genus
-- Stores distinct taxon names with image counts for autocomplete
DROP TABLE IF EXISTS autocomplete_taxon;

CREATE TABLE autocomplete_taxon (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    taxon_name TEXT NOT NULL COLLATE NOCASE,
    image_count INTEGER NOT NULL DEFAULT 0,
    source_field TEXT NOT NULL,  -- Which field it came from (sciname, scientificName, family, genus)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_autocomplete_taxon_name ON autocomplete_taxon(taxon_name COLLATE NOCASE);
CREATE INDEX idx_autocomplete_taxon_count ON autocomplete_taxon(image_count DESC);
CREATE INDEX idx_autocomplete_taxon_source ON autocomplete_taxon(source_field);

-- ============================================================================
-- Collection Autocomplete Index
-- ============================================================================
-- Aggregates: collectionName, collectionCode, institutionCode
-- Stores distinct collection values with image counts for autocomplete
DROP TABLE IF EXISTS autocomplete_collection;

CREATE TABLE autocomplete_collection (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_value TEXT NOT NULL COLLATE NOCASE,
    image_count INTEGER NOT NULL DEFAULT 0,
    source_field TEXT NOT NULL,  -- collectionName, collectionCode, or institutionCode
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_autocomplete_collection_value ON autocomplete_collection(collection_value COLLATE NOCASE);
CREATE INDEX idx_autocomplete_collection_count ON autocomplete_collection(image_count DESC);
CREATE INDEX idx_autocomplete_collection_source ON autocomplete_collection(source_field);

