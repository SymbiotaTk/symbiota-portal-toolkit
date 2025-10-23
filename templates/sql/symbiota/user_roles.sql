-- Get user roles and collections by UID
-- Used by SymbAuth to get user permissions and accessible collections
SELECT r.role, r.tablepk as collid 
FROM userroles r 
WHERE r.uid = ?
