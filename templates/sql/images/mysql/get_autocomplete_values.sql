-- Get distinct values for autocomplete cache building
-- Used during autocomplete cache build to extract unique values for each field
--
-- Placeholders:
--   {table} - Table name (e.g., 'omoccurrences', 'taxa')
--   {column} - Column name (e.g., 'family', 'country')
--   {joins} - JOIN clauses if column is in related table (empty for root table)
--
-- Example interpolation for omoccurrences.family:
--   table = "omoccurrences"
--   column = "family"
--   joins = "LEFT JOIN omoccurrences o ON m.occid = o.occid"
--
-- Example interpolation for taxa.sciName:
--   table = "taxa"
--   column = "sciName"
--   joins = "LEFT JOIN omoccurrences o ON m.occid = o.occid\nLEFT JOIN taxa t ON o.tidInterpreted = t.tid"
--
-- Returns: List of distinct values (lowercased, trimmed, non-null, non-empty)

SELECT DISTINCT
    LOWER(TRIM({table_alias}.{column})) as value
FROM media m
{joins}
WHERE m.occid IS NOT NULL
    AND {table_alias}.{column} IS NOT NULL
    AND {table_alias}.{column} != ''
ORDER BY value

