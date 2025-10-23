-- Get record count for collection
SELECT COUNT(*) as record_count
FROM omoccurrences o
WHERE o.collid = {collid}
