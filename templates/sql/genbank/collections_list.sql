SELECT DISTINCT
    c.collid,
    c.collectionName,
    c.institutionCode,
    c.collectionCode
FROM omcollections c
INNER JOIN omoccurrences o ON c.collid = o.collid
WHERE c.colltype = 'Preserved Specimens'{where_clause}
{order_by_clause|ORDER BY c.collectionName ASC}
LIMIT {limit}
