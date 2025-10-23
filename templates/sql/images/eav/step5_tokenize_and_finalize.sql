-- Step 5: Tokenize EAV_STACK and insert into final EAV table
-- This converts text values in VidArray to Vid references in cache.ValuesText
--
-- Process:
-- 1. Extract unique text values from EAV_STACK
-- 2. Insert into cache.ValuesText (gets auto-increment Vid)
-- 3. Convert EAV_STACK rows to use Vid references
-- 4. Insert into cache.EAV

-- ============================================================================
-- Part A: Populate ValuesText with unique tokens from whole-text columns
-- ============================================================================
-- For :whole strategy, each VidArray is a single token
INSERT OR IGNORE INTO cache.ValuesText (ValueText)
SELECT DISTINCT VidArray
FROM EAV_STACK
WHERE VidArray IS NOT NULL
  AND ValueNumber IS NULL
  AND Aid IN (
    SELECT Aid 
    FROM cache.Attributes 
    WHERE DataType = 'text'
  );

-- ============================================================================
-- Part B: Insert into final EAV table
-- ============================================================================
-- For numeric values: copy directly
INSERT INTO cache.EAV (Eid, Aid, VidArray, ValueNumber)
SELECT 
    Eid,
    Aid,
    NULL AS VidArray,
    ValueNumber
FROM EAV_STACK
WHERE ValueNumber IS NOT NULL
ORDER BY Eid, Aid;

-- For text values: convert VidArray text to Vid reference
INSERT INTO cache.EAV (Eid, Aid, VidArray, ValueNumber)
SELECT 
    s.Eid,
    s.Aid,
    CAST(v.Vid AS TEXT) AS VidArray,
    NULL AS ValueNumber
FROM EAV_STACK s
INNER JOIN cache.ValuesText v ON v.ValueText = s.VidArray
WHERE s.VidArray IS NOT NULL
  AND s.ValueNumber IS NULL
ORDER BY s.Eid, s.Aid;

