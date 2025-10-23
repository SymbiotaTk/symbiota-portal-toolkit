-- Create lookup table for multi-word → VidArray mapping
CREATE TEMP TABLE IF NOT EXISTS TempMultiWordLookup (
    ValueText TEXT PRIMARY KEY, 
    VidArray TEXT NOT NULL
);

