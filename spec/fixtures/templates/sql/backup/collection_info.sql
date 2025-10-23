-- Get collection information for backup operations
-- This is a test fixture that returns mock data for testing
SELECT 
    c.collid,
    c.institutioncode,
    c.collectioncode,
    c.collectionname,
    c.managementtype,
    c.colltype,
    'Active' as status,
    NOW() as last_backup
FROM omcollections c
WHERE c.collid = {collid};
