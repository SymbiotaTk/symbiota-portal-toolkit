-- Get user UID by username
-- Used by UploadModel to find user ID from username
SELECT uid, username
FROM users 
WHERE username = ?
