SELECT
    c.collid,
    c.collectionName,
    c.institutionCode,
    c.collectionCode,
    c.collType
FROM omcollections c
GROUP BY c.collid, c.collectionName, c.institutionCode, c.collectionCode, c.collType
ORDER BY c.collectionName
LIMIT ?
