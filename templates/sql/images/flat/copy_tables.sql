-- ============================================================================
-- Copy Tables from source.db to flat_index.db
-- ============================================================================
-- This template copies tables from the attached source database.
-- The source database should be attached as 'source' before running these queries.
--
-- Placeholders:
--   {limit} - Optional LIMIT clause for testing (e.g., "LIMIT 1000" or "")
-- ============================================================================

-- Copy media table (into main database, not source)
DROP TABLE IF EXISTS main.media;
CREATE TABLE main.media AS
SELECT * FROM source.media
WHERE occid IS NOT NULL
{limit};

-- Copy omoccurrences table
DROP TABLE IF EXISTS main.omoccurrences;
CREATE TABLE main.omoccurrences AS
SELECT DISTINCT o.*
FROM source.omoccurrences o
WHERE o.occid IN (SELECT DISTINCT occid FROM main.media)
{limit};

-- Copy omcollections table
DROP TABLE IF EXISTS main.omcollections;
CREATE TABLE main.omcollections AS
SELECT DISTINCT c.*
FROM source.omcollections c
WHERE c.collid IN (SELECT DISTINCT collid FROM main.omoccurrences)
{limit};

-- Copy taxa table
DROP TABLE IF EXISTS main.taxa;
CREATE TABLE main.taxa AS
SELECT DISTINCT t.*
FROM source.taxa t
WHERE t.tid IN (SELECT DISTINCT tidinterpreted FROM main.omoccurrences WHERE tidinterpreted IS NOT NULL)
{limit};

