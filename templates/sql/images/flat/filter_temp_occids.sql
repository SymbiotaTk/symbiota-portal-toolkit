-- ============================================================================
-- Filter Temporary Occid Table
-- ============================================================================
-- This template removes occids from a temporary table that don't match
-- the specified filter conditions.
--
-- Parameters:
-- - {TEMP_TABLE}: Name of the temporary table containing occids to filter
-- - {FILTER_CLAUSE}: WHERE clause with filter conditions
--
-- Usage:
-- DELETE FROM temp_matching_occids
-- WHERE occid NOT IN (
--     SELECT o.occid
--     FROM omoccurrences o
--     LEFT JOIN omcollections c ON o.collid = c.collid
--     LEFT JOIN taxa t ON o.tidinterpreted = t.tid
--     WHERE o.occid IN (SELECT occid FROM temp_matching_occids)
--     AND {FILTER_CLAUSE}
-- )
-- ============================================================================

DELETE FROM {TEMP_TABLE}
WHERE occid NOT IN (
    SELECT o.occid
    FROM omoccurrences o
    LEFT JOIN omcollections c ON o.collid = c.collid
    LEFT JOIN taxa t ON o.tidinterpreted = t.tid
    WHERE o.occid IN (SELECT occid FROM {TEMP_TABLE})
    AND {FILTER_CLAUSE}
);

