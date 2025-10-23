-- Get user UID by username or email
-- Used by SymbAuth to lookup user ID from Symbiota session username
SELECT uid 
FROM users 
WHERE username = ? OR email = ?
