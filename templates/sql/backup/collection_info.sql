-- Get collection information
SELECT
    c.collID as collid,
    c.collectionName as collectionname,
    c.collectionCode as collectioncode,
    c.institutionCode as institutioncode,
    c.managementType as managementtype,
    c.Contact as contact,
    c.email,
    c.fullDescription as description,
    c.homepage,
    c.individualUrl as individualurl,
    c.guidTarget as guidtarget,
    c.collType as colltype,
    c.publicEdits as publicedits,
    c.icon,
    c.sortSeq as sortseq
FROM omcollections c
WHERE c.collID = {collid}
LIMIT 1
