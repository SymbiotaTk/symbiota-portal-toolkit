-- Create ValuesText table in cache database
-- Stores unique text values (tokens) with auto-incrementing IDs
DROP TABLE IF EXISTS ValuesText;

CREATE TABLE ValuesText (
    Vid INTEGER PRIMARY KEY AUTOINCREMENT,
    ValueText TEXT NOT NULL UNIQUE
);

