-- Create temp table to store split words with order
-- This will hold: Eid, Word, WordOrder for all tokenized values
DROP TABLE IF EXISTS TempSplitWords;

CREATE TEMP TABLE TempSplitWords (
    Eid INTEGER NOT NULL,
    Word TEXT NOT NULL,
    WordOrder INTEGER NOT NULL
);

