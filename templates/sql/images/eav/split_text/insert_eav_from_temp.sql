-- Bulk INSERT into EAV from temp table
INSERT INTO cache.EAV (Eid, Aid, VidArray)
SELECT Eid, Aid, VidArray 
FROM TempSplitTokens 
WHERE VidArray IS NOT NULL;

