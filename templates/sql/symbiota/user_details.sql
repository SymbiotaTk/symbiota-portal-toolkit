-- Get user details by UID
-- Used by SymbAuth to get user information for authentication
SELECT uid, username, firstname, lastname, email 
FROM users 
WHERE uid = ?
