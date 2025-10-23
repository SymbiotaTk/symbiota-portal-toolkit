SELECT
    o.occid,
    o.catalogNumber,
    o.collid,
    c.collectionName,
    o.scientificName,
    o.family,
    o.locality,
    o.stateProvince,
    o.country,
    o.decimalLatitude,
    o.decimalLongitude,
    o.eventDate,
    o.recordedBy,
    o.recordNumber
FROM omoccurrences o
LEFT JOIN omcollections c ON o.collid = c.collid
WHERE o.occid = ?
LIMIT 1
