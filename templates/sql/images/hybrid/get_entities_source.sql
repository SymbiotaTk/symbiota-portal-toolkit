-- Get display fields from attached source.db (URLs and other non-indexed fields)
-- Used by: ImagesModelHybrid::getEavEntities() - source DB
-- Parameters: Dynamic placeholders for media IDs
-- Returns: mediaID, url, originalUrl, thumbnailUrl
-- Note: {PLACEHOLDERS} will be replaced with actual placeholders at runtime
-- Note: Queries attached source.db database
SELECT
    m.mediaID,
    m.url,
    m.originalUrl,
    m.thumbnailUrl
FROM source.media m
WHERE m.mediaID IN ({PLACEHOLDERS})

