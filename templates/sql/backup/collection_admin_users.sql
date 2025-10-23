-- Get colladmin users for a specific collection
SELECT
    u.uid,
    u.username,
    u.firstname,
    u.lastname,
    u.email,
    ur.role,
    ur.tablename
FROM users u
INNER JOIN userroles ur ON u.uid = ur.uid
WHERE (ur.tableName = 'omcollections' OR ur.tableName IS NULL)
  AND ur.tablepk = {collid}
  AND (ur.role = 'CollAdmin' OR ur.role = 'SuperAdmin')
ORDER BY u.lastname, u.firstname
