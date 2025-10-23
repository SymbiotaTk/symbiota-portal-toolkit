SELECT DISTINCT c.collid, c.institutioncode, c.collectioncode, c.collectionname, c.colltype, r.role
FROM omcollections c
INNER JOIN userroles r ON (r.tablepk = c.collid AND (r.tableName = "omcollections" OR r.tableName IS NULL))
WHERE r.uid = {uid} AND r.role = "CollAdmin"
ORDER BY c.collectionname
