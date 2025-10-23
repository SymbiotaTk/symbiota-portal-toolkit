-- Get collection record count
SELECT COUNT(*) as record_count
FROM omoccurrences o
WHERE o.collid = {collid}
