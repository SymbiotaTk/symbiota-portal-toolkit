-- ============================================================================
-- Occid Search Filter Query
-- ============================================================================
-- This template filters occids by additional WHERE conditions.
--
-- Used to apply filters like genus:Boletus to occid search results.
--
-- Placeholders:
--   {FILTER_CLAUSE} - WHERE condition (e.g., "o.genus LIKE '%Boletus%'")
--
-- This query removes occids from temp_matching_occids that don't match the filter.
-- ============================================================================

DELETE FROM temp_matching_occids
WHERE occid NOT IN (
    SELECT o.occid
    FROM omoccurrences o
    LEFT JOIN taxa t ON o.tidinterpreted = t.tid
    LEFT JOIN omcollections c ON o.collid = c.collid
    WHERE o.occid IN (SELECT occid FROM temp_matching_occids)
    AND {FILTER_CLAUSE}
);

