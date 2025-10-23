-- Create Config table in cache database
-- Stores configuration metadata for the EAV index
DROP TABLE IF EXISTS Config;

CREATE TABLE Config (
    ConfigKey TEXT PRIMARY KEY,
    ConfigValue TEXT NOT NULL
) WITHOUT ROWID;

