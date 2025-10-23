-- Batch INSERT entities from source database using INSERT INTO SELECT
-- This is much faster than individual row INSERTs
--
-- Note: Eid is auto-increment PRIMARY KEY, so we don't specify it in INSERT
--
-- Parameters:
--   {INSERT_COLUMNS} - Comma-separated list of column names for INSERT (e.g., "EntityValue, url, originalUrl")
--   {SELECT_COLUMNS} - Comma-separated list of SELECT expressions (e.g., "mediaID AS EntityValue, url, originalUrl")
--   {ROOT_TABLE} - Root table name (e.g., "media")
--   {ROOT_ID_COLUMN} - Root ID column name (e.g., "mediaID")
--   {MIN_ID} - Minimum ID for this batch
--   {MAX_ID} - Maximum ID for this batch

INSERT INTO Entities ({INSERT_COLUMNS})
SELECT {SELECT_COLUMNS}
FROM source.{ROOT_TABLE}
WHERE {ROOT_ID_COLUMN} >= {MIN_ID}
  AND {ROOT_ID_COLUMN} <= {MAX_ID}
ORDER BY {ROOT_ID_COLUMN}

