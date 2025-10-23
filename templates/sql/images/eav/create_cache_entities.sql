-- Create Entities table in cache database
-- Stores unique entity IDs (e.g., mediaID values) plus non-indexed display fields
--
-- Design rationale:
-- - Eid = Auto-increment primary key (internal)
-- - EntityValue = Original entity identifier (e.g., mediaID) - searchable/unique
-- - Display fields = Non-indexed fields needed for display/linking (URLs, etc.)
--
-- This follows standard EAV pattern:
-- - Entities table = Entity metadata + non-indexed display data
-- - EAV table = Indexed/searchable attributes only
--
-- Display fields are dynamically added based on schema_config.ini:
--   columns[] = url:text:display
--   columns[] = format:text:display
--
DROP TABLE IF EXISTS Entities;

CREATE TABLE Entities (
    Eid INTEGER PRIMARY KEY AUTOINCREMENT,
    EntityValue TEXT NOT NULL UNIQUE  -- mediaID or other unique identifier
    -- Display fields will be added dynamically:
    -- url TEXT,
    -- originalUrl TEXT,
    -- thumbnailUrl TEXT,
    -- accessUri TEXT,
    -- format TEXT,
    -- owner TEXT,
    -- etc.
);

