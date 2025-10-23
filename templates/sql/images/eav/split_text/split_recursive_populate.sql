-- Populate TempSplitWords using RECURSIVE CTE to split text by whitespace
-- Based on the pattern from /var/www/temp/symb/data/test.sql
INSERT INTO TempSplitWords (Eid, Word, WordOrder)
WITH RECURSIVE split(Eid, rest, word, word_order) AS (
  -- Seed: trim leading/trailing spaces; take the first word
  SELECT
    Eid,
    TRIM({COLUMN_NAME}) AS rest,
    SUBSTR(TRIM({COLUMN_NAME}), 1, INSTR(TRIM({COLUMN_NAME}) || ' ', ' ') - 1) AS word,
    1 AS word_order
  FROM Records
  WHERE {COLUMN_NAME} IS NOT NULL AND {COLUMN_NAME} != ''

  UNION ALL

  -- Step: drop the word we just emitted; ltrim to skip extra spaces
  SELECT
    Eid,
    LTRIM(SUBSTR(rest, INSTR(rest || ' ', ' ') + 1)) AS rest,
    SUBSTR(
      LTRIM(SUBSTR(rest, INSTR(rest || ' ', ' ') + 1)),
      1,
      INSTR(LTRIM(SUBSTR(rest, INSTR(rest || ' ', ' ') + 1)) || ' ', ' ') - 1
    ) AS word,
    word_order + 1
  FROM split
  WHERE rest <> ''
)
SELECT Eid, word, word_order
FROM split
WHERE word <> ''
ORDER BY Eid, word_order;

