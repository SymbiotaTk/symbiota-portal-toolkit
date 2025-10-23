SELECT
    o.occid,
    o.catalogNumber,
    o.collid,
    c.collectionName,
    o.scientificName,
    o.locality,
    o.decimalLatitude,
    o.decimalLongitude,
    o.eventDate,
    o.recordedBy,
    o.family
FROM omoccurrences o
LEFT JOIN omcollections c ON o.collid = c.collid
WHERE o.catalogNumber LIKE ?
{collection_filter}
ORDER BY o.collid, o.occid
LIMIT {limit|100}
