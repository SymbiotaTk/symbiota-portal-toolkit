<?php

namespace Symbiota\Helpers\Models;

use Exception;

/**
 * BackupManager class for Symbiota integration
 * Follows the pattern from ProfileManager.php for user/collection role management
 */
class BackupManager
{
    private ?\mysqli $conn;

    public function __construct(?\mysqli $connection)
    {
        $this->conn = $connection;
    }

    /**
     * Get collections with CollAdmin or SuperAdmin users
     * Based on ProfileManager::generateAccessPacket() pattern
     */
    public function getCollectionsWithAdmins(): array
    {
        if (!$this->conn) {
            throw new Exception('Database connection not available');
        }

        $collections = [];

        // First, get all collections
        $sql = 'SELECT collid, institutioncode, collectioncode, collectionname, colltype
                FROM omcollections
                ORDER BY collectionname';

        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $collections[$row['collid']] = [
                    'collid' => $row['collid'],
                    'institutioncode' => $row['institutioncode'],
                    'collectioncode' => $row['collectioncode'],
                    'collectionname' => $row['collectionname'],
                    'colltype' => $row['colltype'],
                    'admins' => []
                ];
            }
            $stmt->close();
        }

        // Then get admin users for each collection
        // Handle both tableName = 'omcollections' and tableName = NULL (legacy data)
        $sql = 'SELECT r.tablepk as collid, r.uid, u.username, u.firstname, u.lastname, u.email, r.role
                FROM userroles r
                INNER JOIN users u ON r.uid = u.uid
                WHERE (r.tableName = "omcollections" OR r.tableName IS NULL)
                AND (r.role = "CollAdmin" OR r.role = "SuperAdmin")
                AND r.tablepk IS NOT NULL
                ORDER BY r.tablepk, u.username';

        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $collid = $row['collid'];
                if (isset($collections[$collid])) {
                    $collections[$collid]['admins'][] = [
                        'uid' => $row['uid'],
                        'username' => $row['username'],
                        'firstname' => $row['firstname'],
                        'lastname' => $row['lastname'],
                        'email' => $row['email'],
                        'role' => $row['role']
                    ];
                }
            }
            $stmt->close();
        }

        // Filter to only return collections that have admin users
        return array_filter($collections, function($collection) {
            return !empty($collection['admins']);
        });
    }

    /**
     * Get CollAdmin users for a specific collection
     */
    public function getCollectionAdminUsers(int $collid): array
    {
        if (!$this->conn) {
            throw new Exception('Database connection not available');
        }

        $users = [];

        $sql = 'SELECT r.uid, u.username, u.firstname, u.lastname, u.email, r.role
                FROM userroles r
                INNER JOIN users u ON r.uid = u.uid
                WHERE r.tableName = "omcollections"
                AND r.tablepk = ?
                AND (r.role = "CollAdmin" OR r.role = "SuperAdmin")
                ORDER BY u.username';

        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->bind_param('i', $collid);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $users[] = [
                    'uid' => $row['uid'],
                    'username' => $row['username'],
                    'firstname' => $row['firstname'],
                    'lastname' => $row['lastname'],
                    'email' => $row['email'],
                    'role' => $row['role']
                ];
            }
            $stmt->close();
        }

        return $users;
    }

    /**
     * Get collection information by ID
     */
    public function getCollectionInfo(int $collid): ?array
    {
        if (!$this->conn) {
            throw new Exception('Database connection not available');
        }

        $sql = 'SELECT collid, institutioncode, collectioncode, collectionname, colltype,
                       managementtype, fulldescription, homepage, contact, email
                FROM omcollections
                WHERE collid = ?';

        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->bind_param('i', $collid);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return $row;
            }
            $stmt->close();
        }

        return null;
    }

    /**
     * Get user collections (collections where user has CollAdmin role)
     */
    public function getUserCollections(int $uid): array
    {
        if (!$this->conn) {
            throw new Exception('Database connection not available');
        }

        $collections = [];

        $sql = 'SELECT r.tablepk as collid, c.institutioncode, c.collectioncode,
                       c.collectionname, c.colltype, r.role
                FROM userroles r
                INNER JOIN omcollections c ON r.tablepk = c.collid
                WHERE (r.tableName = "omcollections" OR r.tableName IS NULL)
                AND r.uid = ?
                AND (r.role = "CollAdmin" OR r.role = "SuperAdmin")
                AND r.tablepk IS NOT NULL
                ORDER BY c.collectionname';

        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $collections[] = $row;
            }
            $stmt->close();
        }

        return $collections;
    }

    /**
     * Get all collections (SuperAdmin access)
     */
    public function getAllCollections(): array
    {
        if (!$this->conn) {
            throw new Exception('Database connection not available');
        }

        $collections = [];

        $sql = 'SELECT collid, institutioncode, collectioncode, collectionname, colltype,
                       managementtype, fulldescription, homepage, contact, email
                FROM omcollections
                ORDER BY collectionname';

        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $collections[] = $row;
            }
            $stmt->close();
        }

        return $collections;
    }
}
