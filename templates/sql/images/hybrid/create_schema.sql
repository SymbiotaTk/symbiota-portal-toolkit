-- Hybrid Index Schema
-- Creates tables for hybrid index with occid-based filtering

-- Enable performance optimizations
PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;
PRAGMA cache_size = -64000;
PRAGMA temp_store = MEMORY;
PRAGMA mmap_size = 268435456;

-- Entities table with occid for linking to MySQL
CREATE TABLE IF NOT EXISTS Entities (
    Eid INTEGER PRIMARY KEY AUTOINCREMENT,
    mediaID INTEGER NOT NULL UNIQUE,
    occid INTEGER NOT NULL
);

-- Collections table for collection metadata
CREATE TABLE IF NOT EXISTS Collections (
    Cid INTEGER PRIMARY KEY AUTOINCREMENT,
    collid INTEGER NOT NULL UNIQUE,
    collectionName TEXT,
    collectionCode TEXT,
    institutionCode TEXT
);

-- Attributes table for field definitions
-- Standardized schema matching EAV Attributes table for plugin interchangeability
CREATE TABLE IF NOT EXISTS Attributes (
    Aid INTEGER PRIMARY KEY,
    TableName TEXT NOT NULL,
    ColumnName TEXT NOT NULL,
    DataType TEXT NOT NULL,
    TokenStrategy TEXT DEFAULT 'whole',
    UNIQUE(TableName, ColumnName)
) WITHOUT ROWID;

-- ValuesText table for low-cardinality fields only
-- Excludes high-cardinality fields: catalogNumber, otherCatalogNumbers, locality
CREATE TABLE IF NOT EXISTS ValuesText (
    Vid INTEGER PRIMARY KEY AUTOINCREMENT,
    ValueText TEXT NOT NULL UNIQUE COLLATE NOCASE
);

-- EAV table with Vid references
CREATE TABLE IF NOT EXISTS EAV (
    Eid INTEGER NOT NULL,
    Aid INTEGER NOT NULL,
    Vid INTEGER,
    ValueNumber REAL,
    FOREIGN KEY (Eid) REFERENCES Entities(Eid),
    FOREIGN KEY (Aid) REFERENCES Attributes(Aid),
    FOREIGN KEY (Vid) REFERENCES ValuesText(Vid)
);

-- Metadata table for cache information
CREATE TABLE IF NOT EXISTS Metadata (
    key TEXT PRIMARY KEY,
    value TEXT
);

