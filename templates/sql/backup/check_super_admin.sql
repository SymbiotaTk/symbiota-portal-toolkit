SELECT COUNT(*) as count
FROM userroles r
WHERE (r.tableName = "omcollections" OR r.tableName IS NULL)
AND r.uid = {uid}
AND r.role = "SuperAdmin"
