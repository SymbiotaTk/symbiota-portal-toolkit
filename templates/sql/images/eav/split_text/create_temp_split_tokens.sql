-- Create temp table for :split tokenization
-- Stores all values from a :split attribute with word count and VidArray
DROP TABLE IF EXISTS TempSplitTokens;

CREATE TEMP TABLE TempSplitTokens (
    Eid INTEGER NOT NULL,
    Aid INTEGER NOT NULL,
    ValueText TEXT NOT NULL,
    WordCount INTEGER NOT NULL,
    VidArray TEXT
);

