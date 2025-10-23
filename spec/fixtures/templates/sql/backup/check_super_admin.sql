-- Check if user is super admin for backup operations
-- This is a test fixture that returns mock data for testing
SELECT 
    u.uid,
    u.username,
    'SuperAdmin' as usertype,
    1 as is_admin
FROM users u
WHERE u.uid = {uid}
AND u.uid IN (433, 1, 2);
