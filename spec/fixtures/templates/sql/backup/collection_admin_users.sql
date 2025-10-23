-- Get collection admin users for backup operations
-- This is a test fixture that returns mock data for testing
SELECT 
    u.uid,
    u.username,
    u.firstname,
    u.lastname,
    u.email,
    'SuperAdmin' as usertype
FROM users u
WHERE u.uid IN (433, 1, 2)
ORDER BY u.uid;
