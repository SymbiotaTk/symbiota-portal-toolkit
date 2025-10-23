-- Build Entities table from source.db
-- Populates mediaID and occid for all media records

SELECT m.mediaID, m.occid
FROM source.media m
WHERE m.occid IS NOT NULL
{limit_clause}

