-- Freetext search across all indexed text fields
-- Used when user searches without field prefix (e.g., "amanita")
--
-- Placeholders:
--   {search} - Search pattern (already lowercased with wildcards)
--   {limit} - Result limit
--   {offset} - Result offset
--
-- Example interpolation:
--   search = "'amanita%'"
--   limit = 100
--   offset = 0
--
-- Note: This query searches across all text fields defined in index_config.ini
-- Performance: Slower than field-specific search, but still faster than large SQLite EAV

SELECT DISTINCT
    m.mediaID,
    m.url,
    m.thumbnailUrl
FROM media m
LEFT JOIN omoccurrences o ON m.occid = o.occid
LEFT JOIN omcollections c ON o.collid = c.collID
LEFT JOIN taxa t ON o.tidInterpreted = t.tid
WHERE m.occid IS NOT NULL
    AND (
        -- omoccurrences text fields
        LOWER(o.catalogNumber) LIKE {search}
        OR LOWER(o.otherCatalogNumbers) LIKE {search}
        OR LOWER(o.family) LIKE {search}
        OR LOWER(o.genus) LIKE {search}
        OR LOWER(o.scientificName) LIKE {search}
        OR LOWER(o.sciname) LIKE {search}
        OR LOWER(o.continent) LIKE {search}
        OR LOWER(o.country) LIKE {search}
        OR LOWER(o.stateProvince) LIKE {search}
        OR LOWER(o.county) LIKE {search}
        OR LOWER(o.municipality) LIKE {search}
        OR LOWER(o.locality) LIKE {search}
        -- omcollections text fields
        OR LOWER(c.collectionName) LIKE {search}
        OR LOWER(c.collectionCode) LIKE {search}
        OR LOWER(c.institutionCode) LIKE {search}
        -- taxa text fields
        OR LOWER(t.sciName) LIKE {search}
    )
ORDER BY m.mediaID
LIMIT {limit} OFFSET {offset}

