-- Get collections with colladmin role users
SELECT
    c.collid,
    c.collectionname,
    c.collectioncode,
    c.institutioncode,
    c.managementtype,
    COUNT(DISTINCT o.occid) as recordcount,
    COUNT(DISTINCT ur.uid) as admin_count
FROM omcollections c
LEFT JOIN omoccurrences o ON c.collid = o.collid
LEFT JOIN userroles ur ON c.collid = ur.collid AND ur.role = 'CollAdmin'
GROUP BY c.collid, c.collectionname, c.collectioncode, c.institutioncode, c.managementtype
HAVING admin_count > 0
ORDER BY c.collectionname
