-- Get DISTINCT multi-word values that need tokenization
SELECT DISTINCT ValueText 
FROM TempSplitTokens 
WHERE WordCount > 1 AND VidArray IS NULL;

