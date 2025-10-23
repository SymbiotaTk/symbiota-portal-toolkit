SELECT 
    o.occid,
    o.catalogNumber,
    o.scientificName,
    o.locality,
    o.decimalLatitude,
    o.decimalLongitude,
    c.collectionName,
    c.institutionCode
FROM omoccurrences o
LEFT JOIN omcollections c ON o.collid = c.collid
WHERE o.occid = ?
