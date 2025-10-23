-- Build Collections table from source.db
-- Populates unique collections with metadata

SELECT DISTINCT 
    c.collID as collid, 
    c.collectionName, 
    c.collectionCode, 
    c.institutionCode
FROM source.omcollections c

