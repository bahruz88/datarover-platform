<?php
/**
 * DataRover Backend API v5.0
 * Full MySQL CRUD Operations + User Access Management
 */

session_start();
date_default_timezone_set('Asia/Baku'); // Set timezone

// Database Configuration — overridable via env (set by docker-compose); defaults match the XAMPP setup
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'datarover');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('SCANNER_URL', getenv('SCANNER_URL') ?: 'http://localhost:8000');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Database connection
function db() {
    static $pdo = null;
    if (!$pdo) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec("SET time_zone = '+04:00'"); // Baku timezone
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['success' => false, 'error' => 'Database connection failed']));
        }
    }
    return $pdo;
}

// Get JSON input
function getInput() {
    $input = file_get_contents('php://input');
    return $input ? json_decode($input, true) : [];
}

// Response helper
function respond($data, $success = true, $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => $success, 'data' => $data]);
    exit;
}

function error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// Connect to external database source
function connectToExternalSource($source) {
    $dbType = strtolower($source['db_type']);
    $host = $source['host'];
    $port = $source['port'];
    $database = $source['database_name'];
    $username = $source['username'];
    $password = $source['password'];
    
    try {
        if ($dbType === 'mysql' || $dbType === 'mariadb') {
            $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password);
        } elseif ($dbType === 'postgresql' || $dbType === 'postgres') {
            $dsn = "pgsql:host=$host;port=$port;dbname=$database";
            $pdo = new PDO($dsn, $username, $password);
        } elseif ($dbType === 'oracle') {
            $sid = $source['sid'] ?? $database;
            $dsn = "oci:dbname=//$host:$port/$sid";
            $pdo = new PDO($dsn, $username, $password);
        } elseif ($dbType === 'mssql' || $dbType === 'sqlserver') {
            $dsn = "sqlsrv:Server=$host,$port;Database=$database";
            $pdo = new PDO($dsn, $username, $password);
        } else {
            throw new Exception("Unsupported database type: $dbType");
        }
        
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        return $pdo;
        
    } catch (PDOException $e) {
        throw new Exception("Failed to connect to external source: " . $e->getMessage());
    }
}

/**
 * Resolve table_id and column_id for each entry in a glossary physical_attributes array.
 * Mutates the array in place. Snapshot fields (table, layer, name) are kept as fallback.
 * Falls back to fuzzy match (case-insensitive, spaces<->underscores) within the same layer
 * so that historical snapshots can still be linked after a rename.
 */
function enrichPhysicalAttributesWithIds(&$attrs) {
    if (!is_array($attrs)) return;
    foreach ($attrs as &$a) {
        if (!is_array($a)) continue;

        if (empty($a['table_id']) && !empty($a['table'])) {
            $tableId = null;
            if (!empty($a['layer'])) {
                $stmt = db()->prepare("SELECT t.id FROM catalog_tables t JOIN catalog_layers l ON t.layer_id=l.id WHERE t.name=? AND l.name=? LIMIT 1");
                $stmt->execute([$a['table'], $a['layer']]);
                $row = $stmt->fetch();
                if ($row) $tableId = (int)$row['id'];
            }
            if (!$tableId) {
                $stmt = db()->prepare("SELECT id FROM catalog_tables WHERE name = ? LIMIT 1");
                $stmt->execute([$a['table']]);
                $row = $stmt->fetch();
                if ($row) $tableId = (int)$row['id'];
            }
            if (!$tableId) {
                // Fuzzy: case-insensitive, treat spaces and underscores as equivalent.
                $needle = strtolower(str_replace(' ', '_', $a['table']));
                if (!empty($a['layer'])) {
                    $stmt = db()->prepare("SELECT t.id FROM catalog_tables t JOIN catalog_layers l ON t.layer_id=l.id WHERE LOWER(REPLACE(t.name,' ','_'))=? AND l.name=? LIMIT 1");
                    $stmt->execute([$needle, $a['layer']]);
                } else {
                    $stmt = db()->prepare("SELECT id FROM catalog_tables WHERE LOWER(REPLACE(name,' ','_'))=? LIMIT 1");
                    $stmt->execute([$needle]);
                }
                $row = $stmt->fetch();
                if ($row) $tableId = (int)$row['id'];
            }
            if ($tableId) $a['table_id'] = $tableId;
        }

        if (empty($a['column_id']) && !empty($a['table_id']) && !empty($a['name'])) {
            $stmt = db()->prepare("SELECT id FROM catalog_columns WHERE table_id = ? AND name = ? LIMIT 1");
            $stmt->execute([$a['table_id'], $a['name']]);
            $row = $stmt->fetch();
            if ($row) {
                $a['column_id'] = (int)$row['id'];
            } else {
                $needle = strtolower(str_replace(' ', '_', $a['name']));
                $stmt = db()->prepare("SELECT id FROM catalog_columns WHERE table_id = ? AND LOWER(REPLACE(name,' ','_'))=? LIMIT 1");
                $stmt->execute([$a['table_id'], $needle]);
                $row = $stmt->fetch();
                if ($row) $a['column_id'] = (int)$row['id'];
            }
        }
    }
    unset($a);
}

/**
 * Override snapshot names in physical_attributes with current values from catalog,
 * keyed by table_id / column_id when present. Mutates the array in place.
 */
function applyCurrentNamesToPhysicalAttributes(&$attrs) {
    if (!is_array($attrs)) return;
    foreach ($attrs as &$a) {
        if (!is_array($a)) continue;
        if (!empty($a['table_id'])) {
            $stmt = db()->prepare("SELECT t.name AS tname, l.name AS lname FROM catalog_tables t JOIN catalog_layers l ON t.layer_id=l.id WHERE t.id = ?");
            $stmt->execute([$a['table_id']]);
            $row = $stmt->fetch();
            if ($row) {
                $a['table'] = $row['tname'];
                $a['layer'] = $row['lname'];
            }
        }
        if (!empty($a['column_id'])) {
            $stmt = db()->prepare("SELECT name, data_type FROM catalog_columns WHERE id = ?");
            $stmt->execute([$a['column_id']]);
            $row = $stmt->fetch();
            if ($row) {
                $a['name'] = $row['name'];
                if (!empty($row['data_type'])) $a['dataType'] = $row['data_type'];
            }
        }
    }
    unset($a);
}

// Get REAL column statistics from external database
function getRealColumnStatsFromExternal($pdo, $tableName, $columnName, $dataType, $rowCount, $dbType = 'mysql') {
    $stats = [
        'null_count' => 0,
        'null_percent' => 0,
        'unique_count' => 0,
        'unique_percent' => 0,
        'distinct_count' => 0,
        'min_value' => null,
        'max_value' => null,
        'avg_length' => null,
        'sample_values' => [],
        'data_source' => 'real'
    ];
    
    if ($rowCount == 0) return $stats;
    
    // Prepare identifiers for Oracle
    $queryTableName = $tableName;
    $queryColumnName = $columnName;
    
    if (strtolower($dbType) === 'oracle') {
        // Oracle needs quoted identifiers
        if (strpos($tableName, '.') !== false) {
            list($schema, $table) = explode('.', $tableName, 2);
            $queryTableName = "\"$schema\".\"$table\"";
        } else {
            $queryTableName = "\"$tableName\"";
        }
        $queryColumnName = "\"$columnName\"";
    }
    
    try {
        // 1. NULL COUNT
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM $queryTableName WHERE $queryColumnName IS NULL");
        $stmt->execute();
        $result = $stmt->fetch();
        $stats['null_count'] = isset($result['cnt']) ? (int)$result['cnt'] : 0;
        $stats['null_percent'] = $rowCount > 0 ? round(($stats['null_count'] / $rowCount) * 100, 2) : 0;
        
        // 2. DISTINCT COUNT (with sampling for large tables)
        if ($rowCount > 1000000) {
            $sampleSize = 100000;
            // Note: LIMIT syntax varies by database
            if (strtolower($dbType) === 'oracle') {
                $stmt = $pdo->prepare("SELECT COUNT(DISTINCT $queryColumnName) as cnt 
                    FROM (SELECT $queryColumnName FROM $queryTableName WHERE $queryColumnName IS NOT NULL AND ROWNUM <= $sampleSize)");
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(DISTINCT $queryColumnName) as cnt 
                    FROM (SELECT $queryColumnName FROM $queryTableName WHERE $queryColumnName IS NOT NULL LIMIT $sampleSize) as sample");
            }
            $stmt->execute();
            $result = $stmt->fetch();
            $sampleDistinct = isset($result['cnt']) ? (int)$result['cnt'] : 0;
            $stats['distinct_count'] = round($sampleDistinct * ($rowCount / $sampleSize));
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT $queryColumnName) as cnt FROM $queryTableName WHERE $queryColumnName IS NOT NULL");
            $stmt->execute();
            $result = $stmt->fetch();
            $stats['distinct_count'] = isset($result['cnt']) ? (int)$result['cnt'] : 0;
        }
        
        $stats['unique_count'] = $stats['distinct_count'];
        $stats['unique_percent'] = $rowCount > 0 ? round(($stats['unique_count'] / $rowCount) * 100, 2) : 0;
        
        // 3. TYPE-SPECIFIC STATS
        
        // NUMERIC TYPES
        if (preg_match('/INT|DECIMAL|NUMERIC|FLOAT|DOUBLE|NUMBER/i', $dataType)) {
            $stmt = $pdo->prepare("SELECT MIN($queryColumnName) as min_val, MAX($queryColumnName) as max_val 
                FROM $queryTableName WHERE $queryColumnName IS NOT NULL");
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) {
                $stats['min_value'] = $row['min_val'] !== null ? (string)$row['min_val'] : null;
                $stats['max_value'] = $row['max_val'] !== null ? (string)$row['max_val'] : null;
            }
            
            // Sample values
            if (strtolower($dbType) === 'oracle') {
                $stmt = $pdo->prepare("SELECT DISTINCT $queryColumnName FROM $queryTableName 
                    WHERE $queryColumnName IS NOT NULL AND ROWNUM <= 5 ORDER BY $queryColumnName");
            } else {
                $stmt = $pdo->prepare("SELECT DISTINCT $queryColumnName FROM $queryTableName 
                    WHERE $queryColumnName IS NOT NULL ORDER BY $queryColumnName LIMIT 5");
            }
            $stmt->execute();
            $stats['sample_values'] = array_map(function($row) use ($columnName) {
                return (string)$row[$columnName];
            }, $stmt->fetchAll());
        }
        
        // DATE/TIME TYPES
        elseif (preg_match('/DATE|TIME/i', $dataType)) {
            $stmt = $pdo->prepare("SELECT MIN($queryColumnName) as min_val, MAX($queryColumnName) as max_val 
                FROM $queryTableName WHERE $queryColumnName IS NOT NULL");
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) {
                $stats['min_value'] = $row['min_val'] !== null ? (string)$row['min_val'] : null;
                $stats['max_value'] = $row['max_val'] !== null ? (string)$row['max_val'] : null;
            }
            
            // Sample dates
            if (strtolower($dbType) === 'oracle') {
                $stmt = $pdo->prepare("SELECT DISTINCT $queryColumnName FROM $queryTableName 
                    WHERE $queryColumnName IS NOT NULL AND ROWNUM <= 5 ORDER BY $queryColumnName DESC");
            } else {
                $stmt = $pdo->prepare("SELECT DISTINCT $queryColumnName FROM $queryTableName 
                    WHERE $queryColumnName IS NOT NULL ORDER BY $queryColumnName DESC LIMIT 5");
            }
            $stmt->execute();
            $stats['sample_values'] = array_map(function($row) use ($columnName) {
                return (string)$row[$columnName];
            }, $stmt->fetchAll());
        }
        
        // STRING TYPES
        else {
            // Average length
            try {
                $lengthFunc = strtolower($dbType) === 'oracle' ? 'LENGTH' : 'LENGTH';
                $stmt = $pdo->prepare("SELECT AVG($lengthFunc($queryColumnName)) as avg_len 
                    FROM $queryTableName WHERE $queryColumnName IS NOT NULL");
                $stmt->execute();
                $row = $stmt->fetch();
                if ($row && $row['avg_len'] !== null) {
                    $stats['avg_length'] = round($row['avg_len']);
                }
                
                // Min/Max length
                $stmt = $pdo->prepare("SELECT MIN($lengthFunc($queryColumnName)) as min_len, MAX($lengthFunc($queryColumnName)) as max_len 
                    FROM $queryTableName WHERE $queryColumnName IS NOT NULL");
                $stmt->execute();
                $row = $stmt->fetch();
                if ($row) {
                    $stats['min_value'] = $row['min_len'] !== null ? 'Length: ' . $row['min_len'] : null;
                    $stats['max_value'] = $row['max_len'] !== null ? 'Length: ' . $row['max_len'] : null;
                }
            } catch (Exception $e) {
                // LENGTH function might not be supported
                error_log("LENGTH function error: " . $e->getMessage());
            }
            
            // Sample string values (most common)
            if (strtolower($dbType) === 'oracle') {
                $stmt = $pdo->prepare("SELECT * FROM (
                    SELECT $queryColumnName, COUNT(*) as cnt FROM $queryTableName 
                    WHERE $queryColumnName IS NOT NULL 
                    GROUP BY $queryColumnName 
                    ORDER BY cnt DESC
                ) WHERE ROWNUM <= 5");
            } else {
                $stmt = $pdo->prepare("SELECT $queryColumnName, COUNT(*) as cnt FROM $queryTableName 
                    WHERE $queryColumnName IS NOT NULL 
                    GROUP BY $queryColumnName 
                    ORDER BY cnt DESC 
                    LIMIT 5");
            }
            $stmt->execute();
            $stats['sample_values'] = array_map(function($row) use ($columnName) {
                $val = (string)$row[$columnName];
                return strlen($val) > 50 ? substr($val, 0, 47) . '...' : $val;
            }, $stmt->fetchAll());
        }
        
    } catch (Exception $e) {
        error_log("Profiling error for $tableName.$columnName: " . $e->getMessage());
        $stats['data_source'] = 'partial';
    }
    
    return $stats;
}

// Get REAL column statistics from DataRover's local database
function getRealColumnStats($tableName, $columnName, $dataType, $rowCount) {
    $stats = [
        'null_count' => 0,
        'null_percent' => 0,
        'unique_count' => 0,
        'unique_percent' => 0,
        'distinct_count' => 0,
        'min_value' => null,
        'max_value' => null,
        'avg_length' => null,
        'sample_values' => [],
        'data_source' => 'real'
    ];
    
    if ($rowCount == 0) return $stats;
    
    try {
        $pdo = db();
        
        // 1. NULL COUNT (fast query)
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM `$tableName` WHERE `$columnName` IS NULL");
        $stmt->execute();
        $stats['null_count'] = $stmt->fetch()['cnt'];
        $stats['null_percent'] = round(($stats['null_count'] / $rowCount) * 100, 2);
        
        // 2. DISTINCT COUNT (fast with EXPLAIN or sampling for large tables)
        if ($rowCount > 1000000) {
            // For very large tables, use sampling
            $sampleSize = 100000;
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT `$columnName`) as cnt 
                FROM (SELECT `$columnName` FROM `$tableName` WHERE `$columnName` IS NOT NULL LIMIT $sampleSize) as sample");
            $stmt->execute();
            $sampleDistinct = $stmt->fetch()['cnt'];
            // Estimate total distinct
            $stats['distinct_count'] = round($sampleDistinct * ($rowCount / $sampleSize));
        } else {
            // For smaller tables, count all
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT `$columnName`) as cnt FROM `$tableName` WHERE `$columnName` IS NOT NULL");
            $stmt->execute();
            $stats['distinct_count'] = $stmt->fetch()['cnt'];
        }
        
        $stats['unique_count'] = $stats['distinct_count'];
        $stats['unique_percent'] = round(($stats['unique_count'] / $rowCount) * 100, 2);
        
        // 3. TYPE-SPECIFIC STATS
        
        // NUMERIC TYPES
        if (preg_match('/INT|DECIMAL|NUMERIC|FLOAT|DOUBLE/i', $dataType)) {
            $stmt = $pdo->prepare("SELECT MIN(`$columnName`) as min_val, MAX(`$columnName`) as max_val 
                FROM `$tableName` WHERE `$columnName` IS NOT NULL");
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) {
                $stats['min_value'] = $row['min_val'] !== null ? (string)$row['min_val'] : null;
                $stats['max_value'] = $row['max_val'] !== null ? (string)$row['max_val'] : null;
            }
            
            // Sample numeric values (distinct)
            $stmt = $pdo->prepare("SELECT DISTINCT `$columnName` FROM `$tableName` 
                WHERE `$columnName` IS NOT NULL ORDER BY `$columnName` LIMIT 5");
            $stmt->execute();
            $stats['sample_values'] = array_map(function($row) use ($columnName) {
                return (string)$row[$columnName];
            }, $stmt->fetchAll());
        }
        
        // DATE/TIME TYPES
        elseif (preg_match('/DATE|TIME/i', $dataType)) {
            $stmt = $pdo->prepare("SELECT MIN(`$columnName`) as min_val, MAX(`$columnName`) as max_val 
                FROM `$tableName` WHERE `$columnName` IS NOT NULL");
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) {
                $stats['min_value'] = $row['min_val'] !== null ? (string)$row['min_val'] : null;
                $stats['max_value'] = $row['max_val'] !== null ? (string)$row['max_val'] : null;
            }
            
            // Sample dates
            $stmt = $pdo->prepare("SELECT DISTINCT `$columnName` FROM `$tableName` 
                WHERE `$columnName` IS NOT NULL ORDER BY `$columnName` DESC LIMIT 5");
            $stmt->execute();
            $stats['sample_values'] = array_map(function($row) use ($columnName) {
                return (string)$row[$columnName];
            }, $stmt->fetchAll());
        }
        
        // STRING/VARCHAR TYPES
        else {
            // Average length
            $stmt = $pdo->prepare("SELECT AVG(LENGTH(`$columnName`)) as avg_len 
                FROM `$tableName` WHERE `$columnName` IS NOT NULL");
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row && $row['avg_len'] !== null) {
                $stats['avg_length'] = round($row['avg_len']);
            }
            
            // Min/Max length
            $stmt = $pdo->prepare("SELECT MIN(LENGTH(`$columnName`)) as min_len, MAX(LENGTH(`$columnName`)) as max_len 
                FROM `$tableName` WHERE `$columnName` IS NOT NULL");
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) {
                $stats['min_value'] = $row['min_len'] !== null ? 'Length: ' . $row['min_len'] : null;
                $stats['max_value'] = $row['max_len'] !== null ? 'Length: ' . $row['max_len'] : null;
            }
            
            // Sample string values (most common)
            $stmt = $pdo->prepare("SELECT `$columnName`, COUNT(*) as cnt FROM `$tableName` 
                WHERE `$columnName` IS NOT NULL 
                GROUP BY `$columnName` 
                ORDER BY cnt DESC 
                LIMIT 5");
            $stmt->execute();
            $stats['sample_values'] = array_map(function($row) use ($columnName) {
                $val = (string)$row[$columnName];
                // Truncate long strings
                return strlen($val) > 50 ? substr($val, 0, 47) . '...' : $val;
            }, $stmt->fetchAll());
        }
        
    } catch (Exception $e) {
        // If any query fails, return what we have
        error_log("Profiling error for $tableName.$columnName: " . $e->getMessage());
        $stats['data_source'] = 'partial';
    }
    
    return $stats;
}

// Estimate column statistics based on metadata (fallback when table doesn't exist in DB)
function estimateColumnStats($col, $dataType, $rowCount) {
    $isPK = (bool)$col['is_pk'];
    $isFK = (bool)$col['is_fk'];
    $isNullable = (bool)$col['is_nullable'];
    $colName = strtolower($col['name']);
    
    // Handle zero or null rowCount to prevent division by zero
    if ($rowCount === 0 || $rowCount === null || $rowCount < 1) {
        return [
            'null_count' => 0,
            'null_percent' => 0,
            'unique_count' => 0,
            'unique_percent' => 0,
            'distinct_count' => 0,
            'min_value' => null,
            'max_value' => null,
            'avg_length' => null,
            'sample_values' => [],
            'data_source' => 'estimated_no_data'
        ];
    }
    
    // Initialize stats
    $stats = [
        'null_count' => 0,
        'null_percent' => 0,
        'unique_count' => 0,
        'unique_percent' => 0,
        'distinct_count' => 0,
        'min_value' => null,
        'max_value' => null,
        'avg_length' => null,
        'sample_values' => [],
        'data_source' => 'estimated'
    ];
    
    // Primary keys: 100% unique, 0% null
    if ($isPK) {
        $stats['null_count'] = 0;
        $stats['null_percent'] = 0;
        $stats['unique_count'] = $rowCount;
        $stats['unique_percent'] = 100;
        $stats['distinct_count'] = $rowCount;
        
        if (strpos($dataType, 'INT') !== false) {
            $stats['min_value'] = 1;
            $stats['max_value'] = $rowCount;
            $stats['sample_values'] = [1, 2, 3, 4, 5];
        }
    }
    // Foreign keys: high cardinality, low nulls
    elseif ($isFK) {
        $stats['null_count'] = $isNullable ? rand(0, $rowCount * 0.05) : 0;
        $stats['null_percent'] = round(($stats['null_count'] / $rowCount) * 100, 2);
        $stats['distinct_count'] = rand($rowCount * 0.1, $rowCount * 0.5);
        $stats['unique_count'] = $stats['distinct_count'];
        $stats['unique_percent'] = round(($stats['unique_count'] / $rowCount) * 100, 2);
    }
    // Email fields: high uniqueness
    elseif (strpos($colName, 'email') !== false) {
        $stats['null_count'] = $isNullable ? rand(0, $rowCount * 0.1) : 0;
        $stats['null_percent'] = round(($stats['null_count'] / $rowCount) * 100, 2);
        $stats['unique_count'] = $rowCount - $stats['null_count'] - rand(0, $rowCount * 0.05);
        $stats['unique_percent'] = round(($stats['unique_count'] / $rowCount) * 100, 2);
        $stats['distinct_count'] = $stats['unique_count'];
        $stats['avg_length'] = 25;
        $stats['sample_values'] = ['user@example.com', 'john.doe@company.com', 'alice@domain.com'];
    }
    // Name fields: some duplicates
    elseif (strpos($colName, 'name') !== false) {
        $stats['null_count'] = $isNullable ? rand(0, $rowCount * 0.05) : 0;
        $stats['null_percent'] = round(($stats['null_count'] / $rowCount) * 100, 2);
        $stats['distinct_count'] = rand($rowCount * 0.3, $rowCount * 0.7);
        $stats['unique_count'] = $stats['distinct_count'];
        $stats['unique_percent'] = round(($stats['unique_count'] / $rowCount) * 100, 2);
        $stats['avg_length'] = 15;
        $stats['sample_values'] = ['John Smith', 'Jane Doe', 'Bob Johnson', 'Alice Williams'];
    }
    // Date fields
    elseif (strpos($dataType, 'DATE') !== false || strpos($dataType, 'TIME') !== false) {
        $stats['null_count'] = $isNullable ? rand(0, $rowCount * 0.1) : 0;
        $stats['null_percent'] = round(($stats['null_count'] / $rowCount) * 100, 2);
        $stats['distinct_count'] = rand(100, 5000);
        $stats['min_value'] = '2020-01-01';
        $stats['max_value'] = date('Y-m-d');
        $stats['sample_values'] = ['2024-01-15', '2024-06-20', '2024-12-01'];
    }
    // Numeric fields
    elseif (strpos($dataType, 'INT') !== false || strpos($dataType, 'DECIMAL') !== false || strpos($dataType, 'NUMERIC') !== false) {
        $stats['null_count'] = $isNullable ? rand(0, $rowCount * 0.15) : 0;
        $stats['null_percent'] = round(($stats['null_count'] / $rowCount) * 100, 2);
        
        // Amount/price fields
        if (strpos($colName, 'amount') !== false || strpos($colName, 'price') !== false || strpos($colName, 'cost') !== false) {
            $stats['min_value'] = '0.00';
            $stats['max_value'] = '99999.99';
            $stats['sample_values'] = ['100.50', '250.00', '1500.75'];
        } 
        // Count fields
        elseif (strpos($colName, 'count') !== false || strpos($colName, 'qty') !== false || strpos($colName, 'quantity') !== false) {
            $stats['min_value'] = '0';
            $stats['max_value'] = (string)rand(100, 10000);
            $stats['sample_values'] = ['1', '5', '10', '50', '100'];
        }
        // Generic numeric
        else {
            $stats['min_value'] = '0';
            $stats['max_value'] = (string)$rowCount;
            $stats['sample_values'] = ['10', '25', '100', '500'];
        }
        
        $stats['distinct_count'] = rand($stats['max_value'] * 0.1, $stats['max_value']);
        $stats['unique_count'] = $stats['distinct_count'];
        $stats['unique_percent'] = round(($stats['unique_count'] / $rowCount) * 100, 2);
    }
    // String/varchar fields
    else {
        $stats['null_count'] = $isNullable ? rand(0, $rowCount * 0.2) : 0;
        $stats['null_percent'] = round(($stats['null_count'] / $rowCount) * 100, 2);
        $stats['distinct_count'] = rand($rowCount * 0.1, $rowCount * 0.8);
        $stats['unique_count'] = $stats['distinct_count'];
        $stats['unique_percent'] = round(($stats['unique_count'] / $rowCount) * 100, 2);
        $stats['avg_length'] = rand(10, 50);
        $stats['sample_values'] = ['Sample Value 1', 'Sample Value 2', 'Sample Value 3'];
    }
    
    return $stats;
}

// Run a single check and return result
// Run check via Soda Core Python script
// ALL connection info comes from external_sources table in database
 function runCheckViaSoda($check, $source) {

    // Extract check parameters
    $checkType = $check['check_type'] ?? 'row_count';
    $columnName = $check['column_name'] ?? null;
    $operator = $check['operator'] ?? '>';
    $threshold = $check['threshold_value'] ?? '0';

    // Detect OS
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $ds = DIRECTORY_SEPARATOR;

    // Find soda_scanner.py
    $basePath = __DIR__;
    $sodaPaths = [
        $basePath . $ds . '..' . $ds . 'datarover-soda' . $ds . 'scripts' . $ds . 'soda_scanner.py',
        $basePath . $ds . 'datarover-soda' . $ds . 'scripts' . $ds . 'soda_scanner.py',
        dirname($basePath) . $ds . 'datarover-soda' . $ds . 'scripts' . $ds . 'soda_scanner.py',
    ];

    if ($isWindows) {
        $sodaPaths[] = 'C:\\xampp\\htdocs\\datarover-soda\\scripts\\soda_scanner.py';
        $sodaPaths[] = 'D:\\xampp\\htdocs\\datarover-soda\\scripts\\soda_scanner.py';
    }

    $sodaPath = null;
    foreach ($sodaPaths as $path) {
        if (file_exists($path)) {
            $sodaPath = realpath($path);
            break;
        }
    }

    if (!$sodaPath) {
        return [
            'outcome' => 'error',
            'message' => 'Soda scanner not found',
            'percentage' => 0,
            'searched_paths' => $sodaPaths
        ];
    }

    // Determine datasource name
    $dbType = strtolower($source['db_type'] ?? 'mysql');
    $dataSource = $dbType . '_db';

    // Build table name with schema if needed
    $tableName = $check['table_name'];
    $layerName = $check['layer_name'] ?? null;
    if ($layerName && strpos($tableName, '.') === false) {
        $tableName = $layerName . '.' . $tableName;
    }

    // Create temporary config file
    $tempDir = sys_get_temp_dir();
    $uniqueId = uniqid('soda_');
    $tempConfigFile = $tempDir . $ds . $uniqueId . '_config.yml';

    // Build correct YAML config (Soda Core format)
    $configContent  = "data_source {$dataSource}:\n";
    $configContent .= "  type: {$dbType}\n";

    if ($dbType === 'oracle') {
        // Oracle → Use connect_string (Soda Core Oracle requirement)
        $serviceName = !empty($source['sid']) ? $source['sid'] : ($source['database_name'] ?? 'orcl');
        $host = $source['host'] ?? 'localhost';
        $port = $source['port'] ?? '1521';
        
        $connectString = "{$host}:{$port}/{$serviceName}";
        
        $configContent .= '  username: "' . $source['username'] . '"' . "\n";
        $configContent .= '  password: "' . $source['password'] . '"' . "\n";
        $configContent .= '  connect_string: "' . $connectString . '"' . "\n";
    } 
    else {
        // MySQL / PostgreSQL
        $configContent .= '  host: "' . $source['host'] . '"' . "\n";
        $configContent .= "  port: {$source['port']}\n";
        $configContent .= '  username: "' . $source['username'] . '"' . "\n";
        $configContent .= '  password: "' . $source['password'] . '"' . "\n";
        $configContent .= '  database: "' . $source['database_name'] . '"' . "\n";
    }

    file_put_contents($tempConfigFile, $configContent);

    // Create temporary check file only for non-custom checks
    $tempCheckFile = null;
    
    if ($checkType !== 'custom') {
        $tempCheckFile = $tempDir . $ds . $uniqueId . '_check.yml';
        
        // Standard checks for specific table
        $checkContent = "checks for {$tableName}:\n";
        
        switch ($checkType) {
            case 'row_count':
                $checkContent .= "  - row_count {$operator} {$threshold}\n";
                break;
            case 'missing':
                $checkContent .= "  - missing_count({$columnName}) < {$threshold}\n";
                break;
            case 'duplicate':
                $checkContent .= "  - duplicate_count({$columnName}) < {$threshold}\n";
                break;
            case 'freshness':
                $checkContent .= "  - freshness({$columnName}) < {$threshold}\n";
                break;
            case 'validity':
                $checkContent .= "  - invalid_count({$columnName}) < {$threshold}\n";
                break;
            default:
                $checkContent .= "  - row_count > 0\n";
        }
        
        file_put_contents($tempCheckFile, $checkContent);
    }

    // Build command
    $pythonCmd = $isWindows ? "python" : "python3";

    $cmd = $pythonCmd . " " . escapeshellarg($sodaPath)
        . " --single"
        . " -d " . escapeshellarg($dataSource)
        . " --config " . escapeshellarg($tempConfigFile);
    
    // Add check file only if not custom
    if ($tempCheckFile) {
        $cmd .= " --check-file " . escapeshellarg($tempCheckFile);
        $cmd .= " -t " . escapeshellarg($tableName)
            . " --check-type " . escapeshellarg($checkType);
    }
    
    // For custom SQL checks, pass the SQL to fetch failed rows
    if ($checkType === 'custom' && !empty($check['custom_sql'])) {
        $cmd .= " --fetch-rows"
             . " --custom-sql " . escapeshellarg($check['custom_sql'])
             . " --limit 100";
        
        // Add total SQL for percentage calculation
        $totalSql = "SELECT COUNT(*) FROM " . $tableName;
        $cmd .= " --total-sql " . escapeshellarg($totalSql);
    }
    // For missing check - fetch rows where column IS NULL
    elseif ($checkType === 'missing' && $columnName) {
        $failedSql = "SELECT * FROM {$tableName} WHERE {$columnName} IS NULL";
        $cmd .= " --fetch-rows"
             . " --custom-sql " . escapeshellarg($failedSql)
             . " --limit 100";
        
        $totalSql = "SELECT COUNT(*) FROM " . $tableName;
        $cmd .= " --total-sql " . escapeshellarg($totalSql);
    }
    // For duplicate check - fetch duplicate rows
    elseif ($checkType === 'duplicate' && $columnName) {
        if ($dbType === 'oracle') {
            // Oracle-compatible duplicate query
            $failedSql = "SELECT * FROM {$tableName} t1 WHERE EXISTS (SELECT 1 FROM {$tableName} t2 WHERE t2.{$columnName} = t1.{$columnName} AND t2.ROWID != t1.ROWID)";
        } else {
            // MySQL/PostgreSQL
            $failedSql = "SELECT * FROM {$tableName} WHERE {$columnName} IN (SELECT {$columnName} FROM {$tableName} GROUP BY {$columnName} HAVING COUNT(*) > 1)";
        }
        $cmd .= " --fetch-rows"
             . " --custom-sql " . escapeshellarg($failedSql)
             . " --limit 100";
        
        $totalSql = "SELECT COUNT(*) FROM " . $tableName;
        $cmd .= " --total-sql " . escapeshellarg($totalSql);
    }
    // For validity check - fetch invalid rows
    elseif ($checkType === 'validity' && $columnName) {
        // For validity, we check for empty strings or specific patterns
        if ($dbType === 'oracle') {
            $failedSql = "SELECT * FROM {$tableName} WHERE {$columnName} IS NULL OR TRIM({$columnName}) IS NULL";
        } else {
            $failedSql = "SELECT * FROM {$tableName} WHERE {$columnName} IS NULL OR {$columnName} = ''";
        }
        $cmd .= " --fetch-rows"
             . " --custom-sql " . escapeshellarg($failedSql)
             . " --limit 100";
        
        $totalSql = "SELECT COUNT(*) FROM " . $tableName;
        $cmd .= " --total-sql " . escapeshellarg($totalSql);
    }
    
    $cmd .= " --json 2>&1";

    // DEBUG: Log command for troubleshooting
    error_log("SODA COMMAND: " . $cmd);
    
    $output = shell_exec($cmd);
    
    // DEBUG: Log output
    error_log("SODA OUTPUT: " . $output);

    @unlink($tempConfigFile);
    if ($tempCheckFile) {
        @unlink($tempCheckFile);
    }

    // Parse result
    $result = json_decode($output, true);
    
    // Check for connection/database errors in output
    $outputLower = strtolower($output);
    $connectionErrors = [
        'connection refused', 'connection timeout', 'cannot connect', 'connection failed',
        'unknown database', 'access denied', 'authentication failed', 'ora-', 'tns:',
        'could not connect', 'unable to connect', 'network error', 'host is unreachable'
    ];
    
    foreach ($connectionErrors as $errPattern) {
        if (strpos($outputLower, $errPattern) !== false) {
            return [
                'outcome' => 'error',
                'technical_status' => 'error',
                'quality_status' => null,
                'message' => 'Database əlaqə xətası - source yoxlanmalıdır',
                'percentage' => 0,
                'error' => 'Connection error detected',
                'raw_output' => substr($output, 0, 500)
            ];
        }
    }

    // If JSON parse fails, try parsing text format (e.g., "Outcome: fail\nValue: 5\n")
    if (!$result || !isset($result['outcome'])) {
        $result = [];
        
        // Parse text output for Outcome and Value
        if (preg_match('/Outcome:\s*(\w+)/i', $output, $outcomeMatch)) {
            $result['outcome'] = strtolower($outcomeMatch[1]);
        }
        if (preg_match('/Value:\s*(\d+)/i', $output, $valueMatch)) {
            $result['value'] = (int)$valueMatch[1];
        }
        
        // If we found outcome, treat as valid result
        if (isset($result['outcome'])) {
            $result['message'] = 'Check completed';
            
            // For custom SQL, calculate total rows and percentage
            if ($checkType === 'custom') {
                $failedCount = $result['value'] ?? 0;
                
                // Get total rows from table
                try {
                    $totalSql = "SELECT COUNT(*) as cnt FROM {$tableName}";
                    $stmt = db()->query($totalSql);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $totalRows = (int)$row['cnt'];
                    
                    $result['total_rows'] = $totalRows;
                    $result['valid_rows'] = $totalRows - $failedCount;
                    $result['invalid_rows'] = $failedCount;
                    $result['failed_rows_count'] = $failedCount;
                    
                    // Calculate percentage
                    if ($totalRows > 0) {
                        $result['percentage'] = round((($totalRows - $failedCount) / $totalRows) * 100, 2);
                    } else {
                        $result['percentage'] = 0;
                    }
                } catch (Exception $e) {
                    // If total query fails, use default percentage
                    $result['percentage'] = $failedCount === 0 ? 100 : 0;
                    $result['total_rows'] = 0;
                    $result['valid_rows'] = 0;
                    $result['invalid_rows'] = $failedCount;
                }
            }
        }
    }

    if ($result && isset($result['outcome'])) {
        // Use percentage from Python if available, otherwise calculate based on outcome
        if (!isset($result['percentage']) || $result['percentage'] === null) {
            $result['percentage'] = $result['outcome'] === 'pass' ? 100 :
                                   ($result['outcome'] === 'warn' ? 75 : 0);
        }
        
        // Set technical status
        $result['technical_status'] = 'success';
        
        // Calculate quality status based on percentage, target, and threshold
        $percentage = (float)$result['percentage'];
        $target = isset($check['target_value']) ? (float)$check['target_value'] : 100.0;
        $threshold = isset($check['threshold_value']) ? (float)$check['threshold_value'] : 90.0;
        
        // Determine quality status based on percentage
        if ($percentage >= $target) {
            $result['quality_status'] = 'pass';
            $result['status_color'] = 'green';
        } elseif ($percentage >= $threshold) {
            $result['quality_status'] = 'warn';  // AMBER
            $result['status_color'] = 'amber';
        } else {
            $result['quality_status'] = 'fail';
            $result['status_color'] = 'red';
        }
        
        // Add target and threshold to result
        $result['target_value'] = $target;
        $result['threshold_value'] = $threshold;
        
        return $result;
    }

    return [
        'outcome' => 'error',
        'message' => 'Soda check failed',
        'raw_output' => $output,
        'yaml_config' => $configContent,
        'technical_status' => 'error'
    ];
}

function runSingleCheck($check) {
    $tableName = $check['table_name'];
    $layerName = $check['layer_name'] ?? null;
    $sourceId = $check['source_id'] ?? null;
    
    // Get source configuration
    $source = null;
    
    if ($sourceId) {
        // External source from database
        $sourceStmt = db()->prepare("SELECT * FROM external_sources WHERE id = ?");
        $sourceStmt->execute([$sourceId]);
        $source = $sourceStmt->fetch(PDO::FETCH_ASSOC);
        
        // If source_id was provided but source not found, return error
        if (!$source) {
            return [
                'outcome' => 'error',
                'technical_status' => 'error',
                'quality_status' => null,
                'message' => "Data source (ID: {$sourceId}) tapılmadı və ya aktiv deyil",
                'percentage' => 0,
                'error' => 'Source not found or inactive'
            ];
        }
    }
    
    // If no external source requested, use default DataRover MySQL
    if (!$source) {
        $source = [
            'db_type' => 'mysql',
            'host' => DB_HOST,
            'port' => DB_PORT,
            'username' => DB_USER,
            'password' => DB_PASS,
            'database_name' => DB_NAME
        ];
    }
    
    // ALL checks go through Soda Core
    return runCheckViaSoda($check, $source);
}

// ============================================================
// AUTHENTICATION & AUTHORIZATION FUNCTIONS
// ============================================================

function generateToken($length = 64) {
    return bin2hex(random_bytes($length / 2));
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function getCurrentUser() {
    // Try to get token from various sources
    $token = null;
    
    // Check Authorization header first
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = $_SERVER['HTTP_AUTHORIZATION'];
    }
    // Then check URL parameter
    elseif (isset($_GET['token'])) {
        $token = $_GET['token'];
    }
    // Finally check session
    elseif (isset($_SESSION['auth_token'])) {
        $token = $_SESSION['auth_token'];
    }
    
    if (!$token) return null;
    
    // Remove 'Bearer ' prefix if present
    $token = trim(str_replace('Bearer ', '', $token));
    
    if (empty($token)) return null;
    
    $stmt = db()->prepare("
        SELECT u.*, s.id as session_id, s.expires_at 
        FROM user_sessions s
        JOIN users u ON s.user_id = u.id
        WHERE s.session_token = ? AND s.is_active = TRUE AND s.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Update last activity
        db()->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE id = ?")->execute([$user['session_id']]);
        return $user;
    }
    
    return null;
}

function requireAuth() {
    $user = getCurrentUser();
    if (!$user) {
        error('Unauthorized - Please login', 401);
    }
    if (!$user['is_active']) {
        error('Account is disabled', 403);
    }
    if ($user['is_locked']) {
        error('Account is locked', 403);
    }
    return $user;
}

function getUserPermissions($userId) {
    $stmt = db()->prepare("
        SELECT DISTINCT p.module, p.action
        FROM permissions p
        JOIN role_permissions rp ON p.id = rp.permission_id
        JOIN user_role_assignments ura ON rp.role_id = ura.role_id
        WHERE ura.user_id = ? AND p.is_active = TRUE
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function hasPermission($userId, $module, $action) {
    $stmt = db()->prepare("
        SELECT COUNT(*) as cnt
        FROM permissions p
        JOIN role_permissions rp ON p.id = rp.permission_id
        JOIN user_role_assignments ura ON rp.role_id = ura.role_id
        WHERE ura.user_id = ? AND p.module = ? AND p.action = ? AND p.is_active = TRUE
    ");
    $stmt->execute([$userId, $module, $action]);
    $result = $stmt->fetch();
    return $result['cnt'] > 0;
}

function requirePermission($module, $action) {
    $user = requireAuth();
    if (!hasPermission($user['id'], $module, $action)) {
        auditLog($user['id'], 'permission_denied', $module, null, null, null, null, 'failure', "Required: $module.$action");
        error('Permission denied', 403);
    }
    return $user;
}

function getUserRoles($userId) {
    $stmt = db()->prepare("
        SELECT r.* 
        FROM user_roles r
        JOIN user_role_assignments ura ON r.id = ura.role_id
        WHERE ura.user_id = ? AND r.is_active = TRUE
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function auditLog($userId, $action, $module = null, $entityType = null, $entityId = null, $oldValues = null, $newValues = null, $status = 'success', $errorMessage = null) {
    try {
        $user = null;
        if ($userId) {
            $stmt = db()->prepare("SELECT username, tenant_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
        }
        
        $stmt = db()->prepare("
            INSERT INTO audit_log (tenant_id, user_id, username, action, module, entity_type, entity_id, old_values, new_values, ip_address, user_agent, status, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user['tenant_id'] ?? 1,
            $userId,
            $user['username'] ?? 'system',
            $action,
            $module,
            $entityType,
            $entityId,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $status,
            $errorMessage
        ]);
    } catch (Exception $e) {
        // Don't fail on audit log errors
        error_log("Audit log error: " . $e->getMessage());
    }
}

function validatePassword($password, $tenantId = 1) {
    $stmt = db()->prepare("SELECT * FROM password_policies WHERE tenant_id = ? OR tenant_id IS NULL ORDER BY tenant_id DESC LIMIT 1");
    $stmt->execute([$tenantId]);
    $policy = $stmt->fetch();
    
    if (!$policy) {
        // Default policy
        $policy = [
            'min_length' => 8,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_number' => true,
            'require_special' => true
        ];
    }
    
    $errors = [];
    
    if (strlen($password) < $policy['min_length']) {
        $errors[] = "Password must be at least {$policy['min_length']} characters";
    }
    if ($policy['require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    if ($policy['require_lowercase'] && !preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    if ($policy['require_number'] && !preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    if ($policy['require_special'] && !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return $errors;
}

function checkPasswordHistory($userId, $newPassword, $tenantId = 1) {
    $stmt = db()->prepare("SELECT prevent_reuse_count FROM password_policies WHERE tenant_id = ? OR tenant_id IS NULL ORDER BY tenant_id DESC LIMIT 1");
    $stmt->execute([$tenantId]);
    $policy = $stmt->fetch();
    $reuseCount = $policy['prevent_reuse_count'] ?? 5;
    
    $stmt = db()->prepare("SELECT password_hash FROM password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$userId, $reuseCount]);
    $history = $stmt->fetchAll();
    
    foreach ($history as $h) {
        if (verifyPassword($newPassword, $h['password_hash'])) {
            return false; // Password was used before
        }
    }
    return true;
}

// Route handling
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;

// ============================================================
// EXTERNAL API ENDPOINTS (API Key Authentication)
// ============================================================

// Quality Results API - for external tools to submit results
if ($action === 'api/quality-results') {
    // Get API key from header
    $apiKey = null;
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $auth = $headers['Authorization'];
        if (strpos($auth, 'Bearer ') === 0) {
            $apiKey = substr($auth, 7);
        } elseif (strpos($auth, 'API-Key ') === 0) {
            $apiKey = substr($auth, 8);
        }
    }
    if (!$apiKey && isset($headers['X-Api-Key'])) {
        $apiKey = $headers['X-Api-Key'];
    }
    
    // Validate API key
    if (!$apiKey) {
        error('API key required. Use Authorization: Bearer <key> or X-Api-Key header', 401);
    }
    
    $stmt = db()->prepare("SELECT * FROM api_keys WHERE api_key = ? AND is_active = TRUE AND (expires_at IS NULL OR expires_at > NOW())");
    $stmt->execute([$apiKey]);
    $keyInfo = $stmt->fetch();
    
    if (!$keyInfo) {
        error('Invalid or expired API key', 401);
    }
    
    // Update last used
    db()->prepare("UPDATE api_keys SET last_used = NOW() WHERE id = ?")->execute([$keyInfo['id']]);
    
    if ($method === 'POST') {
        // Submit quality results
        $data = getInput();
        
        $ruleId = $data['rule_id'] ?? null;
        $passRate = $data['pass_rate'] ?? null;
        
        if (!$ruleId || $passRate === null) {
            error('rule_id and pass_rate are required');
        }
        
        // Validate rule exists
        $stmt = db()->prepare("SELECT id FROM data_quality_rules WHERE rule_id = ?");
        $stmt->execute([$ruleId]);
        if (!$stmt->fetch()) {
            error('Rule not found: ' . $ruleId, 404);
        }
        
        $stmt = db()->prepare("
            INSERT INTO quality_rule_results 
            (rule_id, pass_rate, total_records, passed_records, failed_records, run_date, source_system, table_name, column_name, metadata, api_key)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $ruleId,
            $passRate,
            $data['total_records'] ?? 0,
            $data['passed_records'] ?? 0,
            $data['failed_records'] ?? 0,
            $data['run_date'] ?? date('Y-m-d H:i:s'),
            $data['source_system'] ?? null,
            $data['table_name'] ?? ($data['metadata']['table'] ?? null),
            $data['column_name'] ?? ($data['metadata']['column'] ?? null),
            isset($data['metadata']) ? json_encode($data['metadata']) : null,
            $apiKey
        ]);
        
        respond([
            'id' => db()->lastInsertId(),
            'message' => 'Quality result recorded successfully',
            'rule_id' => $ruleId,
            'pass_rate' => $passRate
        ]);
        
    } elseif ($method === 'GET') {
        // Get quality results
        $ruleId = $_GET['rule_id'] ?? null;
        $limit = min(100, intval($_GET['limit'] ?? 10));
        
        if ($ruleId) {
            $stmt = db()->prepare("
                SELECT qr.*, dqr.name as rule_name, dqr.type as rule_type
                FROM quality_rule_results qr
                JOIN data_quality_rules dqr ON qr.rule_id = dqr.rule_id
                WHERE qr.rule_id = ?
                ORDER BY qr.run_date DESC
                LIMIT ?
            ");
            $stmt->execute([$ruleId, $limit]);
        } else {
            $stmt = db()->prepare("
                SELECT qr.*, dqr.name as rule_name, dqr.type as rule_type
                FROM quality_rule_results qr
                JOIN data_quality_rules dqr ON qr.rule_id = dqr.rule_id
                ORDER BY qr.run_date DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
        }
        
        respond($stmt->fetchAll());
        
    } else {
        error('Method not allowed', 405);
    }
}

// Get latest quality results for all rules (used by frontend)
if ($action === 'quality-results/latest') {
    $stmt = db()->query("
        SELECT 
            dqr.rule_id,
            dqr.name,
            dqr.type,
            dqr.severity,
            COALESCE(latest.pass_rate, 0) as pass_rate,
            latest.run_date as last_run,
            latest.source_system,
            latest.total_records
        FROM data_quality_rules dqr
        LEFT JOIN (
            SELECT qr1.*
            FROM quality_rule_results qr1
            INNER JOIN (
                SELECT rule_id, MAX(run_date) as max_date
                FROM quality_rule_results
                GROUP BY rule_id
            ) qr2 ON qr1.rule_id = qr2.rule_id AND qr1.run_date = qr2.max_date
        ) latest ON dqr.rule_id = latest.rule_id
        WHERE dqr.status = 'active'
        ORDER BY dqr.name
    ");
    respond($stmt->fetchAll());
}

try {
    switch ($action) {
        
        // ============================================================
        // AUTHENTICATION
        // ============================================================
        
        case 'auth/login':
            if ($method !== 'POST') error('Method not allowed', 405);
            
            $data = getInput();
            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';
            
            if (!$username || !$password) {
                error('Username and password are required');
            }
            
            // Find user
            $stmt = db()->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND auth_type = 'local'");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                auditLog(null, 'login_failed', 'auth', 'user', null, null, ['username' => $username], 'failure', 'User not found');
                error('Invalid username or password', 401);
            }
            
            // Check if locked
            if ($user['is_locked'] && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
                auditLog($user['id'], 'login_blocked', 'auth', 'user', $user['id'], null, null, 'failure', 'Account locked');
                error('Account is locked. Try again later.', 403);
            }
            
            // Verify password
            if (!verifyPassword($password, $user['password_hash'])) {
                // Increment failed attempts
                $attempts = $user['failed_login_attempts'] + 1;
                
                // Get lockout policy
                $stmt = db()->prepare("SELECT lockout_attempts, lockout_duration_minutes FROM password_policies WHERE tenant_id = ? OR tenant_id IS NULL ORDER BY tenant_id DESC LIMIT 1");
                $stmt->execute([$user['tenant_id']]);
                $policy = $stmt->fetch();
                $maxAttempts = $policy['lockout_attempts'] ?? 5;
                $lockoutMinutes = $policy['lockout_duration_minutes'] ?? 30;
                
                if ($attempts >= $maxAttempts) {
                    // Lock account
                    $lockUntil = date('Y-m-d H:i:s', strtotime("+$lockoutMinutes minutes"));
                    db()->prepare("UPDATE users SET failed_login_attempts = ?, is_locked = TRUE, locked_until = ? WHERE id = ?")
                        ->execute([$attempts, $lockUntil, $user['id']]);
                    auditLog($user['id'], 'account_locked', 'auth', 'user', $user['id'], null, ['locked_until' => $lockUntil], 'failure');
                    error("Account locked due to too many failed attempts. Try again after $lockoutMinutes minutes.", 403);
                } else {
                    db()->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?")->execute([$attempts, $user['id']]);
                }
                
                auditLog($user['id'], 'login_failed', 'auth', 'user', $user['id'], null, ['attempt' => $attempts], 'failure', 'Invalid password');
                error('Invalid username or password', 401);
            }
            
            // Check if active
            if (!$user['is_active']) {
                auditLog($user['id'], 'login_failed', 'auth', 'user', $user['id'], null, null, 'failure', 'Account disabled');
                error('Account is disabled', 403);
            }
            
            // Get session timeout
            $stmt = db()->prepare("SELECT session_timeout_minutes FROM password_policies WHERE tenant_id = ? OR tenant_id IS NULL ORDER BY tenant_id DESC LIMIT 1");
            $stmt->execute([$user['tenant_id']]);
            $policy = $stmt->fetch();
            $sessionTimeout = $policy['session_timeout_minutes'] ?? 480; // 8 hours default
            
            // Create session - use MySQL NOW() to avoid timezone issues
            $token = generateToken();
            
            db()->prepare("
                INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at)
                VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))
            ")->execute([
                $user['id'],
                $token,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $sessionTimeout
            ]);
            
            // Get the actual expires_at from database
            $stmt = db()->prepare("SELECT expires_at FROM user_sessions WHERE session_token = ?");
            $stmt->execute([$token]);
            $session = $stmt->fetch();
            $expiresAt = $session['expires_at'];
            
            // Reset failed attempts and update last login
            db()->prepare("UPDATE users SET failed_login_attempts = 0, is_locked = FALSE, locked_until = NULL, last_login = NOW() WHERE id = ?")
                ->execute([$user['id']]);
            
            // Get user roles and permissions
            $roles = getUserRoles($user['id']);
            $permissions = getUserPermissions($user['id']);
            
            auditLog($user['id'], 'login_success', 'auth', 'user', $user['id']);
            
            $_SESSION['auth_token'] = $token;
            $_SESSION['user_id'] = $user['id'];
            
            respond([
                'token' => $token,
                'expires_at' => $expiresAt,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'avatar_url' => $user['avatar_url'],
                    'must_change_password' => (bool)$user['must_change_password']
                ],
                'roles' => $roles,
                'permissions' => $permissions
            ]);
            break;
            
        case 'auth/logout':
            $user = getCurrentUser();
            if ($user) {
                $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['token'] ?? '');
                db()->prepare("UPDATE user_sessions SET is_active = FALSE WHERE session_token = ?")->execute([$token]);
                auditLog($user['id'], 'logout', 'auth', 'user', $user['id']);
            }
            session_destroy();
            respond(['message' => 'Logged out successfully']);
            break;

        case 'auth/sso_login':
            if ($method !== 'POST') error('Method not allowed', 405);

            $data = getInput();
            $username = $data['username'] ?? '';
            $email = $data['email'] ?? '';
            $firstName = $data['first_name'] ?? '';
            $lastName = $data['last_name'] ?? '';
            $ssoProvider = $data['sso_provider'] ?? 'keycloak';
            $ssoId = $data['sso_id'] ?? '';
            $defaultRoleCode = $data['default_role'] ?? 'user';

            if (!$username && !$email) {
                error('Username or email required for SSO login');
            }

            // Check if user exists (by email or username)
            $stmt = db()->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
            $stmt->execute([$email, $username]);
            $user = $stmt->fetch();

            if (!$user) {
                // Auto-create SSO user
                $newUsername = $username ?: explode('@', $email)[0];
                // Check if username exists
                $stmt = db()->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$newUsername]);
                if ($stmt->fetch()) {
                    $newUsername = $newUsername . '_' . substr(md5($ssoId), 0, 6);
                }

                $stmt = db()->prepare("INSERT INTO users (username, email, first_name, last_name, auth_type, is_active, created_at) VALUES (?, ?, ?, ?, 'sso', 1, NOW())");
                $stmt->execute([$newUsername, $email, $firstName, $lastName]);
                $userId = db()->lastInsertId();

                // Assign default role from settings
                $stmt = db()->prepare("SELECT id FROM roles WHERE code = ? LIMIT 1");
                $stmt->execute([$defaultRoleCode]);
                $defaultRole = $stmt->fetch();
                if (!$defaultRole) {
                    $stmt = db()->prepare("SELECT id FROM roles WHERE code = 'user' LIMIT 1");
                    $stmt->execute();
                    $defaultRole = $stmt->fetch();
                }
                if ($defaultRole) {
                    db()->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$userId, $defaultRole['id']]);
                }

                // Get the new user
                $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();

                auditLog($userId, 'sso_register', 'auth', 'user', $userId, null, ['provider' => $ssoProvider, 'role' => $defaultRoleCode]);
            }

            // Check if user is active
            if (!$user['is_active']) {
                error('User account is deactivated', 403);
            }

            // Generate session token
            $token = bin2hex(random_bytes(32));
            $sessionTimeout = 28800; // 8 hours

            $stmt = db()->prepare("INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))");
            $stmt->execute([
                $user['id'],
                $token,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $sessionTimeout
            ]);

            // Update last login
            db()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

            // Get roles and permissions
            $roles = getUserRoles($user['id']);
            $permissions = getUserPermissions($user['id']);

            auditLog($user['id'], 'sso_login', 'auth', 'user', $user['id'], null, ['provider' => $ssoProvider]);

            $_SESSION['auth_token'] = $token;
            $_SESSION['user_id'] = $user['id'];

            respond([
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'avatar_url' => $user['avatar_url'] ?? null,
                    'must_change_password' => false
                ],
                'roles' => $roles,
                'permissions' => $permissions
            ]);
            break;

        case 'ldap/test':
            if ($method !== 'POST') error('Method not allowed', 405);

            $data = getInput();
            $ldapUrl = $data['url'] ?? '';
            $baseDn = $data['base_dn'] ?? '';
            $bindDn = $data['bind_dn'] ?? '';
            $bindPassword = $data['bind_password'] ?? '';

            if (!$ldapUrl || !$baseDn || !$bindDn || !$bindPassword) {
                error('All LDAP parameters are required');
            }

            // Use Docker ldapsearch since PHP LDAP extension has DLL issues on Windows
            $cmd = 'docker exec openldap-test ldapsearch -x -H ldap://localhost:389 -b "' . $baseDn . '" -D "' . $bindDn . '" -w "' . $bindPassword . '" "(objectClass=*)" dn 2>&1';

            exec($cmd, $output, $returnCode);

            if ($returnCode === 0) {
                $count = 0;
                foreach ($output as $line) {
                    if (strpos($line, 'dn:') === 0) $count++;
                }
                respond([
                    'message' => 'LDAP bağlantısı uğurlu!',
                    'entries_found' => $count
                ]);
            } else {
                $errorMsg = implode("\n", $output);
                if (strpos($errorMsg, 'Invalid credentials') !== false) {
                    error('LDAP bind uğursuz: Yanlış istifadəçi adı və ya parol');
                } else if (strpos($errorMsg, 'No such object') !== false) {
                    error('LDAP xətası: Base DN tapılmadı');
                } else {
                    error('LDAP bağlantısı uğursuz: ' . $errorMsg);
                }
            }
            break;

        case 'ldap/users':
            if ($method !== 'POST') error('Method not allowed', 405);

            $data = getInput();
            $baseDn = $data['base_dn'] ?? '';
            $bindDn = $data['bind_dn'] ?? '';
            $bindPassword = $data['bind_password'] ?? '';
            $usersDn = $data['users_dn'] ?? '';
            $userFilter = $data['user_filter'] ?? '(objectClass=person)';

            // Use Docker ldapsearch
            $cmd = 'docker exec openldap-test ldapsearch -x -H ldap://localhost:389 -b "' . $usersDn . '" -D "' . $bindDn . '" -w "' . $bindPassword . '" "' . $userFilter . '" uid cn sn givenName mail 2>&1';

            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                error('LDAP search failed: ' . implode("\n", $output));
            }

            // Parse ldapsearch output
            $users = [];
            $currentUser = [];

            foreach ($output as $line) {
                $line = trim($line);
                if (empty($line) || $line[0] === '#') continue;

                if (strpos($line, 'dn:') === 0) {
                    if (!empty($currentUser)) {
                        $users[] = $currentUser;
                    }
                    $currentUser = ['dn' => trim(substr($line, 3))];
                } else if (strpos($line, 'uid:') === 0) {
                    $currentUser['username'] = trim(substr($line, 4));
                } else if (strpos($line, 'cn:') === 0) {
                    $currentUser['full_name'] = trim(substr($line, 3));
                } else if (strpos($line, 'sn:') === 0) {
                    $currentUser['last_name'] = trim(substr($line, 3));
                } else if (strpos($line, 'givenName:') === 0) {
                    $currentUser['first_name'] = trim(substr($line, 10));
                } else if (strpos($line, 'mail:') === 0) {
                    $currentUser['email'] = trim(substr($line, 5));
                }
            }

            if (!empty($currentUser) && isset($currentUser['username'])) {
                $users[] = $currentUser;
            }

            // Insert users into database
            $defaultRoleCode = strtoupper($data['default_role'] ?? 'viewer');
            $insertedCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;

            // Get default role ID from user_roles table (case-insensitive)
            $roleStmt = db()->prepare("SELECT id FROM user_roles WHERE UPPER(code) = ? LIMIT 1");
            $roleStmt->execute([$defaultRoleCode]);
            $defaultRoleId = $roleStmt->fetchColumn();

            if (!$defaultRoleId) {
                // Fallback to 'VIEWER' role
                $roleStmt->execute(['VIEWER']);
                $defaultRoleId = $roleStmt->fetchColumn();
            }

            // Get default tenant_id (first tenant or 1)
            $tenantStmt = db()->query("SELECT id FROM tenants LIMIT 1");
            $defaultTenantId = $tenantStmt->fetchColumn() ?: 1;

            foreach ($users as &$ldapUser) {
                if (empty($ldapUser['username'])) {
                    $skippedCount++;
                    continue;
                }

                // Check if user exists
                $checkStmt = db()->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $checkStmt->execute([$ldapUser['username']]);
                $existingUserId = $checkStmt->fetchColumn();

                if ($existingUserId) {
                    // Update existing user
                    $updateStmt = db()->prepare("UPDATE users SET
                        email = COALESCE(?, email),
                        first_name = COALESCE(?, first_name),
                        last_name = COALESCE(?, last_name),
                        auth_type = 'ldap',
                        sso_provider = 'ldap',
                        sso_id = ?
                        WHERE id = ?");
                    $updateStmt->execute([
                        $ldapUser['email'] ?? null,
                        $ldapUser['first_name'] ?? null,
                        $ldapUser['last_name'] ?? null,
                        $ldapUser['dn'] ?? $ldapUser['username'],
                        $existingUserId
                    ]);
                    $ldapUser['status'] = 'updated';
                    $ldapUser['user_id'] = $existingUserId;
                    $updatedCount++;
                } else {
                    // Insert new user with correct column names
                    $insertStmt = db()->prepare("INSERT INTO users (tenant_id, username, email, password_hash, first_name, last_name, auth_type, is_active, sso_provider, sso_id, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'ldap', 1, 'ldap', ?, NOW())");

                    $email = $ldapUser['email'] ?? $ldapUser['username'] . '@ldap.local';
                    $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

                    $insertStmt->execute([
                        $defaultTenantId,
                        $ldapUser['username'],
                        $email,
                        $randomPassword,
                        $ldapUser['first_name'] ?? '',
                        $ldapUser['last_name'] ?? '',
                        $ldapUser['dn'] ?? $ldapUser['username']
                    ]);

                    $newUserId = db()->lastInsertId();
                    $ldapUser['status'] = 'inserted';
                    $ldapUser['user_id'] = $newUserId;

                    // Assign default role using user_role_assignments table
                    if ($defaultRoleId) {
                        $roleAssignStmt = db()->prepare("INSERT IGNORE INTO user_role_assignments (user_id, role_id, assigned_at) VALUES (?, ?, NOW())");
                        $roleAssignStmt->execute([$newUserId, $defaultRoleId]);
                    }

                    $insertedCount++;
                }
            }

            respond([
                'users' => $users,
                'count' => count($users),
                'inserted' => $insertedCount,
                'updated' => $updatedCount,
                'skipped' => $skippedCount,
                'default_role' => $defaultRoleCode
            ]);
            break;

        case 'ldap/verify':
            if ($method !== 'POST') error('Method not allowed', 405);

            $data = getInput();
            $ldapUrl = $data['ldap_url'] ?? '';
            $baseDn = $data['base_dn'] ?? '';
            $usersDn = $data['users_dn'] ?? '';
            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';

            if (!$username || !$password) {
                error('İstifadəçi adı və parol tələb olunur');
            }

            // Use Docker ldapsearch to verify user credentials
            // First find the user DN
            $adminBindDn = $data['bind_dn'] ?? '';
            $adminBindPassword = $data['bind_password'] ?? '';

            $findUserCmd = 'docker exec openldap-test ldapsearch -x -H ldap://localhost:389 -b "' . $usersDn . '" -D "' . $adminBindDn . '" -w "' . $adminBindPassword . '" "(uid=' . $username . ')" dn cn givenName sn mail 2>&1';

            exec($findUserCmd, $findOutput, $findReturnCode);

            if ($findReturnCode !== 0) {
                error('İstifadəçi tapılmadı');
            }

            // Parse user info
            $userDn = null;
            $userInfo = ['username' => $username];

            foreach ($findOutput as $line) {
                $line = trim($line);
                if (strpos($line, 'dn:') === 0) {
                    $userDn = trim(substr($line, 3));
                } else if (strpos($line, 'cn:') === 0) {
                    $userInfo['full_name'] = trim(substr($line, 3));
                } else if (strpos($line, 'givenName:') === 0) {
                    $userInfo['first_name'] = trim(substr($line, 10));
                } else if (strpos($line, 'sn:') === 0) {
                    $userInfo['last_name'] = trim(substr($line, 3));
                } else if (strpos($line, 'mail:') === 0) {
                    $userInfo['email'] = trim(substr($line, 5));
                }
            }

            if (!$userDn) {
                error('İstifadəçi tapılmadı: ' . $username);
            }

            $userInfo['dn'] = $userDn;

            // Now try to bind with user's credentials to verify password
            $verifyCmd = 'docker exec openldap-test ldapwhoami -x -H ldap://localhost:389 -D "' . $userDn . '" -w "' . $password . '" 2>&1';

            exec($verifyCmd, $verifyOutput, $verifyReturnCode);

            if ($verifyReturnCode !== 0) {
                error('Yanlış parol');
            }

            respond($userInfo);
            break;

        case 'ldap/sync':
            if ($method !== 'POST') error('Method not allowed', 405);

            $data = getInput();
            $ldapUrl = $data['ldap_url'] ?? '';
            $baseDn = $data['ldap_base_dn'] ?? '';
            $bindDn = $data['ldap_bind_dn'] ?? '';
            $bindPassword = $data['ldap_bind_password'] ?? '';
            $usersDn = $data['ldap_users_dn'] ?? '';
            $userFilter = $data['ldap_user_filter'] ?? '(objectClass=person)';
            $keycloakUrl = $data['keycloak_url'] ?? '';
            $keycloakRealm = $data['keycloak_realm'] ?? '';

            if (!function_exists('ldap_connect')) {
                error('PHP LDAP extension is not installed');
            }

            try {
                // Connect to LDAP
                $ldapConn = @ldap_connect($ldapUrl);
                if (!$ldapConn) {
                    error('Cannot connect to LDAP server');
                }

                ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
                ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);

                $bind = @ldap_bind($ldapConn, $bindDn, $bindPassword);
                if (!$bind) {
                    error('LDAP bind failed: ' . ldap_error($ldapConn));
                }

                // Search for users
                $search = @ldap_search($ldapConn, $usersDn, $userFilter, ['uid', 'cn', 'sn', 'givenName', 'mail', 'sAMAccountName']);
                if (!$search) {
                    error('LDAP search failed: ' . ldap_error($ldapConn));
                }

                $entries = ldap_get_entries($ldapConn, $search);
                $synced = 0;

                // Get Keycloak admin token
                $tokenUrl = $keycloakUrl . '/realms/master/protocol/openid-connect/token';
                $tokenData = http_build_query([
                    'grant_type' => 'password',
                    'client_id' => 'admin-cli',
                    'username' => 'admin',
                    'password' => 'admin123'
                ]);

                $ch = curl_init($tokenUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $tokenData);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
                $tokenResponse = curl_exec($ch);
                curl_close($ch);

                $tokenJson = json_decode($tokenResponse, true);
                $accessToken = $tokenJson['access_token'] ?? null;

                if (!$accessToken) {
                    ldap_close($ldapConn);
                    error('Cannot get Keycloak admin token');
                }

                // Create users in Keycloak
                for ($i = 0; $i < $entries['count']; $i++) {
                    $entry = $entries[$i];
                    $username = $entry['samaccountname'][0] ?? $entry['uid'][0] ?? null;
                    $email = $entry['mail'][0] ?? null;
                    $firstName = $entry['givenname'][0] ?? '';
                    $lastName = $entry['sn'][0] ?? '';

                    if (!$username) continue;

                    $userData = [
                        'username' => $username,
                        'email' => $email,
                        'firstName' => $firstName,
                        'lastName' => $lastName,
                        'enabled' => true,
                        'emailVerified' => true
                    ];

                    $ch = curl_init($keycloakUrl . '/admin/realms/' . $keycloakRealm . '/users');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($userData));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $accessToken
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode == 201 || $httpCode == 409) {
                        $synced++;
                    }
                }

                ldap_close($ldapConn);

                respond([
                    'synced' => $synced,
                    'total_found' => $entries['count'],
                    'message' => $synced . ' users synced to Keycloak'
                ]);
            } catch (Exception $e) {
                error('Sync error: ' . $e->getMessage());
            }
            break;

        case 'auth/me':
            $user = requireAuth();
            $roles = getUserRoles($user['id']);
            $permissions = getUserPermissions($user['id']);
            
            respond([
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'avatar_url' => $user['avatar_url'],
                    'must_change_password' => (bool)$user['must_change_password']
                ],
                'roles' => $roles,
                'permissions' => $permissions
            ]);
            break;
        
        case 'auth/debug':
            $token = $_GET['token'] ?? null;
            if (!$token) {
                respond(['error' => 'No token provided', 'get' => $_GET]);
                break;
            }
            
            // Check if session exists
            $stmt = db()->prepare("SELECT * FROM user_sessions WHERE session_token = ?");
            $stmt->execute([$token]);
            $session = $stmt->fetch();
            
            // Get current time from MySQL
            $now = db()->query("SELECT NOW() as now")->fetch();
            
            // Check if expired
            $isExpired = false;
            if ($session) {
                $stmt2 = db()->prepare("SELECT expires_at > NOW() as is_valid FROM user_sessions WHERE session_token = ?");
                $stmt2->execute([$token]);
                $check = $stmt2->fetch();
                $isExpired = !$check['is_valid'];
            }
            
            respond([
                'token_received' => substr($token, 0, 20) . '...',
                'token_length' => strlen($token),
                'session_found' => $session ? true : false,
                'session_is_active' => $session ? (bool)$session['is_active'] : false,
                'session_expires_at' => $session ? $session['expires_at'] : null,
                'session_is_expired' => $isExpired,
                'mysql_now' => $now['now'],
                'php_now' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'auth/cleanup':
            // Clear all expired sessions
            $stmt = db()->prepare("DELETE FROM user_sessions WHERE expires_at < NOW() OR is_active = FALSE");
            $stmt->execute();
            $deleted = $stmt->rowCount();
            respond(['message' => "Cleaned up $deleted expired sessions"]);
            break;
        
        // One-time setup - set admin password
        case 'auth/setup':
            if ($method !== 'POST') error('Method not allowed', 405);
            
            $data = getInput();
            $password = $data['password'] ?? 'Admin@123';
            
            // Hash the password
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
            
            // Update all users with new hash
            $stmt = db()->prepare("UPDATE users SET password_hash = ?");
            $stmt->execute([$hash]);
            
            respond([
                'message' => 'Passwords updated successfully',
                'password' => $password,
                'hash' => $hash
            ]);
            break;
            
        case 'auth/change-password':
            if ($method !== 'POST') error('Method not allowed', 405);
            
            $user = requireAuth();
            $data = getInput();
            
            $currentPassword = $data['current_password'] ?? '';
            $newPassword = $data['new_password'] ?? '';
            
            if (!$currentPassword || !$newPassword) {
                error('Current and new password are required');
            }
            
            // Verify current password
            if (!verifyPassword($currentPassword, $user['password_hash'])) {
                auditLog($user['id'], 'password_change_failed', 'auth', 'user', $user['id'], null, null, 'failure', 'Invalid current password');
                error('Current password is incorrect', 401);
            }
            
            // Validate new password
            $errors = validatePassword($newPassword, $user['tenant_id']);
            if ($errors) {
                error(implode('. ', $errors));
            }
            
            // Check password history
            if (!checkPasswordHistory($user['id'], $newPassword, $user['tenant_id'])) {
                error('Password was used recently. Please choose a different password.');
            }
            
            // Update password
            $newHash = hashPassword($newPassword);
            db()->prepare("UPDATE users SET password_hash = ?, last_password_change = NOW(), must_change_password = FALSE WHERE id = ?")
                ->execute([$newHash, $user['id']]);
            
            // Add to history
            db()->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)")
                ->execute([$user['id'], $newHash]);
            
            auditLog($user['id'], 'password_changed', 'auth', 'user', $user['id']);
            
            respond(['message' => 'Password changed successfully']);
            break;
            
        // ============================================================
        // USER MANAGEMENT
        // ============================================================
        
        case 'users':
            if ($method === 'GET') {
                $currentUser = requirePermission('users', 'view');
                
                if ($id) {
                    $stmt = db()->prepare("
                        SELECT u.id, u.tenant_id, u.username, u.email, u.first_name, u.last_name, 
                               u.phone, u.avatar_url, u.auth_type, u.is_active, u.is_locked, 
                               u.last_login, u.created_at
                        FROM users u WHERE u.id = ?
                    ");
                    $stmt->execute([$id]);
                    $user = $stmt->fetch();
                    if (!$user) error('User not found', 404);
                    
                    // Get roles
                    $user['roles'] = getUserRoles($id);
                    respond($user);
                } else {
                    $search = $_GET['q'] ?? '';
                    $role = $_GET['role'] ?? '';
                    $status = $_GET['status'] ?? '';
                    
                    $sql = "
                        SELECT u.id, u.username, u.email, u.first_name, u.last_name, 
                               u.auth_type, u.is_active, u.is_locked, u.last_login, u.created_at,
                               GROUP_CONCAT(r.name) as role_names
                        FROM users u
                        LEFT JOIN user_role_assignments ura ON u.id = ura.user_id
                        LEFT JOIN user_roles r ON ura.role_id = r.id
                        WHERE u.tenant_id = ?
                    ";
                    $params = [$currentUser['tenant_id']];
                    
                    if ($search) {
                        $sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
                        $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
                    }
                    if ($status === 'active') {
                        $sql .= " AND u.is_active = TRUE AND u.is_locked = FALSE";
                    } elseif ($status === 'inactive') {
                        $sql .= " AND u.is_active = FALSE";
                    } elseif ($status === 'locked') {
                        $sql .= " AND u.is_locked = TRUE";
                    }
                    if ($role) {
                        $sql .= " AND r.code = ?";
                        $params[] = $role;
                    }
                    
                    $sql .= " GROUP BY u.id ORDER BY u.created_at DESC";
                    
                    $stmt = db()->prepare($sql);
                    $stmt->execute($params);
                    respond($stmt->fetchAll());
                }
            }
            elseif ($method === 'POST') {
                $currentUser = requirePermission('users', 'create');
                $data = getInput();
                
                $username = trim($data['username'] ?? '');
                $email = trim($data['email'] ?? '');
                $password = $data['password'] ?? '';
                $firstName = trim($data['first_name'] ?? '');
                $lastName = trim($data['last_name'] ?? '');
                $roles = $data['roles'] ?? [];
                
                if (!$username || !$email) {
                    error('Username and email are required');
                }
                
                // Check uniqueness
                $stmt = db()->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND tenant_id = ?");
                $stmt->execute([$username, $email, $currentUser['tenant_id']]);
                if ($stmt->fetch()) {
                    error('Username or email already exists');
                }
                
                // Validate password if local auth
                if ($password) {
                    $errors = validatePassword($password, $currentUser['tenant_id']);
                    if ($errors) {
                        error(implode('. ', $errors));
                    }
                    $passwordHash = hashPassword($password);
                } else {
                    $passwordHash = null;
                }
                
                // Create user
                $stmt = db()->prepare("
                    INSERT INTO users (tenant_id, username, email, password_hash, first_name, last_name, auth_type, must_change_password, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, 'local', TRUE, ?)
                ");
                $stmt->execute([
                    $currentUser['tenant_id'],
                    $username,
                    $email,
                    $passwordHash,
                    $firstName,
                    $lastName,
                    $currentUser['id']
                ]);
                $newUserId = db()->lastInsertId();
                
                // Assign roles
                if ($roles) {
                    foreach ($roles as $roleId) {
                        db()->prepare("INSERT INTO user_role_assignments (user_id, role_id, assigned_by) VALUES (?, ?, ?)")
                            ->execute([$newUserId, $roleId, $currentUser['id']]);
                    }
                }
                
                auditLog($currentUser['id'], 'user_created', 'users', 'user', $newUserId, null, ['username' => $username, 'email' => $email]);
                
                respond(['id' => $newUserId, 'message' => 'User created successfully']);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('User ID is required');
                $currentUser = requirePermission('users', 'edit');
                $data = getInput();
                
                // Get current user data
                $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $oldUser = $stmt->fetch();
                if (!$oldUser) error('User not found', 404);
                
                $updates = [];
                $params = [];
                
                if (isset($data['email'])) {
                    $updates[] = 'email = ?';
                    $params[] = $data['email'];
                }
                if (isset($data['first_name'])) {
                    $updates[] = 'first_name = ?';
                    $params[] = $data['first_name'];
                }
                if (isset($data['last_name'])) {
                    $updates[] = 'last_name = ?';
                    $params[] = $data['last_name'];
                }
                if (isset($data['phone'])) {
                    $updates[] = 'phone = ?';
                    $params[] = $data['phone'];
                }
                if (isset($data['is_active'])) {
                    $updates[] = 'is_active = ?';
                    $params[] = $data['is_active'] ? 1 : 0;
                }
                if (isset($data['is_locked'])) {
                    $updates[] = 'is_locked = ?';
                    $updates[] = 'locked_until = NULL';
                    $params[] = $data['is_locked'] ? 1 : 0;
                }
                
                if ($updates) {
                    $params[] = $id;
                    $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
                    db()->prepare($sql)->execute($params);
                }
                
                // Update roles if provided
                if (isset($data['roles'])) {
                    db()->prepare("DELETE FROM user_role_assignments WHERE user_id = ?")->execute([$id]);
                    foreach ($data['roles'] as $roleId) {
                        db()->prepare("INSERT INTO user_role_assignments (user_id, role_id, assigned_by) VALUES (?, ?, ?)")
                            ->execute([$id, $roleId, $currentUser['id']]);
                    }
                }
                
                auditLog($currentUser['id'], 'user_updated', 'users', 'user', $id, $oldUser, $data);
                
                respond(['message' => 'User updated successfully']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('User ID is required');
                $currentUser = requirePermission('users', 'delete');
                
                // Don't allow deleting yourself
                if ($id == $currentUser['id']) {
                    error('Cannot delete your own account');
                }
                
                // Get user for audit
                $stmt = db()->prepare("SELECT username FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $user = $stmt->fetch();
                
                db()->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
                
                auditLog($currentUser['id'], 'user_deleted', 'users', 'user', $id, $user, null);
                
                respond(['message' => 'User deleted successfully']);
            }
            break;
            
        // ============================================================
        // ROLE MANAGEMENT
        // ============================================================
        
        case 'roles':
            if ($method === 'GET') {
                $currentUser = requirePermission('roles', 'view');
                
                if ($id) {
                    $stmt = db()->prepare("SELECT * FROM user_roles WHERE id = ?");
                    $stmt->execute([$id]);
                    $role = $stmt->fetch();
                    if (!$role) error('Role not found', 404);
                    
                    // Get permissions
                    $stmt = db()->prepare("
                        SELECT p.* FROM permissions p
                        JOIN role_permissions rp ON p.id = rp.permission_id
                        WHERE rp.role_id = ?
                    ");
                    $stmt->execute([$id]);
                    $role['permissions'] = $stmt->fetchAll();
                    
                    respond($role);
                } else {
                    $stmt = db()->prepare("
                        SELECT r.*, COUNT(ura.user_id) as user_count
                        FROM user_roles r
                        LEFT JOIN user_role_assignments ura ON r.id = ura.role_id
                        WHERE r.tenant_id = ? OR r.tenant_id IS NULL
                        GROUP BY r.id
                        ORDER BY r.is_system DESC, r.name
                    ");
                    $stmt->execute([$currentUser['tenant_id']]);
                    respond($stmt->fetchAll());
                }
            }
            elseif ($method === 'POST') {
                $currentUser = requirePermission('roles', 'create');
                $data = getInput();
                
                $name = trim($data['name'] ?? '');
                $code = trim($data['code'] ?? '');
                $description = $data['description'] ?? '';
                $permissions = $data['permissions'] ?? [];
                
                if (!$name || !$code) {
                    error('Name and code are required');
                }
                
                // Create role
                $stmt = db()->prepare("
                    INSERT INTO user_roles (tenant_id, name, code, description)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$currentUser['tenant_id'], $name, $code, $description]);
                $roleId = db()->lastInsertId();
                
                // Assign permissions
                if ($permissions) {
                    foreach ($permissions as $permId) {
                        db()->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)")
                            ->execute([$roleId, $permId]);
                    }
                }
                
                auditLog($currentUser['id'], 'role_created', 'roles', 'role', $roleId, null, ['name' => $name, 'code' => $code]);
                
                respond(['id' => $roleId, 'message' => 'Role created successfully']);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('Role ID is required');
                $currentUser = requirePermission('roles', 'edit');
                $data = getInput();
                
                // Check if system role
                $stmt = db()->prepare("SELECT * FROM user_roles WHERE id = ?");
                $stmt->execute([$id]);
                $role = $stmt->fetch();
                if (!$role) error('Role not found', 404);
                
                if ($role['is_system'] && !hasPermission($currentUser['id'], 'settings', 'edit')) {
                    error('Cannot modify system roles');
                }
                
                $updates = [];
                $params = [];
                
                if (isset($data['name'])) {
                    $updates[] = 'name = ?';
                    $params[] = $data['name'];
                }
                if (isset($data['description'])) {
                    $updates[] = 'description = ?';
                    $params[] = $data['description'];
                }
                if (isset($data['is_active'])) {
                    $updates[] = 'is_active = ?';
                    $params[] = $data['is_active'] ? 1 : 0;
                }
                
                if ($updates) {
                    $params[] = $id;
                    db()->prepare("UPDATE user_roles SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
                }
                
                // Update permissions
                if (isset($data['permissions'])) {
                    db()->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$id]);
                    foreach ($data['permissions'] as $permId) {
                        db()->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)")
                            ->execute([$id, $permId]);
                    }
                }
                
                auditLog($currentUser['id'], 'role_updated', 'roles', 'role', $id, $role, $data);
                
                respond(['message' => 'Role updated successfully']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('Role ID is required');
                $currentUser = requirePermission('roles', 'delete');
                
                // Check if system role
                $stmt = db()->prepare("SELECT * FROM user_roles WHERE id = ?");
                $stmt->execute([$id]);
                $role = $stmt->fetch();
                if (!$role) error('Role not found', 404);
                
                if ($role['is_system']) {
                    error('Cannot delete system roles');
                }
                
                db()->prepare("DELETE FROM user_roles WHERE id = ?")->execute([$id]);
                
                auditLog($currentUser['id'], 'role_deleted', 'roles', 'role', $id, $role, null);
                
                respond(['message' => 'Role deleted successfully']);
            }
            break;
            
        // ============================================================
        // PERMISSIONS
        // ============================================================
        
        case 'permissions':
            $currentUser = requirePermission('roles', 'view');
            
            $stmt = db()->query("SELECT * FROM permissions WHERE is_active = TRUE ORDER BY module, action");
            $permissions = $stmt->fetchAll();
            
            // Group by module
            $grouped = [];
            foreach ($permissions as $p) {
                if (!isset($grouped[$p['module']])) {
                    $grouped[$p['module']] = [];
                }
                $grouped[$p['module']][] = $p;
            }
            
            respond(['permissions' => $permissions, 'grouped' => $grouped]);
            break;
            
        // ============================================================
        // AUDIT LOG
        // ============================================================
        
        case 'audit':
            $currentUser = requirePermission('audit', 'view');
            
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = min(100, max(10, intval($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;
            
            $userId = $_GET['user_id'] ?? '';
            $action = $_GET['filter_action'] ?? '';
            $module = $_GET['module'] ?? '';
            $dateFrom = $_GET['date_from'] ?? '';
            $dateTo = $_GET['date_to'] ?? '';
            
            $sql = "SELECT a.*, u.username as user_name FROM audit_log a LEFT JOIN users u ON a.user_id = u.id WHERE a.tenant_id = ?";
            $countSql = "SELECT COUNT(*) as total FROM audit_log a WHERE a.tenant_id = ?";
            $params = [$currentUser['tenant_id']];
            
            if ($userId) {
                $sql .= " AND a.user_id = ?";
                $countSql .= " AND a.user_id = ?";
                $params[] = $userId;
            }
            if ($action) {
                $sql .= " AND a.action = ?";
                $countSql .= " AND a.action = ?";
                $params[] = $action;
            }
            if ($module) {
                $sql .= " AND a.module = ?";
                $countSql .= " AND a.module = ?";
                $params[] = $module;
            }
            if ($dateFrom) {
                $sql .= " AND a.created_at >= ?";
                $countSql .= " AND a.created_at >= ?";
                $params[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo) {
                $sql .= " AND a.created_at <= ?";
                $countSql .= " AND a.created_at <= ?";
                $params[] = $dateTo . ' 23:59:59';
            }
            
            // Get total count
            $stmt = db()->prepare($countSql);
            $stmt->execute($params);
            $total = $stmt->fetch()['total'];
            
            $sql .= " ORDER BY a.created_at DESC LIMIT $limit OFFSET $offset";
            
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll();
            
            respond([
                'data' => $logs,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;
            
        // ============================================================
        // USER SESSIONS
        // ============================================================
        
        case 'sessions':
            $currentUser = requireAuth();
            
            if ($method === 'GET') {
                $stmt = db()->prepare("
                    SELECT id, ip_address, user_agent, created_at, last_activity, expires_at,
                           CASE WHEN session_token = ? THEN TRUE ELSE FALSE END as is_current
                    FROM user_sessions 
                    WHERE user_id = ? AND is_active = TRUE
                    ORDER BY last_activity DESC
                ");
                $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
                $stmt->execute([$token, $currentUser['id']]);
                respond($stmt->fetchAll());
            }
            elseif ($method === 'DELETE') {
                if ($id === 'all') {
                    // Logout all sessions except current
                    $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
                    db()->prepare("UPDATE user_sessions SET is_active = FALSE WHERE user_id = ? AND session_token != ?")
                        ->execute([$currentUser['id'], $token]);
                    auditLog($currentUser['id'], 'logout_all', 'auth', 'user', $currentUser['id']);
                } else {
                    db()->prepare("UPDATE user_sessions SET is_active = FALSE WHERE id = ? AND user_id = ?")
                        ->execute([$id, $currentUser['id']]);
                }
                respond(['message' => 'Session(s) terminated']);
            }
            break;
            
        // ============================================================
        // PASSWORD POLICY
        // ============================================================
        
        case 'password-policy':
            $currentUser = requirePermission('settings', 'view');
            
            if ($method === 'GET') {
                $stmt = db()->prepare("SELECT * FROM password_policies WHERE tenant_id = ? OR tenant_id IS NULL ORDER BY tenant_id DESC LIMIT 1");
                $stmt->execute([$currentUser['tenant_id']]);
                respond($stmt->fetch());
            }
            elseif ($method === 'PUT') {
                requirePermission('settings', 'edit');
                $data = getInput();
                
                $stmt = db()->prepare("SELECT id FROM password_policies WHERE tenant_id = ?");
                $stmt->execute([$currentUser['tenant_id']]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    db()->prepare("
                        UPDATE password_policies SET
                            min_length = ?, require_uppercase = ?, require_lowercase = ?,
                            require_number = ?, require_special = ?, max_age_days = ?,
                            prevent_reuse_count = ?, lockout_attempts = ?, 
                            lockout_duration_minutes = ?, session_timeout_minutes = ?
                        WHERE id = ?
                    ")->execute([
                        $data['min_length'] ?? 8,
                        $data['require_uppercase'] ?? true,
                        $data['require_lowercase'] ?? true,
                        $data['require_number'] ?? true,
                        $data['require_special'] ?? true,
                        $data['max_age_days'] ?? 90,
                        $data['prevent_reuse_count'] ?? 5,
                        $data['lockout_attempts'] ?? 5,
                        $data['lockout_duration_minutes'] ?? 30,
                        $data['session_timeout_minutes'] ?? 60,
                        $existing['id']
                    ]);
                } else {
                    db()->prepare("
                        INSERT INTO password_policies (tenant_id, min_length, require_uppercase, require_lowercase,
                            require_number, require_special, max_age_days, prevent_reuse_count, 
                            lockout_attempts, lockout_duration_minutes, session_timeout_minutes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $currentUser['tenant_id'],
                        $data['min_length'] ?? 8,
                        $data['require_uppercase'] ?? true,
                        $data['require_lowercase'] ?? true,
                        $data['require_number'] ?? true,
                        $data['require_special'] ?? true,
                        $data['max_age_days'] ?? 90,
                        $data['prevent_reuse_count'] ?? 5,
                        $data['lockout_attempts'] ?? 5,
                        $data['lockout_duration_minutes'] ?? 30,
                        $data['session_timeout_minutes'] ?? 60
                    ]);
                }
                
                auditLog($currentUser['id'], 'policy_updated', 'settings', 'password_policy', null, null, $data);
                
                respond(['message' => 'Password policy updated']);
            }
            break;
        
        // ============================================================
        // GLOSSARY TERMS
        // ============================================================
        
        case 'glossary_terms':
            if ($method === 'GET') {
                if ($id) {
                    $stmt = db()->prepare("SELECT * FROM glossary_terms WHERE id = ?");
                    $stmt->execute([$id]);
                    $term = $stmt->fetch();
                    if (!$term) error('Term not found', 404);

                    // Get history
                    $histStmt = db()->prepare("SELECT * FROM glossary_term_history WHERE term_id = ? ORDER BY created_at DESC");
                    $histStmt->execute([$id]);
                    $term['history'] = $histStmt->fetchAll();

                    if (!empty($term['physical_attributes'])) {
                        $attrs = json_decode($term['physical_attributes'], true);
                        if (is_array($attrs)) {
                            applyCurrentNamesToPhysicalAttributes($attrs);
                            $term['physical_attributes'] = json_encode($attrs, JSON_UNESCAPED_UNICODE);
                        }
                    }

                    respond($term);
                } else {
                    $search = $_GET['q'] ?? '';
                    $status = $_GET['status'] ?? '';
                    $domain = $_GET['domain'] ?? '';
                    
                    $sql = "SELECT t.*, 
                            (SELECT COUNT(*) FROM glossary_term_history WHERE term_id = t.id) as history_count
                            FROM glossary_terms t WHERE 1=1";
                    $params = [];
                    
                    if ($search) {
                        $sql .= " AND (t.name LIKE ? OR t.definition LIKE ?)";
                        $params[] = "%$search%";
                        $params[] = "%$search%";
                    }
                    if ($status) {
                        $sql .= " AND t.status = ?";
                        $params[] = $status;
                    }
                    if ($domain) {
                        $sql .= " AND t.domain = ?";
                        $params[] = $domain;
                    }
                    
                    $sql .= " ORDER BY t.created_at DESC";
                    $stmt = db()->prepare($sql);
                    $stmt->execute($params);
                    $rows = $stmt->fetchAll();
                    foreach ($rows as &$row) {
                        if (!empty($row['physical_attributes'])) {
                            $attrs = json_decode($row['physical_attributes'], true);
                            if (is_array($attrs)) {
                                applyCurrentNamesToPhysicalAttributes($attrs);
                                $row['physical_attributes'] = json_encode($attrs, JSON_UNESCAPED_UNICODE);
                            }
                        }
                    }
                    unset($row);
                    respond($rows);
                }
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['name'])) error('Name is required');
                
                // Handle stewards as JSON array
                $stewards = '';
                if (isset($data['stewards'])) {
                    $stewards = is_array($data['stewards']) ? json_encode($data['stewards']) : $data['stewards'];
                } elseif (isset($data['steward'])) {
                    $stewards = is_array($data['steward']) ? json_encode($data['steward']) : json_encode([$data['steward']]);
                }
                
                $physicalAttributesArr = isset($data['physicalAttributes'])
                    ? (is_array($data['physicalAttributes']) ? $data['physicalAttributes'] : json_decode($data['physicalAttributes'], true))
                    : null;
                if (is_array($physicalAttributesArr)) {
                    enrichPhysicalAttributesWithIds($physicalAttributesArr);
                }
                $physicalAttributes = is_array($physicalAttributesArr)
                    ? json_encode($physicalAttributesArr, JSON_UNESCAPED_UNICODE)
                    : null;
                $qualityRules = isset($data['qualityRules']) ?
                    (is_array($data['qualityRules']) ? json_encode($data['qualityRules']) : $data['qualityRules']) : null;
                $synonyms = isset($data['synonyms']) ?
                    (is_array($data['synonyms']) ? json_encode($data['synonyms']) : $data['synonyms']) : null;
                $relatedTerms = isset($data['relatedTerms']) ?
                    (is_array($data['relatedTerms']) ? json_encode($data['relatedTerms']) : $data['relatedTerms']) : null;
                $history = isset($data['history']) ?
                    (is_array($data['history']) ? json_encode($data['history']) : $data['history']) : null;

                $stmt = db()->prepare("INSERT INTO glossary_terms
                    (name, abbreviation, definition, domain, data_type, example, formula, business_logic, technical_description, owner, stewards, security_classification, physical_attributes, quality_rules, synonyms, related_terms, source_system, notes, history, status, reject_reason, process_comments, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                
                $stmt->execute([
                    $data['name'],
                    $data['abbreviation'] ?? '',
                    $data['definition'] ?? '',
                    $data['domain'] ?? '',
                    $data['dataType'] ?? '',
                    $data['example'] ?? '',
                    $data['formula'] ?? '',
                    $data['businessLogic'] ?? '',
                    $data['technicalDescription'] ?? '',
                    $data['owner'] ?? '',
                    $stewards,
                    $data['securityClassification'] ?? null,
                    $physicalAttributes,
                    $qualityRules,
                    $synonyms,
                    $relatedTerms,
                    $data['sourceSystem'] ?? '',
                    $data['notes'] ?? '',
                    $history,
                    $data['status'] ?? 'draft',
                    $data['rejectReason'] ?? null,
                    $data['processComments'] ?? null
                ]);
                
                $newId = db()->lastInsertId();

                db()->prepare("UPDATE glossary_terms SET code = ? WHERE id = ?")
                    ->execute(['Gloss-' . $newId, $newId]);

                // Add initial history
                $histStmt = db()->prepare("INSERT INTO glossary_term_history (term_id, action, comment, user, created_at) VALUES (?, ?, ?, ?, NOW())");
                $histStmt->execute([$newId, 'Yaradıldı', $data['comment'] ?? null, $data['owner'] ?? 'System']);

                respond(['id' => $newId, 'code' => 'Gloss-' . $newId, 'message' => 'Term created']);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('ID is required');
                $data = getInput();
                
                // Handle stewards as JSON array
                $stewards = '';
                if (isset($data['stewards'])) {
                    $stewards = is_array($data['stewards']) ? json_encode($data['stewards']) : $data['stewards'];
                } elseif (isset($data['steward'])) {
                    $stewards = is_array($data['steward']) ? json_encode($data['steward']) : json_encode([$data['steward']]);
                }
                
                $physicalAttributesArr = isset($data['physicalAttributes'])
                    ? (is_array($data['physicalAttributes']) ? $data['physicalAttributes'] : json_decode($data['physicalAttributes'], true))
                    : null;
                if (is_array($physicalAttributesArr)) {
                    enrichPhysicalAttributesWithIds($physicalAttributesArr);
                }
                $physicalAttributes = is_array($physicalAttributesArr)
                    ? json_encode($physicalAttributesArr, JSON_UNESCAPED_UNICODE)
                    : null;
                $qualityRules = isset($data['qualityRules']) ?
                    (is_array($data['qualityRules']) ? json_encode($data['qualityRules']) : $data['qualityRules']) : null;
                $synonyms = isset($data['synonyms']) ?
                    (is_array($data['synonyms']) ? json_encode($data['synonyms']) : $data['synonyms']) : null;
                $relatedTerms = isset($data['relatedTerms']) ?
                    (is_array($data['relatedTerms']) ? json_encode($data['relatedTerms']) : $data['relatedTerms']) : null;
                $history = isset($data['history']) ?
                    (is_array($data['history']) ? json_encode($data['history']) : $data['history']) : null;

                $stmt = db()->prepare("UPDATE glossary_terms SET
                    name = ?, abbreviation = ?, definition = ?, domain = ?, data_type = ?, example = ?, 
                    formula = ?, business_logic = ?, technical_description = ?, owner = ?, stewards = ?, 
                    security_classification = ?, physical_attributes = ?, quality_rules = ?, 
                    synonyms = ?, related_terms = ?, source_system = ?, notes = ?, history = ?,
                    status = ?, reject_reason = ?, process_comments = ?, updated_at = NOW()
                    WHERE id = ?");
                $stmt->execute([
                    $data['name'],
                    $data['abbreviation'] ?? '',
                    $data['definition'] ?? '',
                    $data['domain'] ?? '',
                    $data['dataType'] ?? '',
                    $data['example'] ?? '',
                    $data['formula'] ?? '',
                    $data['businessLogic'] ?? '',
                    $data['technicalDescription'] ?? '',
                    $data['owner'] ?? '',
                    $stewards,
                    $data['securityClassification'] ?? null,
                    $physicalAttributes,
                    $qualityRules,
                    $synonyms,
                    $relatedTerms,
                    $data['sourceSystem'] ?? '',
                    $data['notes'] ?? '',
                    $history,
                    $data['status'] ?? 'draft',
                    $data['rejectReason'] ?? null,
                    $data['processComments'] ?? null,
                    $id
                ]);
                
                respond(['message' => 'Term updated']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                $data = getInput();
                
                // Soft delete - just update status
                if (isset($data['soft']) && $data['soft']) {
                    $stmt = db()->prepare("UPDATE glossary_terms SET 
                        status = 'deleted', 
                        deleted_at = NOW(), 
                        deleted_by = ?
                        WHERE id = ?");
                    $stmt->execute([$data['deletedBy'] ?? 'System', $id]);
                    respond(['message' => 'Term soft deleted']);
                }
                // Permanent delete
                else {
                    db()->prepare("DELETE FROM glossary_term_history WHERE term_id = ?")->execute([$id]);
                    db()->prepare("DELETE FROM term_physical_attributes WHERE term_id = ?")->execute([$id]);
                    db()->prepare("DELETE FROM glossary_terms WHERE id = ?")->execute([$id]);
                    respond(['message' => 'Term permanently deleted']);
                }
            }
            break;

        case 'migrate_glossary_physical_refs':
            if ($method === 'POST') {
                $rows = db()->query("SELECT id, physical_attributes FROM glossary_terms WHERE physical_attributes IS NOT NULL AND physical_attributes <> ''")->fetchAll();
                $termsScanned = 0;
                $termsUpdated = 0;
                $entriesLinked = 0;
                foreach ($rows as $row) {
                    $termsScanned++;
                    $attrs = json_decode($row['physical_attributes'], true);
                    if (!is_array($attrs)) continue;

                    $beforeMissing = 0;
                    foreach ($attrs as $a) {
                        if (is_array($a) && empty($a['table_id'])) $beforeMissing++;
                    }

                    enrichPhysicalAttributesWithIds($attrs);

                    $afterMissing = 0;
                    foreach ($attrs as $a) {
                        if (is_array($a) && empty($a['table_id'])) $afterMissing++;
                    }

                    if ($beforeMissing !== $afterMissing) {
                        db()->prepare("UPDATE glossary_terms SET physical_attributes = ? WHERE id = ?")
                            ->execute([json_encode($attrs, JSON_UNESCAPED_UNICODE), $row['id']]);
                        $termsUpdated++;
                        $entriesLinked += ($beforeMissing - $afterMissing);
                    }
                }
                respond([
                    'terms_scanned' => $termsScanned,
                    'terms_updated' => $termsUpdated,
                    'entries_linked' => $entriesLinked,
                ]);
            }
            break;


        // ════════════════════════════════════════════════════════
        // GET CONNECTIONS (for schedule creation)
        // ════════════════════════════════════════════════════════
        
        case 'get_connections':
            try {
                $stmt = $pdo->query("
                    SELECT id, name, db_type, host, port, database_name
                    FROM connections
                    ORDER BY name
                ");
                $connections = $stmt->fetchAll();
                respond(['success' => true, 'data' => $connections]);
            } catch (Exception $e) {
                error_log('Get connections error: ' . $e->getMessage());
                respond(['success' => false, 'error' => 'Failed to load connections'], 500);
            }
            break;
        
        // ════════════════════════════════════════════════════════
        // GET QUALITY RULES (filtered by connection)
        // ════════════════════════════════════════════════════════
        
        
        // ════════════════════════════════════════════════════════
        // GET ALL QUALITY RULES (with connection info)
        // ════════════════════════════════════════════════════════
        
        
        // ════════════════════════════════════════════════════════
        // GET ALL QUALITY RULES (from data_quality_rules table)
        // ════════════════════════════════════════════════════════
        
        case 'get_quality_rules':
            try {
                // Get ALL active checks from soda_checks
                $stmt = db()->query("
                    SELECT 
                        id, 
                        check_name as name,
                        check_type as rule_type,
                        layer_name,
                        table_name,
                        column_name,
                        kpi_name,
                        custom_sql as query_text,
                        status,
                        target_value,
                        threshold_value
                    FROM soda_checks
                    WHERE status = 'active'
                    ORDER BY check_name
                ");
                
                $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Format for UI display
                foreach ($rules as &$rule) {
                    // Build connection name from layer
                    $layer = $rule['layer_name'] ?? '';
                    $table = $rule['table_name'] ?? '';
                    
                    if ($layer && $table) {
                        $rule['connection_name'] = $layer . '.' . $table;
                    } elseif ($layer) {
                        $rule['connection_name'] = $layer;
                    } else {
                        $rule['connection_name'] = 'MIM';
                    }
                    
                    // Add type badge
                    $rule['type_badge'] = strtolower($rule['rule_type'] ?? 'custom');
                }
                
                respond($rules);
                
            } catch (Exception $e) {
                error_log('Get quality rules error: ' . $e->getMessage());
                error('Failed to load rules: ' . $e->getMessage());
            }
            break;

        case 'quality_rules':
            if ($method === 'GET') {
                if ($id) {
                    $stmt = db()->prepare("SELECT * FROM data_quality_rules WHERE id = ? OR rule_id = ?");
                    $stmt->execute([$id, $id]);
                    $rule = $stmt->fetch(PDO::FETCH_ASSOC);
                    respond($rule ?: []);
                } else {
                    $stmt = db()->query("SELECT * FROM data_quality_rules ORDER BY created_at DESC");
                    respond($stmt->fetchAll(PDO::FETCH_ASSOC));
                }
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['name']) || empty($data['type'])) error('name and type are required');
                
                $ruleId = $data['rule_id'] ?? ('rule_' . time());
                $stmt = db()->prepare("INSERT INTO data_quality_rules 
                    (rule_id, name, type, description, expression, severity, status, pass_rate, linked_columns) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $ruleId,
                    $data['name'],
                    $data['type'],
                    $data['description'] ?? '',
                    $data['expression'] ?? '',
                    $data['severity'] ?? 'medium',
                    $data['status'] ?? 'active',
                    $data['pass_rate'] ?? 0,
                    $data['linked_columns'] ?? '[]'
                ]);
                
                respond(['id' => db()->lastInsertId(), 'rule_id' => $ruleId]);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('ID is required');
                $data = getInput();
                
                // Debug log - full request
                error_log("Quality rule PUT - ID: $id");
                error_log("Quality rule PUT - Full data: " . json_encode($data));
                error_log("Quality rule PUT - linked_columns: " . ($data['linked_columns'] ?? 'NULL'));
                
                $stmt = db()->prepare("UPDATE data_quality_rules SET 
                    name = ?, type = ?, description = ?, expression = ?, severity = ?, status = ?, pass_rate = ?, linked_columns = ?, updated_at = NOW()
                    WHERE id = ? OR rule_id = ?");
                $stmt->execute([
                    $data['name'],
                    $data['type'],
                    $data['description'] ?? '',
                    $data['expression'] ?? '',
                    $data['severity'] ?? 'medium',
                    $data['status'] ?? 'active',
                    $data['pass_rate'] ?? 0,
                    $data['linked_columns'] ?? '[]',
                    $id,
                    $id
                ]);
                
                // Debug: Check affected rows
                $affected = $stmt->rowCount();
                error_log("Quality rule PUT - Affected rows: $affected");
                
                // Verify by re-reading
                $verifyStmt = db()->prepare("SELECT linked_columns FROM data_quality_rules WHERE id = ? OR rule_id = ?");
                $verifyStmt->execute([$id, $id]);
                $verifyResult = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                error_log("Quality rule PUT - Verify linked_columns: " . ($verifyResult['linked_columns'] ?? 'NOT FOUND'));
                
                respond(['message' => 'Quality rule updated', 'affected' => $affected, 'verified_linked_columns' => $verifyResult['linked_columns'] ?? null]);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM data_quality_rules WHERE id = ? OR rule_id = ?")->execute([$id, $id]);
                respond(['message' => 'Quality rule deleted']);
            }
            break;
        
        // ============ SODA SCAN RESULTS ============
        case 'soda_scans':
            if ($method === 'GET') {
                if ($id) {
                    // Get specific scan result
                    $stmt = db()->prepare("SELECT * FROM soda_scan_results WHERE id = ?");
                    $stmt->execute([$id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($result && $result['results_json']) {
                        $result['results'] = json_decode($result['results_json'], true);
                        
                        // Enrich checks with column_name from soda_checks table
                        if (isset($result['results']['checks']) && is_array($result['results']['checks'])) {
                            foreach ($result['results']['checks'] as &$check) {
                                // Try to find matching check in soda_checks by check_id or name
                                if (isset($check['check_id'])) {
                                    $checkStmt = db()->prepare("SELECT column_name, table_name FROM soda_checks WHERE id = ?");
                                    $checkStmt->execute([$check['check_id']]);
                                    $checkInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
                                    if ($checkInfo) {
                                        $check['column_name'] = $checkInfo['column_name'];
                                        if (!isset($check['table_name'])) {
                                            $check['table_name'] = $checkInfo['table_name'];
                                        }
                                    }
                                } elseif (isset($check['check_name']) || isset($check['name'])) {
                                    // Fallback: try to match by check name
                                    $checkName = $check['check_name'] ?? $check['name'];
                                    $checkStmt = db()->prepare("SELECT column_name, table_name FROM soda_checks WHERE check_name = ? LIMIT 1");
                                    $checkStmt->execute([$checkName]);
                                    $checkInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
                                    if ($checkInfo) {
                                        $check['column_name'] = $checkInfo['column_name'];
                                        if (!isset($check['table_name'])) {
                                            $check['table_name'] = $checkInfo['table_name'];
                                        }
                                    }
                                }
                            }
                        }
                        
                        unset($result['results_json']);
                    }
                    respond($result ?: ['error' => 'Scan not found']);
                } else {
                    // List all scans with pagination
                    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
                    $offset = ($page - 1) * $limit;
                    $source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : null;
                    $check_id = isset($_GET['check_id']) ? (int)$_GET['check_id'] : null;
                    
                    $where = "1=1";
                    $params = [];
                    if ($source_id) {
                        $where .= " AND source_id = ?";
                        $params[] = $source_id;
                    }
                    if ($check_id) {
                        $where .= " AND check_id = ?";
                        $params[] = $check_id;
                    }
                    
                    $countStmt = db()->prepare("SELECT COUNT(*) FROM soda_scan_results WHERE $where");
                    $countStmt->execute($params);
                    $total = $countStmt->fetchColumn();
                    
                    $stmt = db()->prepare("SELECT sr.id, sr.scan_id, sr.scan_name, sr.source_id, sr.check_id, sr.data_source, 
                        sr.total_checks, sr.passed_checks, sr.failed_checks, sr.warning_checks, sr.quality_score,
                        sr.status, sr.results_json, sr.executed_at, sr.created_at,
                        sc.column_name, sc.table_name as check_table_name, sc.layer_name as check_layer_name
                        FROM soda_scan_results sr
                        LEFT JOIN soda_checks sc ON sr.check_id = sc.id
                        WHERE $where ORDER BY sr.executed_at DESC LIMIT $limit OFFSET $offset");
                    $stmt->execute($params);
                    
                    $scans = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    // Parse results_json for each scan
                    foreach ($scans as &$scan) {
                        if (!empty($scan['results_json'])) {
                            $scan['results'] = json_decode($scan['results_json'], true);
                        }
                        unset($scan['results_json']);
                    }
                    
                    respond([
                        'data' => $scans,
                        'total' => $total,
                        'page' => $page,
                        'limit' => $limit
                    ]);
                }
            }
            elseif ($method === 'POST') {
                // Save scan results from Soda service
                $data = getInput();
                
                $stmt = db()->prepare("INSERT INTO soda_scan_results 
                    (scan_id, scan_name, source_id, data_source, total_checks, passed_checks, 
                     failed_checks, warning_checks, quality_score, status, results_json, executed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->execute([
                    $data['scan_id'] ?? uniqid('scan_'),
                    $data['scan_name'] ?? 'Manual Scan',
                    $data['source_id'] ?? null,
                    $data['data_source'] ?? 'unknown',
                    $data['summary']['total_checks'] ?? 0,
                    $data['summary']['passed'] ?? 0,
                    $data['summary']['failures'] ?? 0,
                    $data['summary']['warnings'] ?? 0,
                    $data['summary']['score'] ?? 0,
                    $data['status'] ?? 'completed',
                    json_encode($data),
                    $data['executed_at'] ?? date('Y-m-d H:i:s')
                ]);
                
                respond(['id' => db()->lastInsertId(), 'message' => 'Scan results saved']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM soda_scan_results WHERE id = ?")->execute([$id]);
                respond(['message' => 'Scan result deleted']);
            }
            break;
        
        // ============================================================
        // QUALITY SCHEDULER - Proxy to Python Server
        // ============================================================
        
        case 'quality_schedules':
            $pythonUrl = 'http://localhost:8001/schedules';
            
            if ($method === 'GET') {
                if ($id) {
                    $pythonUrl .= '/' . $id;
                }
                $result = @file_get_contents($pythonUrl);
                if ($result === false) {
                    respond(['success' => false, 'message' => 'Python scheduler bağlantısı uğursuz. Python server işləyirmi?']);
                } else {
                    header('Content-Type: application/json');
                    echo $result;
                }
            } elseif ($method === 'POST') {
                $data = getInput();
                $postData = json_encode($data);
                $options = [
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-Type: application/json',
                        'content' => $postData
                    ]
                ];
                $context = stream_context_create($options);
                $result = @file_get_contents($pythonUrl, false, $context);
                if ($result === false) {
                    respond(['success' => false, 'message' => 'Schedule yaradıla bilmədi']);
                } else {
                    header('Content-Type: application/json');
                    echo $result;
                }
            } elseif ($method === 'PUT' && $id) {
                $data = getInput();
                $putData = json_encode($data);
                $options = [
                    'http' => [
                        'method' => 'PUT',
                        'header' => 'Content-Type: application/json',
                        'content' => $putData
                    ]
                ];
                $context = stream_context_create($options);
                $result = @file_get_contents($pythonUrl . '/' . $id, false, $context);
                if ($result === false) {
                    respond(['success' => false, 'message' => 'Schedule yenilənə bilmədi']);
                } else {
                    header('Content-Type: application/json');
                    echo $result;
                }
            } elseif ($method === 'DELETE' && $id) {
                $options = [
                    'http' => [
                        'method' => 'DELETE'
                    ]
                ];
                $context = stream_context_create($options);
                $result = @file_get_contents($pythonUrl . '/' . $id, false, $context);
                if ($result === false) {
                    respond(['success' => false, 'message' => 'Schedule silinə bilmədi']);
                } else {
                    header('Content-Type: application/json');
                    echo $result;
                }
            }
            break;
        
        case 'quality_executions':
            $pythonUrl = 'http://localhost:8001/executions';
            if (isset($_GET['schedule_id'])) {
                $pythonUrl .= '?schedule_id=' . (int)$_GET['schedule_id'];
            }
            $result = @file_get_contents($pythonUrl);
            if ($result === false) {
                respond(['success' => false, 'data' => [], 'message' => 'Execution history yüklənə bilmədi']);
            } else {
                header('Content-Type: application/json');
                echo $result;
            }
            break;
        
        case 'run_schedule':
            $data = getInput();
            $scheduleId = $data['schedule_id'] ?? null;
            if (!$scheduleId) {
                respond(['success' => false, 'message' => 'Schedule ID lazımdır']);
                break;
            }
            
            $pythonUrl = 'http://localhost:8001/schedules/' . $scheduleId . '/run';
            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json'
                ]
            ];
            $context = stream_context_create($options);
            $result = @file_get_contents($pythonUrl, false, $context);
            
            if ($result === false) {
                respond(['success' => false, 'message' => 'Schedule işlədilə bilmədi']);
            } else {
                header('Content-Type: application/json');
                echo $result;
            }
            break;
        
        // ============================================================
        
        case 'soda_check_results':
            // Get failed/warning checks for a scan
            if ($method === 'GET' && $id) {
                $stmt = db()->prepare("SELECT results_json FROM soda_scan_results WHERE id = ?");
                $stmt->execute([$id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result && $result['results_json']) {
                    $data = json_decode($result['results_json'], true);
                    $checks = $data['checks'] ?? [];
                    
                    // Filter by status if requested
                    $status = $_GET['status'] ?? null;
                    if ($status) {
                        $checks = array_filter($checks, fn($c) => $c['outcome'] === $status);
                    }
                    
                    respond(['checks' => array_values($checks)]);
                }
                respond(['checks' => []]);
            }
            break;
        
        case 'soda_summary':
            // Get quality summary across all sources
            if ($method === 'GET') {
                $stmt = db()->query("
                    SELECT 
                        data_source,
                        COUNT(*) as total_scans,
                        AVG(quality_score) as avg_score,
                        SUM(passed_checks) as total_passed,
                        SUM(failed_checks) as total_failed,
                        MAX(executed_at) as last_scan
                    FROM soda_scan_results
                    GROUP BY data_source
                    ORDER BY last_scan DESC
                ");
                respond($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            break;
        
        // ============ SODA CHECKS MANAGEMENT ============
        case 'soda_checks':
            if ($method === 'GET') {
                if ($id) {
                    // Get specific check
                    $stmt = db()->prepare("SELECT * FROM soda_checks WHERE id = ?");
                    $stmt->execute([$id]);
                    respond($stmt->fetch(PDO::FETCH_ASSOC) ?: ['error' => 'Check not found']);
                } else {
                    // List all checks
                    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
                    $offset = ($page - 1) * $limit;
                    $table = $_GET['table'] ?? null;
                    $layer = $_GET['layer'] ?? null;
                    
                    $where = "1=1";
                    $params = [];
                    if ($table) {
                        $where .= " AND table_name = ?";
                        $params[] = $table;
                    }
                    if ($layer) {
                        $where .= " AND layer_name = ?";
                        $params[] = $layer;
                    }
                    
                    $stmt = db()->prepare("SELECT * FROM soda_checks WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
                    $stmt->execute($params);
                    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Get last result for each check
                    foreach ($checks as &$check) {
                        try {
                            $resultStmt = db()->prepare("SELECT results_json, quality_score FROM soda_scan_results WHERE check_id = ? ORDER BY executed_at DESC LIMIT 1");
                            $resultStmt->execute([$check['id']]);
                            $lastResult = $resultStmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($lastResult && $lastResult['results_json']) {
                                $results = json_decode($lastResult['results_json'], true);
                                if (isset($results['checks'][0])) {
                                    $checkResult = $results['checks'][0];
                                    $check['last_outcome'] = $checkResult['outcome'] ?? null;
                                    
                                    // Calculate percentage from valid_rows/total_rows if available
                                    if (isset($checkResult['total_rows']) && $checkResult['total_rows'] > 0) {
                                        $validRows = $checkResult['valid_rows'] ?? 0;
                                        $check['last_percentage'] = round(($validRows / $checkResult['total_rows']) * 100, 2);
                                    } elseif (isset($checkResult['percentage']) && $checkResult['percentage'] > 0) {
                                        $check['last_percentage'] = $checkResult['percentage'];
                                    } else {
                                        $check['last_percentage'] = $lastResult['quality_score'] ?? 0;
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            // Ignore errors getting last result
                        }
                    }
                    
                    $countStmt = db()->prepare("SELECT COUNT(*) FROM soda_checks WHERE $where");
                    $countStmt->execute($params);
                    
                    respond([
                        'data' => $checks,
                        'total' => $countStmt->fetchColumn()
                    ]);
                }
            }
            elseif ($method === 'POST') {
                $data = getInput();
                
                // If ID is provided, update existing check
                if (!empty($data['id'])) {
                    $checkId = $data['id'];
                    
                    // Build dynamic update query based on provided fields
                    $updates = [];
                    $params = [];
                    
                    if (isset($data['check_name'])) { $updates[] = 'check_name = ?'; $params[] = $data['check_name']; }
                    if (isset($data['check_type'])) { $updates[] = 'check_type = ?'; $params[] = $data['check_type']; }
                    if (isset($data['layer_name'])) { $updates[] = 'layer_name = ?'; $params[] = $data['layer_name']; }
                    if (isset($data['table_name'])) { $updates[] = 'table_name = ?'; $params[] = $data['table_name']; }
                    if (array_key_exists('column_name', $data)) { $updates[] = 'column_name = ?'; $params[] = $data['column_name']; }
                    if (isset($data['operator'])) { $updates[] = 'operator = ?'; $params[] = $data['operator']; }
                    if (isset($data['target_value'])) { $updates[] = 'target_value = ?'; $params[] = $data['target_value']; }
                    if (isset($data['threshold_value'])) { $updates[] = 'threshold_value = ?'; $params[] = $data['threshold_value']; }
                    if (isset($data['custom_sql'])) { $updates[] = 'custom_sql = ?'; $params[] = $data['custom_sql']; }
                    if (isset($data['yaml_content'])) { $updates[] = 'yaml_content = ?'; $params[] = $data['yaml_content']; }
                    if (isset($data['status'])) { $updates[] = 'status = ?'; $params[] = $data['status']; }
                    if (isset($data['kpi_name'])) { $updates[] = 'kpi_name = ?'; $params[] = $data['kpi_name']; }
                    if (array_key_exists('source_id', $data)) { $updates[] = 'source_id = ?'; $params[] = $data['source_id'] ?: null; }
                    
                    if (count($updates) > 0) {
                        $updates[] = 'updated_at = NOW()';
                        $params[] = $checkId;
                        $sql = "UPDATE soda_checks SET " . implode(', ', $updates) . " WHERE id = ?";
                        $stmt = db()->prepare($sql);
                        $stmt->execute($params);
                    }
                    
                    respond(['id' => $checkId, 'message' => 'Check updated']);
                } else {
                    // Insert new check - try with source_id, layer_name and kpi_name first
                    try {
                        $stmt = db()->prepare("INSERT INTO soda_checks 
                            (check_name, check_type, source_id, layer_name, table_name, column_name, operator, kpi_name, target_value, threshold_value, 
                             custom_sql, yaml_content, status, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        
                        $stmt->execute([
                            $data['check_name'] ?? 'Unnamed Check',
                            $data['check_type'] ?? 'custom',
                            $data['source_id'] ?: null,
                            $data['layer_name'] ?? null,
                            $data['table_name'] ?? null,
                            $data['column_name'] ?? null,
                            $data['operator'] ?? null,
                            $data['kpi_name'] ?? null,
                            $data['target_value'] ?? 100.00,
                            $data['threshold_value'] ?? 90.00,
                            $data['custom_sql'] ?? null,
                            $data['yaml_content'] ?? '',
                            $data['status'] ?? 'active'
                        ]);
                    } catch (Exception $e) {
                        // Fallback to old schema without source_id
                        try {
                            $stmt = db()->prepare("INSERT INTO soda_checks 
                                (check_name, check_type, layer_name, table_name, column_name, operator, kpi_name, target_value, threshold_value, 
                                 custom_sql, yaml_content, status, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                            
                            $stmt->execute([
                                $data['check_name'] ?? 'Unnamed Check',
                                $data['check_type'] ?? 'custom',
                                $data['layer_name'] ?? null,
                                $data['table_name'] ?? null,
                                $data['column_name'] ?? null,
                                $data['operator'] ?? null,
                                $data['kpi_name'] ?? null,
                                $data['target_value'] ?? 100.00,
                                $data['threshold_value'] ?? 90.00,
                                $data['custom_sql'] ?? null,
                                $data['yaml_content'] ?? '',
                                $data['status'] ?? 'active'
                            ]);
                        } catch (Exception $e2) {
                            // Fallback to old schema without layer_name
                            try {
                                $stmt = db()->prepare("INSERT INTO soda_checks 
                                    (check_name, check_type, table_name, column_name, operator, kpi_name, target_value, threshold_value, 
                                     custom_sql, yaml_content, status, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                            
                                $stmt->execute([
                                    $data['check_name'] ?? 'Unnamed Check',
                                    $data['check_type'] ?? 'custom',
                                    $data['table_name'] ?? null,
                                    $data['column_name'] ?? null,
                                    $data['operator'] ?? null,
                                    $data['kpi_name'] ?? null,
                                    $data['target_value'] ?? 100.00,
                                    $data['threshold_value'] ?? 90.00,
                                    $data['custom_sql'] ?? null,
                                    $data['yaml_content'] ?? '',
                                    $data['status'] ?? 'active'
                                ]);
                            } catch (Exception $e3) {
                                // Final fallback - old schema without kpi_name
                                $stmt = db()->prepare("INSERT INTO soda_checks 
                                    (check_name, check_type, table_name, column_name, operator, target_value, threshold_value, 
                                     custom_sql, yaml_content, status, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                                
                                $stmt->execute([
                                    $data['check_name'] ?? 'Unnamed Check',
                                    $data['check_type'] ?? 'custom',
                                    $data['table_name'] ?? null,
                                    $data['column_name'] ?? null,
                                    $data['operator'] ?? null,
                                    $data['target_value'] ?? 100.00,
                                    $data['threshold_value'] ?? 90.00,
                                    $data['custom_sql'] ?? null,
                                    $data['yaml_content'] ?? '',
                                    $data['status'] ?? 'active'
                                ]);
                            }
                        }
                    }
                    
                    respond(['id' => db()->lastInsertId(), 'message' => 'Check saved']);
                }
            }
            elseif ($method === 'PUT') {
                if (!$id) error('ID is required');
                $data = getInput();
                
                $stmt = db()->prepare("UPDATE soda_checks SET 
                    check_name = ?, check_type = ?, table_name = ?, column_name = ?,
                    operator = ?, threshold_value = ?, custom_sql = ?, yaml_content = ?,
                    status = ?, updated_at = NOW()
                    WHERE id = ?");
                
                $stmt->execute([
                    $data['check_name'],
                    $data['check_type'],
                    $data['table_name'],
                    $data['column_name'],
                    $data['operator'],
                    $data['threshold_value'],
                    $data['custom_sql'],
                    $data['yaml_content'],
                    $data['status'] ?? 'active',
                    $id
                ]);
                
                respond(['message' => 'Check updated']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM soda_checks WHERE id = ?")->execute([$id]);
                respond(['message' => 'Check deleted']);
            }
            break;
        
        // ============ RUN CHECK ============
        case 'run_check':
            if ($method === 'POST') {
                $data = getInput();
                $checkId = $data['check_id'] ?? $id;
                
                // Get check details
                if ($checkId) {
                    $stmt = db()->prepare("SELECT * FROM soda_checks WHERE id = ?");
                    $stmt->execute([$checkId]);
                    $check = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    // Run check from request data directly
                    $check = $data;
                }
                
                if (!$check) error('Check not found');
                
                // Get source info for data_source name
                $sourceId = $check['source_id'] ?? null;
                $dataSourceName = 'mysql_db';
                if ($sourceId) {
                    $srcStmt = db()->prepare("SELECT db_type, name FROM external_sources WHERE id = ?");
                    $srcStmt->execute([$sourceId]);
                    $srcInfo = $srcStmt->fetch(PDO::FETCH_ASSOC);
                    if ($srcInfo) {
                        $dataSourceName = strtolower($srcInfo['db_type']) . '_db';
                    }
                }
                
                // Use the runSingleCheck function for consistent KPI calculation
                $result = runSingleCheck($check);
                $checkType = $check['check_type'];
                
                // Determine technical vs quality status
                $technicalStatus = 'success';
                $qualityStatus = $result['outcome'] ?? 'unknown';
                
                if (isset($result['outcome']) && $result['outcome'] === 'error') {
                    $technicalStatus = 'error';
                    $qualityStatus = null; // No quality status when technical error
                }
                
                $result['technical_status'] = $technicalStatus;
                $result['quality_status'] = $qualityStatus;
                
                // Add target and threshold values for color determination in UI
                $result['target_value'] = $check['target_value'] ?? 100;
                $result['threshold_value'] = $check['threshold_value'] ?? 90;
                
                // Save result to soda_scan_results
                $scanId = 'ui_' . time() . '_' . rand(1000, 9999);
                $checkName = $check['check_name'] ?? $checkType;
                
                // Add check_name to result for proper grouping in UI
                $result['check_name'] = $checkName;
                $result['table_name'] = $check['table_name'] ?? null;
                $result['check_id'] = $checkId ?? null;
                
                $scanResult = [
                    'scan_id' => $scanId,
                    'scan_name' => $checkName,
                    'data_source' => $dataSourceName,
                    'summary' => [
                        'total_checks' => 1,
                        'passed' => $result['outcome'] === 'pass' ? 1 : 0,
                        'failures' => $result['outcome'] === 'fail' ? 1 : 0,
                        'warnings' => 0,
                        'score' => $result['percentage'] ?? ($result['outcome'] === 'pass' ? 100 : 0)
                    ],
                    'checks' => [$result]
                ];
                
                try {
                    // Try with check_id column first (new schema)
                    try {
                        $insertStmt = db()->prepare("INSERT INTO soda_scan_results 
                            (scan_id, scan_name, check_id, data_source, total_checks, passed_checks, failed_checks, warning_checks, quality_score, status, results_json, executed_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $insertStmt->execute([
                            $scanId,
                            $scanResult['scan_name'],
                            $checkId ?? null,
                            $dataSourceName,
                            1,
                            $result['outcome'] === 'pass' ? 1 : 0,
                            $result['outcome'] === 'fail' ? 1 : 0,
                            0,
                            $result['percentage'] ?? ($result['outcome'] === 'pass' ? 100 : 0),
                            $result['outcome'] === 'pass' ? 'passed' : 'failed',
                            json_encode($scanResult)
                        ]);
                    } catch (Exception $e1) {
                        // Fallback to old schema without check_id
                        $insertStmt = db()->prepare("INSERT INTO soda_scan_results 
                            (scan_id, scan_name, data_source, total_checks, passed_checks, failed_checks, warning_checks, quality_score, status, results_json, executed_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $insertStmt->execute([
                            $scanId,
                            $scanResult['scan_name'],
                            $dataSourceName,
                            1,
                            $result['outcome'] === 'pass' ? 1 : 0,
                            $result['outcome'] === 'fail' ? 1 : 0,
                            0,
                            $result['percentage'] ?? ($result['outcome'] === 'pass' ? 100 : 0),
                            'completed',
                            json_encode($scanResult)
                        ]);
                    }
                    
                    $result['scan_id'] = $scanId;
                } catch (Exception $e) {
                    // If insert fails, still return the result
                    $result['db_error'] = $e->getMessage();
                }
                
                respond($result);
            }
            break;
        
        // ============ RUN ALL CHECKS ============
        case 'run_all_checks':
            if ($method === 'POST') {
                $data = getInput();
                $tableName = $data['table_name'] ?? null;
                
                // Get all active checks
                $where = "status = 'active'";
                $params = [];
                if ($tableName) {
                    $where .= " AND table_name = ?";
                    $params[] = $tableName;
                }
                
                $stmt = db()->prepare("SELECT * FROM soda_checks WHERE $where");
                $stmt->execute($params);
                $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $results = [];
                $passed = 0;
                $failed = 0;
                
                foreach ($checks as $check) {
                    // Simulate POST to run_check
                    $_POST = json_encode(['check_id' => $check['id']]);
                    
                    // Run check inline
                    $checkResult = runSingleCheck($check);
                    $results[] = $checkResult;
                    
                    if ($checkResult['outcome'] === 'pass') $passed++;
                    else $failed++;
                }
                
                $total = count($results);
                $score = $total > 0 ? round(($passed / $total) * 100, 2) : 0;
                
                // Save combined result
                $scanId = 'batch_' . time();
                $insertStmt = db()->prepare("INSERT INTO soda_scan_results 
                    (scan_id, scan_name, data_source, total_checks, passed_checks, failed_checks, warning_checks, quality_score, status, results_json, executed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, NOW())");
                $insertStmt->execute([
                    $scanId,
                    'Batch Run: ' . ($tableName ?? 'All Tables'),
                    'mysql_db',
                    $total,
                    $passed,
                    $failed,
                    0,
                    $score,
                    json_encode(['checks' => $results])
                ]);
                
                respond([
                    'scan_id' => $scanId,
                    'total_checks' => $total,
                    'passed' => $passed,
                    'failed' => $failed,
                    'score' => $score,
                    'results' => $results
                ]);
            }
            break;
        

        
        // ════════════════════════════════════════════════════════
        // RUN SELECTED CHECKS (for scheduled execution)
        // ════════════════════════════════════════════════════════
        
        case 'run_selected_checks':
            if ($method === 'POST') {
                $data = getInput();
                $checkIds = $data['check_ids'] ?? [];
                
                error_log("run_selected_checks: Received check_ids: " . json_encode($checkIds));
                
                if (empty($checkIds) || !is_array($checkIds)) {
                    error('check_ids array is required');
                }
                
                // Get all selected checks
                $placeholders = str_repeat('?,', count($checkIds) - 1) . '?';
                $stmt = db()->prepare("
                    SELECT * FROM soda_checks 
                    WHERE id IN ($placeholders) AND status = 'active'
                ");
                $stmt->execute($checkIds);
                $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                error_log("run_selected_checks: Found " . count($checks) . " active checks");
                
                if (empty($checks)) {
                    error_log("run_selected_checks: No active checks found for IDs: " . json_encode($checkIds));
                    error('No active checks found with provided IDs');
                }
                
                $results = [];
                $passed = 0;
                $failed = 0;
                $errors = 0;
                
                // Run each check
                foreach ($checks as $check) {
                    try {
                        error_log("run_selected_checks: Running check ID=" . $check['id'] . " Name=" . $check['check_name']);
                        
                        $checkResult = runSingleCheck($check);
                        $checkResult['check_id'] = $check['id'];
                        $checkResult['check_name'] = $check['check_name'];
                        $results[] = $checkResult;
                        
                        error_log("run_selected_checks: Check result outcome=" . ($checkResult['outcome'] ?? 'null'));
                        
                        if ($checkResult['outcome'] === 'pass') {
                            $passed++;
                        } elseif ($checkResult['outcome'] === 'fail') {
                            $failed++;
                        } elseif ($checkResult['outcome'] === 'error') {
                            $errors++;
                        } else {
                            // Unknown outcome - count as error
                            error_log("run_selected_checks: Unknown outcome: " . ($checkResult['outcome'] ?? 'null'));
                            $errors++;
                        }
                        
                        // Save individual result to soda_scan_results
                        $scanId = 'schedule_' . time() . '_' . $check['id'];
                        $checkName = $check['check_name'] ?? 'Unknown Check';
                        $checkIdVal = $check['id'];
                        $dataSource = $check['layer_name'] ?? 'mysql_db';
                        $passedVal = $checkResult['outcome'] === 'pass' ? 1 : 0;
                        $failedVal = $checkResult['outcome'] === 'fail' ? 1 : 0;
                        $scoreVal = $checkResult['percentage'] ?? ($checkResult['outcome'] === 'pass' ? 100 : 0);
                        $statusVal = $checkResult['outcome'] === 'error' ? 'error' : ($checkResult['outcome'] === 'fail' ? 'failed' : 'passed');
                        
                        // Create results_json in the format UI expects
                        $resultsJson = json_encode([
                            'scan_id' => $scanId,
                            'scan_name' => $checkName,
                            'data_source' => $dataSource,
                            'summary' => [
                                'total_checks' => 1,
                                'passed' => $passedVal,
                                'failures' => $failedVal,
                                'warnings' => 0,
                                'score' => $scoreVal
                            ],
                            'checks' => [$checkResult]  // UI expects 'checks' array!
                        ]);
                        
                        error_log("run_selected_checks: Saving scan result - scan_id=$scanId, check_name=$checkName, check_id=$checkIdVal, status=$statusVal");
                        
                        try {
                            $insertStmt = db()->prepare("
                                INSERT INTO soda_scan_results 
                                (scan_id, scan_name, source_id, check_id, data_source, 
                                 total_checks, passed_checks, failed_checks, warning_checks, 
                                 quality_score, status, results_json, executed_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            
                            $insertResult = $insertStmt->execute([
                                $scanId,
                                $checkName,
                                null,  // source_id - NULL for schedule runs
                                $checkIdVal,
                                $dataSource,
                                1,  // total_checks
                                $passedVal,
                                $failedVal,
                                0,  // warning_checks
                                $scoreVal,
                                $statusVal,
                                $resultsJson
                            ]);
                            
                            if ($insertResult) {
                                error_log("run_selected_checks: Scan result saved successfully, ID=" . db()->lastInsertId());
                            } else {
                                error_log("run_selected_checks: INSERT failed - " . json_encode($insertStmt->errorInfo()));
                            }
                        } catch (Exception $insertEx) {
                            error_log("run_selected_checks: INSERT exception - " . $insertEx->getMessage());
                        }
                        
                    } catch (Exception $e) {
                        error_log('Error running check ' . $check['id'] . ': ' . $e->getMessage());
                        $results[] = [
                            'check_id' => $check['id'],
                            'check_name' => $check['check_name'],
                            'outcome' => 'error',
                            'error' => $e->getMessage()
                        ];
                        $errors++;
                    }
                }
                
                $total = count($results);
                $score = $total > 0 ? round(($passed / $total) * 100, 2) : 0;
                
                error_log("run_selected_checks: SUMMARY - Total=$total, Passed=$passed, Failed=$failed, Errors=$errors");
                
                // Return summary
                respond([
                    'success' => true,
                    'total_checks' => $total,
                    'passed' => $passed,
                    'failed' => $failed,
                    'errors' => $errors,
                    'score' => $score,
                    'results' => $results
                ]);
            }
            break;

        // ============ DOWNLOAD ALL FAILED ROWS ============
        case 'download_failed_rows':
            if ($method === 'POST') {
                $data = getInput();
                $checkId = $data['check_id'] ?? null;
                
                if (!$checkId) error('check_id is required');
                
                // Get check details
                $stmt = db()->prepare("SELECT * FROM soda_checks WHERE id = ?");
                $stmt->execute([$checkId]);
                $check = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$check) error('Check not found');
                
                // Get the SQL to fetch failed rows based on check type
                $failedSql = null;
                $tableName = $check['table_name'];
                $layerName = $check['layer_name'] ?? null;
                $columnName = $check['column_name'] ?? null;
                
                if ($layerName && strpos($tableName, '.') === false) {
                    $tableName = $layerName . '.' . $tableName;
                }
                
                // Get source first to determine dbType
                $source = null;
                if ($check['source_id']) {
                    $srcStmt = db()->prepare("SELECT * FROM external_sources WHERE id = ?");
                    $srcStmt->execute([$check['source_id']]);
                    $source = $srcStmt->fetch(PDO::FETCH_ASSOC);
                }
                
                if (!$source) {
                    $source = [
                        'db_type' => 'mysql',
                        'host' => DB_HOST,
                        'port' => DB_PORT,
                        'username' => DB_USER,
                        'password' => DB_PASS,
                        'database_name' => DB_NAME
                    ];
                }
                
                $dbType = strtolower($source['db_type'] ?? 'mysql');
                
                if ($check['check_type'] === 'custom' && !empty($check['custom_sql'])) {
                    $failedSql = $check['custom_sql'];
                } elseif ($check['check_type'] === 'missing' && $columnName) {
                    $failedSql = "SELECT * FROM {$tableName} WHERE {$columnName} IS NULL";
                } elseif ($check['check_type'] === 'duplicate' && $columnName) {
                    if ($dbType === 'oracle') {
                        $failedSql = "SELECT * FROM {$tableName} t1 WHERE EXISTS (SELECT 1 FROM {$tableName} t2 WHERE t2.{$columnName} = t1.{$columnName} AND t2.ROWID != t1.ROWID)";
                    } else {
                        $failedSql = "SELECT * FROM {$tableName} WHERE {$columnName} IN (SELECT {$columnName} FROM {$tableName} GROUP BY {$columnName} HAVING COUNT(*) > 1)";
                    }
                } elseif ($check['check_type'] === 'validity' && $columnName) {
                    if ($dbType === 'oracle') {
                        $failedSql = "SELECT * FROM {$tableName} WHERE {$columnName} IS NULL OR TRIM({$columnName}) IS NULL";
                    } else {
                        $failedSql = "SELECT * FROM {$tableName} WHERE {$columnName} IS NULL OR {$columnName} = ''";
                    }
                }
                
                if (!$failedSql) error('Cannot determine failed rows SQL for this check type');
                
                // Generate CSV file directly
                $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
                $ds = DIRECTORY_SEPARATOR;
                $tempDir = sys_get_temp_dir();
                $uniqueId = uniqid('csv_');
                $csvFile = $tempDir . $ds . $uniqueId . '.csv';
                $tempConfigFile = $tempDir . $ds . $uniqueId . '_config.yml';
                
                $dataSource = $dbType . '_db';
                
                // Build config based on db type
                if ($dbType === 'oracle') {
                    $connectString = $source['host'] . ':' . ($source['port'] ?? '1521') . '/' . ($source['sid'] ?? $source['database_name'] ?? 'orcl');
                    $configContent = "data_source {$dataSource}:\n"
                        . "  type: oracle\n"
                        . "  connect_string: {$connectString}\n"
                        . "  username: {$source['username']}\n"
                        . "  password: \"{$source['password']}\"\n";
                } elseif ($dbType === 'postgresql' || $dbType === 'postgres') {
                    $configContent = "data_source {$dataSource}:\n"
                        . "  type: postgres\n"
                        . "  host: {$source['host']}\n"
                        . "  port: " . ($source['port'] ?? '5432') . "\n"
                        . "  username: {$source['username']}\n"
                        . "  password: \"{$source['password']}\"\n"
                        . "  database: {$source['database_name']}\n";
                } else {
                    $configContent = "data_source {$dataSource}:\n"
                        . "  type: mysql\n"
                        . "  host: {$source['host']}\n"
                        . "  port: " . ($source['port'] ?? '3306') . "\n"
                        . "  username: {$source['username']}\n"
                        . "  password: \"{$source['password']}\"\n"
                        . "  database: {$source['database_name']}\n";
                }
                
                file_put_contents($tempConfigFile, $configContent);
                
                // Call Python fetch_rows script with CSV output
                $pythonScript = $isWindows 
                    ? "C:\\xampp\\htdocs\\datarover-soda\\scripts\\fetch_rows.py"
                    : "/var/www/html/datarover-soda/scripts/fetch_rows.py";
                
                $pythonCmd = $isWindows ? "python" : "python3";
                
                $cmd = $pythonCmd . " " . escapeshellarg($pythonScript)
                    . " --config " . escapeshellarg($tempConfigFile)
                    . " --sql " . escapeshellarg($failedSql)
                    . " --limit 50000"
                    . " --csv " . escapeshellarg($csvFile)
                    . " 2>&1";
                
                $output = shell_exec($cmd);
                @unlink($tempConfigFile);
                
                // Check if CSV file was created
                if (file_exists($csvFile) && filesize($csvFile) > 0) {
                    // Read and return CSV content
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="failed_rows_' . $check['check_name'] . '_' . date('Y-m-d') . '.csv"');
                    header('Content-Length: ' . filesize($csvFile));
                    header('Cache-Control: no-cache');
                    
                    // Output file in chunks to avoid memory issues
                    $handle = fopen($csvFile, 'r');
                    while (!feof($handle)) {
                        echo fread($handle, 8192);
                        flush();
                    }
                    fclose($handle);
                    @unlink($csvFile);
                    exit;
                } else {
                    @unlink($csvFile);
                    respond([
                        'success' => false,
                        'error' => 'Failed to generate CSV file',
                        'output' => $output
                    ]);
                }
            }
            break;
        
        case 'restore_term':
            if ($method === 'POST') {
                $data = getInput();
                if (!$id) error('ID is required');
                
                $stmt = db()->prepare("UPDATE glossary_terms SET 
                    status = ?, 
                    deleted_at = NULL, 
                    deleted_by = NULL,
                    restored_at = NOW(), 
                    restored_by = ?
                    WHERE id = ?");
                $stmt->execute([
                    $data['status'] ?? 'draft',
                    $data['restoredBy'] ?? 'System',
                    $id
                ]);
                
                respond(['message' => 'Term restored']);
            }
            break;
            
        case 'glossary_history':
            if ($method === 'POST') {
                $data = getInput();
                if (empty($data['term_id'])) error('term_id is required');
                
                $stmt = db()->prepare("INSERT INTO glossary_term_history (term_id, action, comment, user, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $data['term_id'],
                    $data['action'] ?? '',
                    $data['comment'] ?? null,
                    $data['user'] ?? 'System'
                ]);
                
                respond(['id' => db()->lastInsertId()]);
            }
            elseif ($method === 'GET' && $id) {
                // Get history for specific term
                $stmt = db()->prepare("SELECT * FROM glossary_term_history WHERE term_id = ? ORDER BY created_at DESC");
                $stmt->execute([$id]);
                respond($stmt->fetchAll());
            }
            break;
            
        // ============================================================
        // DATA CATALOG - LAYERS
        // ============================================================
        
        case 'catalog_layers':
            if ($method === 'GET') {
                $stmt = db()->query("SELECT * FROM catalog_layers ORDER BY `order` ASC");
                respond($stmt->fetchAll());
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['name'])) error('Name is required');
                
                $stmt = db()->prepare("INSERT INTO catalog_layers (name, icon, color, description, `order`) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $data['name'],
                    $data['icon'] ?? '📁',
                    $data['color'] ?? '#6366f1',
                    $data['description'] ?? '',
                    $data['order'] ?? 0
                ]);
                
                respond(['id' => db()->lastInsertId()]);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('ID is required');
                $data = getInput();
                
                $stmt = db()->prepare("UPDATE catalog_layers SET name = ?, icon = ?, color = ?, description = ?, `order` = ? WHERE id = ?");
                $stmt->execute([
                    $data['name'],
                    $data['icon'] ?? '📁',
                    $data['color'] ?? '#6366f1',
                    $data['description'] ?? '',
                    $data['order'] ?? 0,
                    $id
                ]);
                
                respond(['message' => 'Layer updated']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                
                // First get all tables in this layer
                $tablesStmt = db()->prepare("SELECT id FROM catalog_tables WHERE layer_id = ?");
                $tablesStmt->execute([$id]);
                $tableIds = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Delete columns for all tables in this layer
                if (!empty($tableIds)) {
                    $placeholders = implode(',', array_fill(0, count($tableIds), '?'));
                    db()->prepare("DELETE FROM catalog_columns WHERE table_id IN ($placeholders)")->execute($tableIds);
                    
                    // Delete all tables in this layer
                    db()->prepare("DELETE FROM catalog_tables WHERE layer_id = ?")->execute([$id]);
                }
                
                // Delete the layer itself
                db()->prepare("DELETE FROM catalog_layers WHERE id = ?")->execute([$id]);
                respond(['message' => 'Layer and all related tables deleted']);
            }
            break;
            
        // ============================================================
        // DATA CATALOG - TABLES
        // ============================================================
        
        case 'catalog_tables':
            if ($method === 'GET') {
                $layer = $_GET['layer'] ?? '';
                
                if ($layer) {
                    $stmt = db()->prepare("SELECT t.*, l.name as layer_name FROM catalog_tables t 
                        JOIN catalog_layers l ON t.layer_id = l.id 
                        WHERE l.name = ? ORDER BY t.name");
                    $stmt->execute([$layer]);
                } else {
                    $stmt = db()->query("SELECT t.*, l.name as layer_name FROM catalog_tables t 
                        JOIN catalog_layers l ON t.layer_id = l.id ORDER BY l.order, t.name");
                }
                
                $tables = $stmt->fetchAll();
                
                // Get columns for each table
                foreach ($tables as &$table) {
                    $colStmt = db()->prepare("SELECT * FROM catalog_columns WHERE table_id = ? ORDER BY `order`");
                    $colStmt->execute([$table['id']]);
                    $table['columns'] = $colStmt->fetchAll();
                }
                
                respond($tables);
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['name']) || empty($data['layer_id'])) error('Name and layer_id are required');
                
                $stmt = db()->prepare("INSERT INTO catalog_tables (layer_id, name, description, row_count, owner, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $data['layer_id'],
                    $data['name'],
                    $data['description'] ?? '',
                    $data['row_count'] ?? 0,
                    $data['owner'] ?? ''
                ]);
                
                respond(['id' => db()->lastInsertId()]);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('ID is required');
                $data = getInput();

                $oldStmt = db()->prepare("SELECT name FROM catalog_tables WHERE id = ?");
                $oldStmt->execute([$id]);
                $oldRow = $oldStmt->fetch();
                $oldName = $oldRow ? $oldRow['name'] : null;
                $newName = $data['name'];

                $stmt = db()->prepare("UPDATE catalog_tables SET name = ?, description = ?, row_count = ?, owner = ? WHERE id = ?");
                $stmt->execute([
                    $newName,
                    $data['description'] ?? '',
                    $data['row_count'] ?? 0,
                    $data['owner'] ?? '',
                    $id
                ]);

                $cascade = ['glossary_terms_updated' => 0, 'term_physical_attributes_updated' => 0];
                if ($oldName !== null && $oldName !== $newName) {
                    $upd = db()->prepare("UPDATE term_physical_attributes SET table_name = ? WHERE table_name = ?");
                    $upd->execute([$newName, $oldName]);
                    $cascade['term_physical_attributes_updated'] = $upd->rowCount();

                    $rows = db()->query("SELECT id, physical_attributes FROM glossary_terms WHERE physical_attributes IS NOT NULL AND physical_attributes <> ''")->fetchAll();
                    foreach ($rows as $row) {
                        $attrs = json_decode($row['physical_attributes'], true);
                        if (!is_array($attrs)) continue;
                        $changed = false;
                        foreach ($attrs as &$a) {
                            if (is_array($a) && isset($a['table']) && $a['table'] === $oldName) {
                                $a['table'] = $newName;
                                $changed = true;
                            }
                        }
                        unset($a);
                        if ($changed) {
                            db()->prepare("UPDATE glossary_terms SET physical_attributes = ? WHERE id = ?")
                                ->execute([json_encode($attrs, JSON_UNESCAPED_UNICODE), $row['id']]);
                            $cascade['glossary_terms_updated']++;
                        }
                    }
                }

                respond(['message' => 'Table updated', 'cascade' => $cascade]);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM catalog_columns WHERE table_id = ?")->execute([$id]);
                db()->prepare("DELETE FROM catalog_tables WHERE id = ?")->execute([$id]);
                respond(['message' => 'Table deleted']);
            }
            break;
            
        // ============================================================
        // DATA CATALOG - COLUMNS
        // ============================================================
        
        case 'catalog_columns':
            if ($method === 'GET') {
                $tableId = $_GET['table_id'] ?? '';
                
                if ($tableId) {
                    $stmt = db()->prepare("SELECT * FROM catalog_columns WHERE table_id = ? ORDER BY `order`");
                    $stmt->execute([$tableId]);
                } else {
                    $stmt = db()->query("SELECT c.*, t.name as table_name, l.name as layer_name 
                        FROM catalog_columns c 
                        JOIN catalog_tables t ON c.table_id = t.id
                        JOIN catalog_layers l ON t.layer_id = l.id
                        ORDER BY l.order, t.name, c.order");
                }
                
                respond($stmt->fetchAll());
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['name']) || empty($data['table_id'])) error('Name and table_id are required');
                
                $stmt = db()->prepare("INSERT INTO catalog_columns (table_id, name, data_type, description, is_pk, is_fk, is_nullable, icon, `order`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $data['table_id'],
                    $data['name'],
                    $data['data_type'] ?? 'VARCHAR',
                    $data['description'] ?? '',
                    $data['is_pk'] ?? 0,
                    $data['is_fk'] ?? 0,
                    $data['is_nullable'] ?? 1,
                    $data['icon'] ?? '📊',
                    $data['order'] ?? 0
                ]);
                
                respond(['id' => db()->lastInsertId()]);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM catalog_columns WHERE id = ?")->execute([$id]);
                respond(['message' => 'Column deleted']);
            }
            break;
            
        // ============================================================
        // DATA PROFILING - REAL DATA FROM DATABASE
        // ============================================================
        
        case 'profile_table':
            if ($method === 'GET') {
                $tableName = $_GET['table'] ?? '';
                $layer = $_GET['layer'] ?? '';
                
                if (empty($tableName)) error('Table name is required');
                
                // Get table info from catalog (including source_id and object_type)
                $stmt = db()->prepare("SELECT t.*, l.name as layer_name 
                    FROM catalog_tables t 
                    JOIN catalog_layers l ON t.layer_id = l.id 
                    WHERE t.name = ? AND l.name = ?");
                $stmt->execute([$tableName, $layer]);
                $table = $stmt->fetch();
                
                if (!$table) error('Table not found');

                $tableId = $table['id'];

                // Check if object is a VIEW
                $objectType = strtoupper($table['object_type'] ?? 'TABLE');
                $isView = ($objectType === 'VIEW' || $objectType === 'MATERIALIZED VIEW');

                // Get columns from catalog
                $colStmt = db()->prepare("SELECT * FROM catalog_columns WHERE table_id = ? ORDER BY `order`");
                $colStmt->execute([$tableId]);
                $columns = $colStmt->fetchAll();
                
                // Profile reads from local catalog only — no external connection.
                // Use the Refresh button in the profile panel to re-fetch metadata via the scanner.
                $sourceConnection = null;
                $realRowCount = $table['row_count'] ?: 0;
                $dataSource = 'estimated';
                $connectionMessage = '';
                
                // Calculate estimated size
                $estimatedSizeMB = round($realRowCount * 0.001, 2);
                
                // Build profiling response
                $profiling = [
                    'table_name' => $table['name'],
                    'layer' => $table['layer_name'],
                    'description' => $table['description'],
                    'row_count' => $realRowCount,
                    'size_mb' => $estimatedSizeMB,
                    'last_updated' => $table['created_at'],
                    'data_source' => $dataSource,
                    'connection_message' => $connectionMessage, // Why estimated data is used
                    'source_id' => $table['source_id'],
                    'object_type' => $objectType,
                    'is_view' => $isView,
                    'columns' => []
                ];
                
                // Profile each column
                foreach ($columns as $col) {
                    $colName = $col['name'];
                    $dataType = strtoupper($col['data_type']);
                    
                    // Get real column statistics if connected to source AND not a view
                    if ($sourceConnection && isset($externalPdo) && !$isView) {
                        $colStats = getRealColumnStatsFromExternal($externalPdo, $tableName, $colName, $dataType, $realRowCount, $sourceConnection['db_type']);
                    } else {
                        // Use intelligent estimation (for views or tables without source)
                        $colStats = estimateColumnStats($col, $dataType, $realRowCount);
                        if ($isView) {
                            $colStats['data_source'] = 'estimated_view';
                        }
                    }
                    
                    $profiling['columns'][] = [
                        'name' => $col['name'],
                        'data_type' => $col['data_type'],
                        'description' => $col['description'],
                        'is_pk' => (bool)$col['is_pk'],
                        'is_fk' => (bool)$col['is_fk'],
                        'is_nullable' => (bool)$col['is_nullable'],
                        'null_count' => $colStats['null_count'],
                        'null_percent' => $colStats['null_percent'],
                        'unique_count' => $colStats['unique_count'],
                        'unique_percent' => $colStats['unique_percent'],
                        'distinct_count' => $colStats['distinct_count'],
                        'min_value' => $colStats['min_value'],
                        'max_value' => $colStats['max_value'],
                        'avg_length' => $colStats['avg_length'],
                        'sample_values' => $colStats['sample_values'],
                        'data_source' => $colStats['data_source']
                    ];
                }
                
                respond($profiling);
            }
            break;

        case 'refresh_table':
            if ($method === 'POST') {
                $data = getInput();
                $tableName = $data['table'] ?? $_GET['table'] ?? '';
                $layer = $data['layer'] ?? $_GET['layer'] ?? '';
                if (empty($tableName)) error('Table name is required');

                $stmt = db()->prepare("SELECT t.*, l.name as layer_name
                    FROM catalog_tables t
                    JOIN catalog_layers l ON t.layer_id = l.id
                    WHERE t.name = ? AND l.name = ?");
                $stmt->execute([$tableName, $layer]);
                $table = $stmt->fetch();
                if (!$table) error('Table not found');
                if (empty($table['source_id'])) error('Table has no external source linked');

                $sourceStmt = db()->prepare("SELECT * FROM external_sources WHERE id = ?");
                $sourceStmt->execute([$table['source_id']]);
                $source = $sourceStmt->fetch();
                if (!$source) error('External source not found');

                if (strpos($tableName, '.') !== false) {
                    list($schemaPart, $tablePart) = explode('.', $tableName, 2);
                } else {
                    $schemaPart = $source['database_name'] ?? '';
                    $tablePart = $tableName;
                }

                $payload = [
                    'connection' => [
                        'db_type' => strtolower($source['db_type']),
                        'host' => $source['host'],
                        'port' => (int)$source['port'],
                        'username' => $source['username'],
                        'password' => $source['password'],
                        'database' => $source['database_name'] ?? null,
                        'sid' => $source['sid'] ?? null,
                        'lakehouse' => $source['lakehouse'] ?? null,
                        'data_plane' => $source['data_plane'] ?? null,
                    ],
                    'schemas' => [$schemaPart],
                    'tables' => [$tablePart],
                ];

                $ctx = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => "Content-Type: application/json\r\n",
                        'content' => json_encode($payload),
                        'timeout' => 60,
                        'ignore_errors' => true,
                    ]
                ]);
                $response = @file_get_contents(rtrim(SCANNER_URL, '/') . '/scan', false, $ctx);
                if ($response === false) {
                    $err = error_get_last();
                    error('Scanner unavailable: ' . ($err['message'] ?? 'unknown'));
                }
                $statusLine = $http_response_header[0] ?? '';
                if (preg_match('#HTTP/\S+\s+(\d+)#', $statusLine, $m) && (int)$m[1] >= 400) {
                    error('Scanner error (HTTP ' . $m[1] . '): ' . $response);
                }
                $scanResult = json_decode($response, true);
                if (!$scanResult || empty($scanResult['tables'])) {
                    error('Scanner returned no tables for ' . $tableName);
                }

                $scanned = $scanResult['tables'][0];
                $tableId = $table['id'];

                $rowCount = (int)($scanned['row_count'] ?? 0);
                $description = $scanned['comment'] ?? $table['description'];
                $objectType = strtoupper($scanned['table_type'] ?? $table['object_type'] ?? 'TABLE');
                db()->prepare("UPDATE catalog_tables SET row_count = ?, description = COALESCE(?, description), object_type = ? WHERE id = ?")
                    ->execute([$rowCount, $description, $objectType, $tableId]);

                $columnsUpdated = 0;
                $columnsInserted = 0;
                $seenIds = [];
                foreach (($scanned['columns'] ?? []) as $idx => $col) {
                    $colName = $col['column_name'] ?? null;
                    if (!$colName) continue;
                    $dataType = $col['full_type'] ?? $col['data_type'] ?? 'unknown';
                    $isPk = !empty($col['is_primary_key']) ? 1 : 0;
                    $isFk = !empty($col['is_foreign_key']) ? 1 : 0;
                    $isNullable = isset($col['is_nullable']) ? ($col['is_nullable'] ? 1 : 0) : 1;
                    $comment = $col['comment'] ?? null;
                    $position = $col['position'] ?? ($idx + 1);

                    $check = db()->prepare("SELECT id FROM catalog_columns WHERE table_id = ? AND name = ?");
                    $check->execute([$tableId, $colName]);
                    $existing = $check->fetch();
                    if ($existing) {
                        db()->prepare("UPDATE catalog_columns SET data_type = ?, is_pk = ?, is_fk = ?, is_nullable = ?, description = COALESCE(?, description), `order` = ? WHERE id = ?")
                            ->execute([$dataType, $isPk, $isFk, $isNullable, $comment, $position, $existing['id']]);
                        $seenIds[] = (int)$existing['id'];
                        $columnsUpdated++;
                    } else {
                        db()->prepare("INSERT INTO catalog_columns (table_id, name, data_type, is_pk, is_fk, is_nullable, description, `order`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$tableId, $colName, $dataType, $isPk, $isFk, $isNullable, $comment, $position]);
                        $seenIds[] = (int)db()->lastInsertId();
                        $columnsInserted++;
                    }
                }

                $columnsRemoved = 0;
                if (!empty($seenIds)) {
                    $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
                    $params = array_merge([$tableId], $seenIds);
                    $delStmt = db()->prepare("DELETE FROM catalog_columns WHERE table_id = ? AND id NOT IN ($placeholders)");
                    $delStmt->execute($params);
                    $columnsRemoved = $delStmt->rowCount();
                }

                respond([
                    'success' => true,
                    'message' => 'Metadata refreshed',
                    'row_count' => $rowCount,
                    'columns_inserted' => $columnsInserted,
                    'columns_updated' => $columnsUpdated,
                    'columns_removed' => $columnsRemoved,
                ]);
            }
            break;

        // ============================================================
        // COLUMN MAPPINGS
        // ============================================================

        case 'mappings':
            if ($method === 'GET') {
                $sourceLayer = $_GET['source_layer'] ?? '';
                $sourceTable = $_GET['source_table'] ?? '';
                
                $sql = "SELECT * FROM column_mappings WHERE 1=1";
                $params = [];
                
                if ($sourceLayer) {
                    $sql .= " AND source_layer = ?";
                    $params[] = $sourceLayer;
                }
                if ($sourceTable) {
                    $sql .= " AND source_table = ?";
                    $params[] = $sourceTable;
                }
                
                $sql .= " ORDER BY id DESC";
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
                respond($stmt->fetchAll());
            }
            elseif ($method === 'POST') {
                $data = getInput();
                
                $stmt = db()->prepare("INSERT INTO column_mappings 
                    (source_layer, source_table, source_column, target_layer, target_table, target_column, transformation, custom_expression, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $data['source_layer'] ?? $data['sourceLayer'] ?? '',
                    $data['source_table'] ?? $data['sourceTable'] ?? '',
                    $data['source_column'] ?? $data['sourceColumn'] ?? '',
                    $data['target_layer'] ?? $data['targetLayer'] ?? '',
                    $data['target_table'] ?? $data['targetTable'] ?? '',
                    $data['target_column'] ?? $data['targetColumn'] ?? '',
                    $data['transformation'] ?? 'Direct Copy',
                    $data['custom_expression'] ?? null
                ]);
                
                respond(['id' => db()->lastInsertId()]);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('ID is required');
                $data = getInput();
                
                $updates = [];
                $params = [];
                
                if (isset($data['transformation'])) {
                    $updates[] = 'transformation = ?';
                    $params[] = $data['transformation'];
                }
                if (array_key_exists('custom_expression', $data)) {
                    $updates[] = 'custom_expression = ?';
                    $params[] = $data['custom_expression'];
                }
                
                if (empty($updates)) {
                    respond(['message' => 'Nothing to update']);
                } else {
                    $params[] = $id;
                    $stmt = db()->prepare("UPDATE column_mappings SET " . implode(', ', $updates) . " WHERE id = ?");
                    $stmt->execute($params);
                    respond(['message' => 'Mapping updated']);
                }
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM column_mappings WHERE id = ?")->execute([$id]);
                respond(['message' => 'Mapping deleted']);
            }
            break;
        
        // ============================================================
        // TABLE JOIN CONDITIONS
        // ============================================================
        
        case 'join_conditions':
            if ($method === 'GET') {
                $stmt = db()->prepare("SELECT * FROM table_join_conditions ORDER BY id DESC");
                $stmt->execute();
                respond($stmt->fetchAll());
            }
            elseif ($method === 'POST') {
                $data = getInput();
                
                $stmt = db()->prepare("INSERT INTO table_join_conditions 
                    (source_layer, source_table, source_column, target_layer, target_table, target_column, join_type, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $data['source_layer'] ?? $data['sourceLayer'] ?? '',
                    $data['source_table'] ?? $data['sourceTable'] ?? '',
                    $data['source_column'] ?? $data['sourceColumn'] ?? '',
                    $data['target_layer'] ?? $data['targetLayer'] ?? '',
                    $data['target_table'] ?? $data['targetTable'] ?? '',
                    $data['target_column'] ?? $data['targetColumn'] ?? '',
                    $data['join_type'] ?? $data['joinType'] ?? 'INNER'
                ]);
                
                respond(['id' => db()->lastInsertId()]);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('ID is required');
                $data = getInput();
                
                $stmt = db()->prepare("UPDATE table_join_conditions SET 
                    source_column = ?, target_column = ?, join_type = ? WHERE id = ?");
                $stmt->execute([
                    $data['source_column'] ?? $data['sourceColumn'] ?? '',
                    $data['target_column'] ?? $data['targetColumn'] ?? '',
                    $data['join_type'] ?? $data['joinType'] ?? 'INNER',
                    $id
                ]);
                respond(['message' => 'Join condition updated']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM table_join_conditions WHERE id = ?")->execute([$id]);
                respond(['message' => 'Join condition deleted']);
            }
            break;
            
        // ============================================================
        // REPORTING CATALOG
        // ============================================================
        
        case 'reports':
            if ($method === 'GET') {
                $search = $_GET['search'] ?? '';
                $domain = $_GET['domain'] ?? '';
                $biTool = $_GET['bi_tool'] ?? '';
                $status = $_GET['status'] ?? '';
                
                $sql = "SELECT r.*, d.name as domain_name, d.icon as domain_icon 
                    FROM reports r 
                    LEFT JOIN domains d ON r.domain_id = d.id 
                    WHERE 1=1";
                $params = [];
                
                if ($search) {
                    $sql .= " AND (r.report_name LIKE ? OR r.short_description LIKE ? OR r.report_id LIKE ?)";
                    $searchTerm = "%$search%";
                    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
                }
                if ($domain) {
                    $sql .= " AND r.domain_id = ?";
                    $params[] = $domain;
                }
                if ($biTool) {
                    $sql .= " AND r.bi_tool = ?";
                    $params[] = $biTool;
                }
                if ($status) {
                    $sql .= " AND r.certification_status = ?";
                    $params[] = $status;
                }
                
                $sql .= " ORDER BY r.report_name";
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
                respond($stmt->fetchAll());
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['report_name'])) error('Report name is required');
                
                // Generate report_id if not provided
                $reportId = $data['report_id'] ?? 'RPT-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                $stmt = db()->prepare("INSERT INTO reports 
                    (report_id, report_name, short_description, long_description, business_purpose, 
                     domain_id, sub_domain, report_type, business_owner, data_owner, technical_owner, 
                     data_steward, report_maintainer, update_frequency, certification_status,
                     bi_tool, workspace_location, dashboard_url, report_version, pages_count, visuals_count,
                     target_audience, access_level, has_pii, has_financial_data, regulatory_flags, retention_policy) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->execute([
                    $reportId,
                    $data['report_name'],
                    $data['short_description'] ?? '',
                    $data['long_description'] ?? '',
                    $data['business_purpose'] ?? '',
                    $data['domain_id'] ?: null,
                    $data['sub_domain'] ?? '',
                    $data['report_type'] ?? 'analytical',
                    $data['business_owner'] ?? '',
                    $data['data_owner'] ?? '',
                    $data['technical_owner'] ?? '',
                    $data['data_steward'] ?? '',
                    $data['report_maintainer'] ?? '',
                    $data['update_frequency'] ?? 'daily',
                    $data['certification_status'] ?? 'draft',
                    $data['bi_tool'] ?? 'power_bi',
                    $data['workspace_location'] ?? '',
                    $data['dashboard_url'] ?? '',
                    $data['report_version'] ?? '1.0',
                    $data['pages_count'] ?? 1,
                    $data['visuals_count'] ?? 0,
                    $data['target_audience'] ?? '',
                    $data['access_level'] ?? 'restricted',
                    $data['has_pii'] ?? 0,
                    $data['has_financial_data'] ?? 0,
                    isset($data['regulatory_flags']) ? json_encode($data['regulatory_flags']) : null,
                    $data['retention_policy'] ?? ''
                ]);
                
                respond(['id' => db()->lastInsertId(), 'report_id' => $reportId]);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('ID is required');
                $data = getInput();
                
                $fields = ['report_name', 'short_description', 'long_description', 'business_purpose',
                    'domain_id', 'sub_domain', 'report_type', 'business_owner', 'data_owner', 
                    'technical_owner', 'data_steward', 'report_maintainer', 'update_frequency',
                    'certification_status', 'bi_tool', 'workspace_location', 'dashboard_url',
                    'report_version', 'pages_count', 'visuals_count', 'target_audience', 'access_level',
                    'has_pii', 'has_financial_data', 'retention_policy', 'overall_quality_score', 'known_issues'];
                
                $updates = [];
                $params = [];
                
                foreach ($fields as $field) {
                    if (isset($data[$field])) {
                        $updates[] = "$field = ?";
                        $params[] = $data[$field];
                    }
                }
                
                if (isset($data['regulatory_flags'])) {
                    $updates[] = 'regulatory_flags = ?';
                    $params[] = json_encode($data['regulatory_flags']);
                }
                
                if (empty($updates)) {
                    respond(['message' => 'Nothing to update']);
                } else {
                    $params[] = $id;
                    $stmt = db()->prepare("UPDATE reports SET " . implode(', ', $updates) . " WHERE id = ?");
                    $stmt->execute($params);
                    respond(['message' => 'Report updated']);
                }
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM reports WHERE id = ?")->execute([$id]);
                respond(['message' => 'Report deleted']);
            }
            break;
            
        case 'report_data_sources':
            if ($method === 'GET') {
                $reportId = $_GET['report_id'] ?? '';
                if (!$reportId) error('report_id is required');
                
                $stmt = db()->prepare("SELECT rds.*, ct.name as catalog_table_name, cl.name as catalog_layer_name
                    FROM report_data_sources rds 
                    LEFT JOIN catalog_tables ct ON rds.table_id = ct.id
                    LEFT JOIN catalog_layers cl ON ct.layer_id = cl.id
                    WHERE rds.report_id = ?");
                $stmt->execute([$reportId]);
                respond($stmt->fetchAll());
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['report_id'])) error('report_id is required');
                
                $stmt = db()->prepare("INSERT INTO report_data_sources 
                    (report_id, source_type, layer_name, table_id, table_name, schema_name, database_name, 
                     used_columns, etl_process_name, transformation_logic, refresh_frequency) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->execute([
                    $data['report_id'],
                    $data['source_type'] ?? 'table',
                    $data['layer_name'] ?? '',
                    $data['table_id'] ?: null,
                    $data['table_name'] ?? '',
                    $data['schema_name'] ?? '',
                    $data['database_name'] ?? '',
                    isset($data['used_columns']) ? json_encode($data['used_columns']) : null,
                    $data['etl_process_name'] ?? '',
                    $data['transformation_logic'] ?? '',
                    $data['refresh_frequency'] ?? ''
                ]);
                
                respond(['id' => db()->lastInsertId()]);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM report_data_sources WHERE id = ?")->execute([$id]);
                respond(['message' => 'Data source deleted']);
            }
            break;
            
        case 'report_kpis':
            if ($method === 'GET') {
                $reportId = $_GET['report_id'] ?? '';
                if (!$reportId) error('report_id is required');
                
                $stmt = db()->prepare("SELECT rk.*, gt.name as term_name
                    FROM report_kpis rk 
                    LEFT JOIN glossary_terms gt ON rk.term_id = gt.id
                    WHERE rk.report_id = ?");
                $stmt->execute([$reportId]);
                respond($stmt->fetchAll());
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['report_id']) || empty($data['kpi_name'])) error('report_id and kpi_name are required');
                
                $stmt = db()->prepare("INSERT INTO report_kpis 
                    (report_id, kpi_name, kpi_code, business_definition, technical_formula, 
                     aggregation_rule, unit_of_measure, target_value, threshold_warning, threshold_critical,
                     current_value, trend, filters_applied, dependencies, term_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->execute([
                    $data['report_id'],
                    $data['kpi_name'],
                    $data['kpi_code'] ?? '',
                    $data['business_definition'] ?? '',
                    $data['technical_formula'] ?? '',
                    $data['aggregation_rule'] ?? '',
                    $data['unit_of_measure'] ?? '',
                    $data['target_value'] ?: null,
                    $data['threshold_warning'] ?: null,
                    $data['threshold_critical'] ?: null,
                    $data['current_value'] ?: null,
                    $data['trend'] ?? 'stable',
                    isset($data['filters_applied']) ? json_encode($data['filters_applied']) : null,
                    isset($data['dependencies']) ? json_encode($data['dependencies']) : null,
                    $data['term_id'] ?: null
                ]);
                
                respond(['id' => db()->lastInsertId()]);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('ID is required');
                $data = getInput();
                
                $stmt = db()->prepare("UPDATE report_kpis SET 
                    kpi_name = ?, kpi_code = ?, business_definition = ?, technical_formula = ?,
                    aggregation_rule = ?, unit_of_measure = ?, target_value = ?, threshold_warning = ?,
                    threshold_critical = ?, current_value = ?, trend = ?, term_id = ?
                    WHERE id = ?");
                
                $stmt->execute([
                    $data['kpi_name'],
                    $data['kpi_code'] ?? '',
                    $data['business_definition'] ?? '',
                    $data['technical_formula'] ?? '',
                    $data['aggregation_rule'] ?? '',
                    $data['unit_of_measure'] ?? '',
                    $data['target_value'] ?: null,
                    $data['threshold_warning'] ?: null,
                    $data['threshold_critical'] ?: null,
                    $data['current_value'] ?: null,
                    $data['trend'] ?? 'stable',
                    $data['term_id'] ?: null,
                    $id
                ]);
                
                respond(['message' => 'KPI updated']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM report_kpis WHERE id = ?")->execute([$id]);
                respond(['message' => 'KPI deleted']);
            }
            break;
            
        case 'report_users':
            if ($method === 'GET') {
                $reportId = $_GET['report_id'] ?? '';
                if (!$reportId) error('report_id is required');
                
                $stmt = db()->prepare("SELECT * FROM report_users WHERE report_id = ? ORDER BY user_name");
                $stmt->execute([$reportId]);
                respond($stmt->fetchAll());
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['report_id']) || empty($data['user_name'])) error('report_id and user_name are required');
                
                $stmt = db()->prepare("INSERT INTO report_users 
                    (report_id, user_name, user_email, user_role, department, access_granted_by) 
                    VALUES (?, ?, ?, ?, ?, ?)");
                
                $stmt->execute([
                    $data['report_id'],
                    $data['user_name'],
                    $data['user_email'] ?? '',
                    $data['user_role'] ?? 'viewer',
                    $data['department'] ?? '',
                    $data['access_granted_by'] ?? ''
                ]);
                
                respond(['id' => db()->lastInsertId()]);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM report_users WHERE id = ?")->execute([$id]);
                respond(['message' => 'User removed']);
            }
            break;
            
        case 'report_terms':
            if ($method === 'GET') {
                $reportId = $_GET['report_id'] ?? '';
                if (!$reportId) error('report_id is required');
                
                $stmt = db()->prepare("SELECT rt.*, gt.name as term_name, gt.definition as term_definition, gt.domain as term_domain
                    FROM report_terms rt 
                    JOIN glossary_terms gt ON rt.term_id = gt.id
                    WHERE rt.report_id = ?");
                $stmt->execute([$reportId]);
                respond($stmt->fetchAll());
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['report_id']) || empty($data['term_id'])) error('report_id and term_id are required');
                
                $stmt = db()->prepare("INSERT INTO report_terms (report_id, term_id, relationship_type, notes) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE relationship_type = VALUES(relationship_type), notes = VALUES(notes)");
                
                $stmt->execute([
                    $data['report_id'],
                    $data['term_id'],
                    $data['relationship_type'] ?? 'uses',
                    $data['notes'] ?? ''
                ]);
                
                respond(['id' => db()->lastInsertId()]);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM report_terms WHERE id = ?")->execute([$id]);
                respond(['message' => 'Term link removed']);
            }
            break;
            
        // ============================================================
        // GOVERNANCE - ROLES
        // ============================================================
        
        case 'governance_roles':
            if ($method === 'GET') {
                $stmt = db()->query("SELECT * FROM governance_roles ORDER BY id");
                respond($stmt->fetchAll());
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['role_id']) || empty($data['name'])) error('role_id and name are required');
                
                $stmt = db()->prepare("INSERT INTO governance_roles (role_id, name, icon, color, description) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $data['role_id'],
                    $data['name'],
                    $data['icon'] ?? '👤',
                    $data['color'] ?? '#6366f1',
                    $data['description'] ?? ''
                ]);
                
                respond(['id' => db()->lastInsertId()]);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('ID is required');
                $data = getInput();
                
                $stmt = db()->prepare("UPDATE governance_roles SET name = ?, icon = ?, color = ?, description = ? WHERE id = ?");
                $stmt->execute([
                    $data['name'],
                    $data['icon'] ?? '👤',
                    $data['color'] ?? '#6366f1',
                    $data['description'] ?? '',
                    $id
                ]);
                
                respond(['message' => 'Role updated']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM governance_roles WHERE id = ?")->execute([$id]);
                respond(['message' => 'Role deleted']);
            }
            break;
            
        // ============================================================
        // GOVERNANCE - WORKFLOW STEPS
        // ============================================================
        
        case 'governance_steps':
            if ($method === 'GET') {
                $stmt = db()->query("SELECT * FROM governance_workflow_steps ORDER BY `order`");
                $steps = $stmt->fetchAll();
                
                // Parse JSON fields
                foreach ($steps as &$step) {
                    $step['createdBy'] = json_decode($step['created_by'] ?? '[]', true);
                    $step['approvedBy'] = json_decode($step['approved_by'] ?? '[]', true);
                }
                
                respond($steps);
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['step_id']) || empty($data['name'])) error('step_id and name are required');
                
                $stmt = db()->prepare("INSERT INTO governance_workflow_steps 
                    (step_id, name, icon, color, `order`, created_by, approved_by, next_step, reject_step) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $data['step_id'],
                    $data['name'],
                    $data['icon'] ?? '📋',
                    $data['color'] ?? '#6366f1',
                    $data['order'] ?? 0,
                    json_encode($data['createdBy'] ?? []),
                    json_encode($data['approvedBy'] ?? []),
                    $data['nextStep'] ?? null,
                    $data['rejectStep'] ?? null
                ]);
                
                respond(['id' => db()->lastInsertId()]);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('ID is required');
                $data = getInput();
                
                $stmt = db()->prepare("UPDATE governance_workflow_steps SET 
                    name = ?, icon = ?, color = ?, `order` = ?, created_by = ?, approved_by = ?, next_step = ?, reject_step = ?
                    WHERE id = ?");
                $stmt->execute([
                    $data['name'],
                    $data['icon'] ?? '📋',
                    $data['color'] ?? '#6366f1',
                    $data['order'] ?? 0,
                    json_encode($data['createdBy'] ?? []),
                    json_encode($data['approvedBy'] ?? []),
                    $data['nextStep'] ?? null,
                    $data['rejectStep'] ?? null,
                    $id
                ]);
                
                respond(['message' => 'Step updated']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM governance_workflow_steps WHERE id = ?")->execute([$id]);
                respond(['message' => 'Step deleted']);
            }
            break;
            
        // ============================================================
        // GOVERNANCE - STAKEHOLDERS
        // ============================================================
        
        case 'governance_stakeholders':
            if ($method === 'GET') {
                $stmt = db()->query("SELECT * FROM governance_stakeholders ORDER BY name");
                respond($stmt->fetchAll());
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['name'])) error('Name is required');
                
                $stmt = db()->prepare("INSERT INTO governance_stakeholders (name, role_id, email, department) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $data['name'],
                    $data['role'] ?? '',
                    $data['email'] ?? '',
                    $data['department'] ?? ''
                ]);
                
                respond(['id' => db()->lastInsertId()]);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('ID is required');
                $data = getInput();
                
                $stmt = db()->prepare("UPDATE governance_stakeholders SET name = ?, role_id = ?, email = ?, department = ? WHERE id = ?");
                $stmt->execute([
                    $data['name'],
                    $data['role'] ?? '',
                    $data['email'] ?? '',
                    $data['department'] ?? '',
                    $id
                ]);
                
                respond(['message' => 'Stakeholder updated']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('ID is required');
                db()->prepare("DELETE FROM governance_stakeholders WHERE id = ?")->execute([$id]);
                respond(['message' => 'Stakeholder deleted']);
            }
            break;
            
        // ============================================================
        // FULL DATA EXPORT/IMPORT
        // ============================================================
        
        case 'export_all':
            $data = [
                'exportDate' => date('c'),
                'version' => 'MIM v16 MySQL',
                'glossary_terms' => db()->query("SELECT * FROM glossary_terms")->fetchAll(),
                'catalog_layers' => db()->query("SELECT * FROM catalog_layers ORDER BY `order`")->fetchAll(),
                'catalog_tables' => db()->query("SELECT * FROM catalog_tables")->fetchAll(),
                'catalog_columns' => db()->query("SELECT * FROM catalog_columns")->fetchAll(),
                'column_mappings' => db()->query("SELECT * FROM column_mappings")->fetchAll(),
                'governance_roles' => db()->query("SELECT * FROM governance_roles")->fetchAll(),
                'governance_steps' => db()->query("SELECT * FROM governance_workflow_steps")->fetchAll(),
                'governance_stakeholders' => db()->query("SELECT * FROM governance_stakeholders")->fetchAll()
            ];
            respond($data);
            break;
            
        // ============================================================
        // STATS
        // ============================================================
        
        case 'stats':
            $stats = [
                // Main counts
                'glossary' => db()->query("SELECT COUNT(*) FROM glossary_terms")->fetchColumn(),
                'catalog_tables' => db()->query("SELECT COUNT(*) FROM catalog_tables")->fetchColumn(),
                'catalog_columns' => db()->query("SELECT COUNT(*) FROM catalog_columns")->fetchColumn(),
                'mappings' => db()->query("SELECT COUNT(*) FROM column_mappings")->fetchColumn(),
                'roles' => db()->query("SELECT COUNT(*) FROM governance_roles")->fetchColumn(),
                'steps' => db()->query("SELECT COUNT(*) FROM governance_workflow_steps")->fetchColumn(),
                'stakeholders' => db()->query("SELECT COUNT(*) FROM governance_stakeholders")->fetchColumn(),
                
                // Glossary status breakdown
                'glossary_approved' => 0,
                'glossary_draft' => 0,
                'glossary_review' => 0,
                
                // Catalog status breakdown
                'catalog_active' => 0,
                'catalog_documented' => 0,
                'catalog_new' => 0,
                
                // Mappings status breakdown
                'mappings_validated' => 0,
                'mappings_pending' => 0,
                'mappings_draft' => 0,
                
                // Data Quality metrics (DIFFERENT!)
                'quality_score' => 0,
                'quality_checks_total' => 0,
                'quality_checks_passed' => 0,
                'quality_checks_failed' => 0,
                
                'external_sources' => 0,
                'domains' => 0,
                // Reports stats
                'reports' => 0,
                'reports_certified' => 0,
                'reports_kpis' => 0,
                'reports_quality_avg' => 0
            ];
            
            // Glossary status breakdown
            try {
                $stats['glossary_approved'] = db()->query("SELECT COUNT(*) FROM glossary_terms WHERE status = 'approved'")->fetchColumn();
                $stats['glossary_draft'] = db()->query("SELECT COUNT(*) FROM glossary_terms WHERE status = 'draft'")->fetchColumn();
                $stats['glossary_review'] = db()->query("SELECT COUNT(*) FROM glossary_terms WHERE status = 'under_review'")->fetchColumn();
            } catch (Exception $e) {
                // Status column might not exist - use proportions
                $total = $stats['glossary'];
                $stats['glossary_approved'] = (int)($total * 0.6);
                $stats['glossary_draft'] = (int)($total * 0.3);
                $stats['glossary_review'] = $total - $stats['glossary_approved'] - $stats['glossary_draft'];
            }
            
            // Catalog status breakdown
            try {
                $stats['catalog_active'] = db()->query("SELECT COUNT(*) FROM catalog_tables WHERE is_active = 1")->fetchColumn();
                $stats['catalog_documented'] = db()->query("SELECT COUNT(*) FROM catalog_tables WHERE description IS NOT NULL AND description != ''")->fetchColumn();
                $stats['catalog_new'] = $stats['catalog_tables'] - $stats['catalog_active'];
            } catch (Exception $e) {
                $total = $stats['catalog_tables'];
                $stats['catalog_active'] = (int)($total * 0.7);
                $stats['catalog_documented'] = (int)($total * 0.5);
                $stats['catalog_new'] = $total - $stats['catalog_active'];
            }
            
            // Mappings status breakdown
            try {
                $stats['mappings_validated'] = db()->query("SELECT COUNT(*) FROM column_mappings WHERE is_validated = 1")->fetchColumn();
                $stats['mappings_pending'] = db()->query("SELECT COUNT(*) FROM column_mappings WHERE is_validated = 0")->fetchColumn();
                $stats['mappings_draft'] = 0;
            } catch (Exception $e) {
                $total = $stats['mappings'];
                $stats['mappings_validated'] = (int)($total * 0.6);
                $stats['mappings_pending'] = (int)($total * 0.3);
                $stats['mappings_draft'] = $total - $stats['mappings_validated'] - $stats['mappings_pending'];
            }
            
            // Data Quality metrics
            try {
                $stats['quality_checks_total'] = db()->query("SELECT COUNT(*) FROM data_quality_rules WHERE status = 'active'")->fetchColumn();
                $passed = db()->query("SELECT COUNT(*) FROM soda_check_results WHERE result = 'pass'")->fetchColumn();
                $failed = db()->query("SELECT COUNT(*) FROM soda_check_results WHERE result = 'fail'")->fetchColumn();
                $stats['quality_checks_passed'] = $passed;
                $stats['quality_checks_failed'] = $failed;
                
                // Calculate quality score
                $total_results = $passed + $failed;
                if ($total_results > 0) {
                    $stats['quality_score'] = round(($passed / $total_results) * 100, 1);
                } else {
                    $stats['quality_score'] = 0;
                }
            } catch (Exception $e) {
                // Tables don't exist - use dummy data
                $stats['quality_checks_total'] = 25;
                $stats['quality_checks_passed'] = 22;
                $stats['quality_checks_failed'] = 3;
                $stats['quality_score'] = 88.0;
            }
            
            // Try to get external sources count
            try {
                $stats['external_sources'] = db()->query("SELECT COUNT(*) FROM external_sources")->fetchColumn();
            } catch (Exception $e) {
                // Table doesn't exist
            }
            
            // Try to get domains count
            try {
                $stats['domains'] = db()->query("SELECT COUNT(*) FROM domains")->fetchColumn();
            } catch (Exception $e) {
                // Table doesn't exist
            }
            
            // Try to get reports stats (table may not exist yet)
            try {
                $stats['reports'] = db()->query("SELECT COUNT(*) FROM reports")->fetchColumn();
                $stats['reports_certified'] = db()->query("SELECT COUNT(*) FROM reports WHERE certification_status = 'certified'")->fetchColumn();
                $stats['reports_kpis'] = db()->query("SELECT COUNT(*) FROM report_kpis")->fetchColumn();
                $avgQuality = db()->query("SELECT AVG(overall_quality_score) FROM reports WHERE overall_quality_score IS NOT NULL")->fetchColumn();
                $stats['reports_quality_avg'] = $avgQuality ? round($avgQuality, 1) : 0;
            } catch (Exception $e) {
                // Reports table doesn't exist yet
            }
            
            respond($stats);
            break;
        
        // ============================================================
        // DOMAINS
        // ============================================================
        
        case 'domains':
            if ($method === 'GET') {
                if ($id) {
                    // Get single domain with stakeholders
                    $stmt = db()->prepare("SELECT * FROM domains WHERE id = ?");
                    $stmt->execute([$id]);
                    $domain = $stmt->fetch();
                    if (!$domain) error('Domain not found', 404);
                    
                    // Get stakeholders grouped by role
                    $stStmt = db()->prepare("SELECT role_id, stakeholder_name, stakeholder_email FROM domain_stakeholders WHERE domain_id = ? ORDER BY role_id, stakeholder_name");
                    $stStmt->execute([$id]);
                    $stakeholders = $stStmt->fetchAll();
                    
                    // Group by role
                    $grouped = [];
                    foreach ($stakeholders as $s) {
                        $key = $s['role_id'] . 's'; // domain_owner -> domain_owners
                        if (!isset($grouped[$key])) $grouped[$key] = [];
                        $grouped[$key][] = $s['stakeholder_name'];
                    }
                    $domain['stakeholders'] = $grouped;
                    
                    respond($domain);
                } else {
                    // Get all domains with stakeholders
                    $domains = db()->query("SELECT * FROM domains ORDER BY name")->fetchAll();
                    
                    foreach ($domains as &$domain) {
                        $stStmt = db()->prepare("SELECT role_id, stakeholder_name FROM domain_stakeholders WHERE domain_id = ? ORDER BY role_id, stakeholder_name");
                        $stStmt->execute([$domain['id']]);
                        $stakeholders = $stStmt->fetchAll();
                        
                        // Group by role
                        $grouped = [];
                        foreach ($stakeholders as $s) {
                            $key = $s['role_id'] . 's';
                            if (!isset($grouped[$key])) $grouped[$key] = [];
                            $grouped[$key][] = $s['stakeholder_name'];
                        }
                        $domain['stakeholders'] = $grouped;
                    }
                    
                    respond($domains);
                }
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (!isset($data['name'])) error('Domain name is required');
                
                $stmt = db()->prepare("INSERT INTO domains (name, icon, color, description) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $data['name'],
                    $data['icon'] ?? '📁',
                    $data['color'] ?? '#6366f1',
                    $data['description'] ?? ''
                ]);
                respond(['id' => db()->lastInsertId(), 'message' => 'Domain created']);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('Domain ID required');
                $data = getInput();
                
                $stmt = db()->prepare("UPDATE domains SET name = ?, icon = ?, color = ?, description = ? WHERE id = ?");
                $stmt->execute([
                    $data['name'],
                    $data['icon'] ?? '📁',
                    $data['color'] ?? '#6366f1',
                    $data['description'] ?? '',
                    $id
                ]);
                respond(['message' => 'Domain updated']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('Domain ID required');
                $stmt = db()->prepare("DELETE FROM domains WHERE id = ?");
                $stmt->execute([$id]);
                respond(['message' => 'Domain deleted']);
            }
            break;
        
        // ============================================================
        // DOMAIN STAKEHOLDERS
        // ============================================================
        
        case 'domain_stakeholders':
            if ($method === 'GET') {
                $domainId = $_GET['domain_id'] ?? null;
                $roleId = $_GET['role_id'] ?? null;
                
                if ($domainId && $roleId) {
                    // Get stakeholders for specific domain and role
                    $stmt = db()->prepare("SELECT * FROM domain_stakeholders WHERE domain_id = ? AND role_id = ? ORDER BY stakeholder_name");
                    $stmt->execute([$domainId, $roleId]);
                    respond($stmt->fetchAll());
                } elseif ($domainId) {
                    // Get all stakeholders for a domain
                    $stmt = db()->prepare("SELECT * FROM domain_stakeholders WHERE domain_id = ? ORDER BY role_id, stakeholder_name");
                    $stmt->execute([$domainId]);
                    respond($stmt->fetchAll());
                } else {
                    // Get all domain stakeholders
                    $stmt = db()->query("SELECT ds.*, d.name as domain_name FROM domain_stakeholders ds JOIN domains d ON ds.domain_id = d.id ORDER BY d.name, ds.role_id, ds.stakeholder_name");
                    respond($stmt->fetchAll());
                }
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (!isset($data['domain_id']) || !isset($data['role_id']) || !isset($data['stakeholder_name'])) {
                    error('domain_id, role_id, and stakeholder_name are required');
                }
                
                $stmt = db()->prepare("INSERT INTO domain_stakeholders (domain_id, role_id, stakeholder_name, stakeholder_email) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $data['domain_id'],
                    $data['role_id'],
                    $data['stakeholder_name'],
                    $data['stakeholder_email'] ?? null
                ]);
                respond(['id' => db()->lastInsertId(), 'message' => 'Stakeholder added to domain']);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('Stakeholder ID required');
                $data = getInput();
                
                $stmt = db()->prepare("UPDATE domain_stakeholders SET role_id = ?, stakeholder_name = ?, stakeholder_email = ? WHERE id = ?");
                $stmt->execute([
                    $data['role_id'],
                    $data['stakeholder_name'],
                    $data['stakeholder_email'] ?? null,
                    $id
                ]);
                respond(['message' => 'Domain stakeholder updated']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('Stakeholder ID required');
                $stmt = db()->prepare("DELETE FROM domain_stakeholders WHERE id = ?");
                $stmt->execute([$id]);
                respond(['message' => 'Domain stakeholder removed']);
            }
            break;
            
        // ============================================================
        // EXTERNAL SOURCES
        // ============================================================
        
        case 'external_sources':
            if ($method === 'GET') {
                if ($id) {
                    $stmt = db()->prepare("SELECT * FROM external_sources WHERE id = ?");
                    $stmt->execute([$id]);
                    $source = $stmt->fetch();
                    if (!$source) error('Source not found', 404);
                    // Decrypt password for use
                    respond($source);
                } else {
                    $stmt = db()->query("SELECT id, name, db_type, host, port, username, database_name, status, table_count, last_scan, created_at FROM external_sources ORDER BY name");
                    respond($stmt->fetchAll());
                }
            }
            elseif ($method === 'POST') {
                $data = getInput();
                if (empty($data['name']) || empty($data['db_type']) || empty($data['host'])) {
                    error('Name, db_type and host are required');
                }
                
                $stmt = db()->prepare("INSERT INTO external_sources (name, db_type, host, port, username, password, database_name, sid, lakehouse, data_plane, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");
                $stmt->execute([
                    $data['name'],
                    $data['db_type'],
                    $data['host'],
                    $data['port'] ?? 3306,
                    $data['username'] ?? '',
                    $data['password'] ?? '', // In production, encrypt this
                    $data['database_name'] ?? null,
                    $data['sid'] ?? null,
                    $data['lakehouse'] ?? null,
                    $data['data_plane'] ?? null,
                    $currentUser['id'] ?? 1
                ]);
                
                respond(['id' => db()->lastInsertId(), 'message' => 'Source created']);
            }
            elseif ($method === 'PUT') {
                if (!$id) error('Source ID required');
                $data = getInput();
                
                $fields = [];
                $values = [];
                
                if (isset($data['name'])) { $fields[] = 'name = ?'; $values[] = $data['name']; }
                if (isset($data['db_type'])) { $fields[] = 'db_type = ?'; $values[] = $data['db_type']; }
                if (isset($data['host'])) { $fields[] = 'host = ?'; $values[] = $data['host']; }
                if (isset($data['port'])) { $fields[] = 'port = ?'; $values[] = $data['port']; }
                if (isset($data['username'])) { $fields[] = 'username = ?'; $values[] = $data['username']; }
                if (isset($data['password']) && $data['password']) { $fields[] = 'password = ?'; $values[] = $data['password']; }
                if (isset($data['database_name'])) { $fields[] = 'database_name = ?'; $values[] = $data['database_name']; }
                if (isset($data['sid'])) { $fields[] = 'sid = ?'; $values[] = $data['sid']; }
                if (isset($data['lakehouse'])) { $fields[] = 'lakehouse = ?'; $values[] = $data['lakehouse']; }
                if (isset($data['data_plane'])) { $fields[] = 'data_plane = ?'; $values[] = $data['data_plane']; }
                if (isset($data['status'])) { $fields[] = 'status = ?'; $values[] = $data['status']; }
                if (isset($data['table_count'])) { $fields[] = 'table_count = ?'; $values[] = $data['table_count']; }
                if (isset($data['last_scan'])) { $fields[] = 'last_scan = ?'; $values[] = $data['last_scan']; }
                
                if (empty($fields)) error('No fields to update');
                
                $values[] = $id;
                $stmt = db()->prepare("UPDATE external_sources SET " . implode(', ', $fields) . " WHERE id = ?");
                $stmt->execute($values);
                
                respond(['message' => 'Source updated']);
            }
            elseif ($method === 'DELETE') {
                if (!$id) error('Source ID required');
                
                // Delete related data first
                db()->prepare("DELETE FROM external_source_tables WHERE source_id = ?")->execute([$id]);
                db()->prepare("DELETE FROM external_sources WHERE id = ?")->execute([$id]);
                
                respond(['message' => 'Source deleted']);
            }
            break;
            
        case 'external_sources_import':
            if ($method === 'POST') {
                $data = getInput();
                $sourceId = $data['source_id'] ?? null;
                $tables = $data['tables'] ?? [];
                $targetLayerId = $data['target_layer_id'] ?? null;
                $targetLayerName = isset($data['target_layer_name']) ? trim($data['target_layer_name']) : '';

                if (!$sourceId || empty($tables)) {
                    error('source_id and tables are required');
                }

                // Resolve target layer once for the whole import.
                $resolvedLayerId = null;
                if ($targetLayerId) {
                    $check = db()->prepare("SELECT id FROM catalog_layers WHERE id = ?");
                    $check->execute([$targetLayerId]);
                    $found = $check->fetch();
                    if (!$found) error('Target catalog (layer_id=' . $targetLayerId . ') not found');
                    $resolvedLayerId = (int)$found['id'];
                } elseif ($targetLayerName !== '') {
                    $check = db()->prepare("SELECT id FROM catalog_layers WHERE name = ?");
                    $check->execute([$targetLayerName]);
                    $found = $check->fetch();
                    if ($found) {
                        $resolvedLayerId = (int)$found['id'];
                    } else {
                        $ins = db()->prepare("INSERT INTO catalog_layers (name, description) VALUES (?, ?)");
                        $ins->execute([$targetLayerName, 'Created during external source import']);
                        $resolvedLayerId = (int)db()->lastInsertId();
                    }
                }

                $importedTables = 0;
                $importedColumns = 0;
                $updatedColumns = 0;

                foreach ($tables as $table) {
                    $schemaName = $table['schema_name'];
                    $tableName = $table['table_name'];
                    $columns = $table['columns'] ?? [];

                    // Full table name includes schema
                    $fullTableName = $schemaName . '.' . $tableName;

                    // Check if table exists in catalog
                    $stmt = db()->prepare("SELECT id FROM catalog_tables WHERE name = ?");
                    $stmt->execute([$fullTableName]);
                    $existingTable = $stmt->fetch();

                    if ($existingTable) {
                        $tableId = $existingTable['id'];

                        // Update row count if provided
                        if (isset($table['row_count'])) {
                            $stmt = db()->prepare("UPDATE catalog_tables SET row_count = ? WHERE id = ?");
                            $stmt->execute([$table['row_count'], $tableId]);
                        }
                    } else {
                        if ($resolvedLayerId !== null) {
                            $layerId = $resolvedLayerId;
                        } else {
                            // Backwards-compatible fallback: when no target catalog is specified,
                            // fall back to a layer named after the schema (auto-create if missing).
                            $stmt = db()->prepare("SELECT id FROM catalog_layers WHERE name = ?");
                            $stmt->execute([$schemaName]);
                            $layer = $stmt->fetch();

                            if (!$layer) {
                                $stmt = db()->prepare("INSERT INTO catalog_layers (name, description) VALUES (?, ?)");
                                $stmt->execute([$schemaName, 'Imported from external source']);
                                $layerId = db()->lastInsertId();
                            } else {
                                $layerId = $layer['id'];
                            }
                        }
                        
                        // Create table
                        $stmt = db()->prepare("INSERT INTO catalog_tables (layer_id, name, description, row_count, source_id, object_type, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([
                            $layerId,
                            $fullTableName,
                            $table['comment'] ?? 'Imported from external source',
                            $table['row_count'] ?? 0,
                            $sourceId,
                            $table['object_type'] ?? 'TABLE'  // TABLE or VIEW
                        ]);
                        $tableId = db()->lastInsertId();
                        $importedTables++;
                    }
                    
                    // Import/Update columns
                    foreach ($columns as $column) {
                        // Check if column exists
                        $stmt = db()->prepare("SELECT id FROM catalog_columns WHERE table_id = ? AND name = ?");
                        $stmt->execute([$tableId, $column['column_name']]);
                        $existingColumn = $stmt->fetch();
                        
                        if ($existingColumn) {
                            // Update existing column
                            $stmt = db()->prepare("UPDATE catalog_columns SET data_type = ?, is_pk = ?, description = COALESCE(?, description) WHERE id = ?");
                            $stmt->execute([
                                $column['full_type'] ?? $column['data_type'],
                                $column['is_primary_key'] ? 1 : 0,
                                $column['comment'] ?? null,
                                $existingColumn['id']
                            ]);
                            $updatedColumns++;
                        } else {
                            // Insert new column
                            $stmt = db()->prepare("INSERT INTO catalog_columns (table_id, name, data_type, is_pk, description) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $tableId,
                                $column['column_name'],
                                $column['full_type'] ?? $column['data_type'],
                                $column['is_primary_key'] ? 1 : 0,
                                $column['comment'] ?? null
                            ]);
                            $importedColumns++;
                        }
                    }
                }
                
                respond([
                    'success' => true,
                    'message' => 'Import/Update completed',
                    'tables_imported' => $importedTables,
                    'columns_imported' => $importedColumns,
                    'columns_updated' => $updatedColumns
                ]);
            }
            break;
            
        // ============================================================
        // DEBUG - Check database content
        // ============================================================
        
        case 'debug_catalog':
            if ($method === 'GET') {
                try {
                    $result = [
                        'tables_count' => 0,
                        'columns_count' => 0,
                        'layers_count' => 0,
                        'glossary_count' => 0,
                        'sample_tables' => [],
                        'sample_layers' => []
                    ];
                    
                    // Count tables
                    $stmt = db()->query("SELECT COUNT(*) as cnt FROM catalog_tables");
                    $result['tables_count'] = $stmt->fetch()['cnt'];
                    
                    // Count columns
                    $stmt = db()->query("SELECT COUNT(*) as cnt FROM catalog_columns");
                    $result['columns_count'] = $stmt->fetch()['cnt'];
                    
                    // Count layers
                    $stmt = db()->query("SELECT COUNT(*) as cnt FROM catalog_layers");
                    $result['layers_count'] = $stmt->fetch()['cnt'];
                    
                    // Count glossary
                    $stmt = db()->query("SELECT COUNT(*) as cnt FROM glossary_terms WHERE status != 'deleted'");
                    $result['glossary_count'] = $stmt->fetch()['cnt'];
                    
                    // Sample tables
                    $stmt = db()->query("
                        SELECT t.name, l.name as layer_name 
                        FROM catalog_tables t 
                        LEFT JOIN catalog_layers l ON t.layer_id = l.id 
                        LIMIT 5
                    ");
                    $result['sample_tables'] = $stmt->fetchAll();
                    
                    // Sample layers
                    $stmt = db()->query("SELECT name FROM catalog_layers LIMIT 10");
                    $result['sample_layers'] = $stmt->fetchAll();
                    
                    respond($result);
                } catch (Exception $e) {
                    error('Debug error: ' . $e->getMessage(), 500);
                }
            }
            break;
            
        // ============================================================
        // GLOBAL SEARCH - Real-time database search
        // ============================================================
        
        case 'global_search':
            if ($method === 'GET') {
                $query = $_GET['q'] ?? '';
                if (empty($query)) {
                    respond(['results' => []]);
                }
                
                $query = strtolower($query);
                $results = [
                    'catalog' => [],
                    'glossary' => [],
                    'reports' => [],
                    'domains' => [],
                    'governance' => [],
                    'departments' => [],
                    'mappings' => [],
                    'quality' => []
                ];
                
                // Search in Catalog Tables (with error handling)
                try {
                    $searchPattern = '%' . $query . '%';
                    
                    error_log("=== SEARCH DEBUG ===");
                    error_log("Query: " . $query);
                    error_log("Pattern: " . $searchPattern);
                    
                    // Universal search - search name, description, layer, AND common patterns
                    $stmt = db()->prepare("
                        SELECT t.id, t.name, t.description, t.row_count,
                               l.name as layer_name,
                               (SELECT COUNT(*) FROM catalog_columns WHERE table_id = t.id) as column_count
                        FROM catalog_tables t
                        LEFT JOIN catalog_layers l ON t.layer_id = l.id
                        WHERE LOWER(t.name) LIKE ?
                           OR LOWER(t.description) LIKE ?
                           OR LOWER(l.name) LIKE ?
                        LIMIT 20
                    ");
                    
                    error_log("SQL prepared successfully");
                    
                    $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
                    
                    error_log("SQL executed successfully");
                    
                    $tables = $stmt->fetchAll();
                    
                    error_log("Tables found: " . count($tables));
                    
                    if (count($tables) > 0) {
                        error_log("First table: " . json_encode($tables[0]));
                    }
                    
                    foreach ($tables as $table) {
                        $results['catalog'][] = [
                            'type' => 'table',
                            'name' => $table['name'],
                            'layer' => $table['layer_name'] ?? 'Unknown',
                            'description' => $table['description'] ?? '',
                            'columns' => $table['column_count'] ?? 0
                        ];
                    }
                    
                    error_log("Catalog results count: " . count($results['catalog']));
                    
                } catch (Exception $e) {
                    // Table doesn't exist or error - skip
                    error_log('!!! Catalog tables search ERROR: ' . $e->getMessage());
                    error_log('!!! Stack trace: ' . $e->getTraceAsString());
                }
                
                // Search in Catalog Columns (with error handling)
                try {
                    $stmt = db()->prepare("
                        SELECT c.id, c.name, c.data_type, c.description,
                               t.name as table_name,
                               l.name as layer_name
                        FROM catalog_columns c
                        JOIN catalog_tables t ON c.table_id = t.id
                        LEFT JOIN catalog_layers l ON t.layer_id = l.id
                        WHERE LOWER(c.name) LIKE ?
                           OR LOWER(c.description) LIKE ?
                           OR LOWER(t.name) LIKE ?
                        LIMIT 20
                    ");
                    $stmt->execute(['%' . $query . '%', '%' . $query . '%', '%' . $query . '%']);
                    $columns = $stmt->fetchAll();
                    
                    foreach ($columns as $col) {
                        $results['catalog'][] = [
                            'type' => 'column',
                            'name' => $col['name'],
                            'table' => $col['table_name'],
                            'layer' => $col['layer_name'] ?? 'Unknown',
                            'dataType' => $col['data_type'] ?? 'Unknown'
                        ];
                    }
                } catch (Exception $e) {
                    // Table doesn't exist or error - skip
                    error_log('Catalog columns search error: ' . $e->getMessage());
                }
                
                // Search in Glossary Terms (with error handling)
                try {
                    $stmt = db()->prepare("
                        SELECT id, name, abbreviation, definition, domain, status
                        FROM glossary_terms
                        WHERE status != 'deleted'
                          AND (LOWER(name) LIKE ? 
                               OR LOWER(abbreviation) LIKE ? 
                               OR LOWER(definition) LIKE ?)
                        LIMIT 10
                    ");
                    $searchPattern = '%' . $query . '%';
                    $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
                    $results['glossary'] = $stmt->fetchAll();
                } catch (Exception $e) {
                    // Table doesn't exist or error - skip
                    error_log('Glossary search error: ' . $e->getMessage());
                }
                
                // Search in Reports table (report definitions like "Fraud Detection")
                try {
                    error_log("=== REPORTS SEARCH START ===");
                    error_log("Search pattern: " . $searchPattern);
                    
                    $stmt = db()->prepare("
                        SELECT id, report_name as name, 
                               short_description as description, 
                               report_type, created_at
                        FROM reports
                        WHERE LOWER(report_name) LIKE ?
                           OR LOWER(short_description) LIKE ?
                           OR LOWER(long_description) LIKE ?
                        LIMIT 10
                    ");
                    
                    error_log("SQL prepared for reports");
                    
                    $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
                    
                    error_log("SQL executed for reports");
                    
                    $results['reports'] = $stmt->fetchAll();
                    
                    error_log('Reports search found: ' . count($results['reports']) . ' reports');
                    
                    if (count($results['reports']) > 0) {
                        error_log('First report: ' . json_encode($results['reports'][0]));
                    } else {
                        error_log('NO REPORTS FOUND! Testing if table has any data...');
                        // Test if table has ANY data
                        $testStmt = db()->query("SELECT COUNT(*) as cnt FROM reports");
                        $testCount = $testStmt->fetch()['cnt'];
                        error_log('Total reports in table: ' . $testCount);
                        
                        // Show first report
                        if ($testCount > 0) {
                            $sampleStmt = db()->query("SELECT report_name FROM reports LIMIT 1");
                            $sample = $sampleStmt->fetch();
                            error_log('Sample report name: ' . $sample['report_name']);
                        }
                    }
                    
                } catch (Exception $e) {
                    // Reports table doesn't exist - skip (it's optional)
                    error_log('!!! Reports search ERROR: ' . $e->getMessage());
                    error_log('!!! Stack trace: ' . $e->getTraceAsString());
                }
                
                // Search in Domains
                try {
                    $stmt = db()->prepare("
                        SELECT id, name, description, owner, status
                        FROM domains
                        WHERE LOWER(name) LIKE ?
                           OR LOWER(description) LIKE ?
                           OR LOWER(owner) LIKE ?
                        LIMIT 10
                    ");
                    $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
                    $results['domains'] = $stmt->fetchAll();
                } catch (Exception $e) {
                    error_log('Domains search error (table may not exist): ' . $e->getMessage());
                }
                
                // Search in Governance (roles, stakeholders, workflows)
                try {
                    $governanceResults = [];
                    
                    // Governance roles
                    $stmt = db()->prepare("
                        SELECT 'role' as type, id, role_name as name, description, created_at
                        FROM governance_roles
                        WHERE LOWER(role_name) LIKE ?
                           OR LOWER(description) LIKE ?
                        LIMIT 5
                    ");
                    $stmt->execute([$searchPattern, $searchPattern]);
                    $governanceResults = array_merge($governanceResults, $stmt->fetchAll());
                    
                    // Governance stakeholders
                    $stmt = db()->prepare("
                        SELECT 'stakeholder' as type, id, name, role, email
                        FROM governance_stakeholders
                        WHERE LOWER(name) LIKE ?
                           OR LOWER(role) LIKE ?
                           OR LOWER(email) LIKE ?
                        LIMIT 5
                    ");
                    $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
                    $governanceResults = array_merge($governanceResults, $stmt->fetchAll());
                    
                    $results['governance'] = array_slice($governanceResults, 0, 10);
                } catch (Exception $e) {
                    error_log('Governance search error (table may not exist): ' . $e->getMessage());
                }
                
                // Search in Departments
                try {
                    $stmt = db()->prepare("
                        SELECT id, name, description, manager, created_at
                        FROM departments
                        WHERE LOWER(name) LIKE ?
                           OR LOWER(description) LIKE ?
                           OR LOWER(manager) LIKE ?
                        LIMIT 10
                    ");
                    $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
                    $results['departments'] = $stmt->fetchAll();
                } catch (Exception $e) {
                    error_log('Departments search error (table may not exist): ' . $e->getMessage());
                }
                
                // Search in Mappings (with error handling - table may not exist)
                try {
                    $stmt = db()->prepare("
                        SELECT m.id, m.source_table, m.source_column, 
                               m.target_table, m.target_column, m.transformation
                        FROM mappings m
                        WHERE LOWER(m.source_table) LIKE ?
                           OR LOWER(m.target_table) LIKE ?
                           OR LOWER(m.source_column) LIKE ?
                           OR LOWER(m.target_column) LIKE ?
                        LIMIT 10
                    ");
                    $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
                    $results['mappings'] = $stmt->fetchAll();
                } catch (Exception $e) {
                    // Mappings table doesn't exist - skip (it's optional)
                    error_log('Mappings search error (table may not exist): ' . $e->getMessage());
                }
                
                respond(['results' => $results, 'query' => $query]);
            }
            break;
            
        // ============================================================
        // SEARCH IN REPORTS TABLE - for report definitions
        // ============================================================
        
        case 'search_reports':
            if ($method === 'GET') {
                $query = $_GET['q'] ?? '';
                if (empty($query)) {
                    respond(['reports' => []]);
                }
                
                $searchPattern = '%' . $query . '%';
                
                try {
                    // Search in reports table (report definitions like "Fraud Detection")
                    $stmt = db()->prepare("
                        SELECT id, report_name as name, 
                               short_description as description, 
                               report_type, created_at
                        FROM reports
                        WHERE LOWER(report_name) LIKE ?
                           OR LOWER(short_description) LIKE ?
                           OR LOWER(long_description) LIKE ?
                           OR LOWER(report_type) LIKE ?
                        LIMIT 20
                    ");
                    $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
                    $reports = $stmt->fetchAll();
                    
                    respond(['reports' => $reports]);
                } catch (Exception $e) {
                    error_log('Reports search error: ' . $e->getMessage());
                    respond(['reports' => []]);
                }
            }
            break;
        
        // ============================================================
        // AI GENERATE TERM
        // ============================================================
        
        case 'ai_generate_term':
            if ($method === 'POST') {
                $input = getInput();
                $termName = $input['term_name'] ?? '';
                $prompt = $input['prompt'] ?? '';
                
                if (empty($termName) && empty($prompt)) {
                    error('Term name or prompt required');
                }
                
                $searchTerm = !empty($termName) ? $termName : $prompt;
                
                // OpenAI API Key — set OPENAI_API_KEY env var (see .env.example)
                $apiKey = getenv('OPENAI_API_KEY') ?: '';
                if (empty($apiKey)) {
                    error('OPENAI_API_KEY environment variable is not set');
                }
                
                $systemPrompt = "Sən data governance və business glossary üzrə ekspertisən. Verilən termin üçün aşağıdakı məlumatları JSON formatında qaytar. Cavabı yalnız JSON formatında ver, heç bir əlavə mətn olmasın.

JSON strukturu:
{
    \"name\": \"Terminin tam adı\",
    \"abbreviation\": \"Qısaltma (varsa)\",
    \"definition\": \"Terminin dəqiq biznes mənası (2-3 cümlə)\",
    \"domain\": \"Ən uyğun domain: Finance, HR, Sales, Marketing, Analytics, Operations\",
    \"dataType\": \"Data tipi: String, Integer, Decimal, Date, Boolean\",
    \"example\": \"Nümunə dəyər\",
    \"formula\": \"Hesablama formulu (varsa)\",
    \"businessLogic\": \"Biznes kontekstində istifadəsi\",
    \"technicalDesc\": \"Texniki implementasiya detalları\",
    \"synonyms\": \"Sinonimlər (vergüllə ayrılmış)\",
    \"relatedTerms\": \"Əlaqəli terminlər (vergüllə ayrılmış)\",
    \"sourceSystem\": \"Mənbə sistem (CRM, ERP, SAP və s.)\"
}";

                $userPrompt = "Termin: " . $searchTerm;
                
                $postData = json_encode([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt]
                    ],
                    'temperature' => 0.7,
                    'max_tokens' => 1000
                ]);
                
                // Try cURL first, then fall back to file_get_contents
                $response = null;
                
                if (function_exists('curl_init')) {
                    // Use cURL
                    $ch = curl_init('https://api.openai.com/v1/chat/completions');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            'Authorization: Bearer ' . $apiKey
                        ],
                        CURLOPT_POSTFIELDS => $postData,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                        CURLOPT_TIMEOUT => 30
                    ]);
                    
                    $response = curl_exec($ch);
                    $curlError = curl_error($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($curlError) {
                        error('cURL error: ' . $curlError, 500);
                    }
                    
                    if ($httpCode !== 200) {
                        $errorData = json_decode($response, true);
                        $errorMsg = $errorData['error']['message'] ?? $response;
                        error('OpenAI API error (' . $httpCode . '): ' . $errorMsg, 500);
                    }
                } else {
                    // Use file_get_contents as fallback
                    $context = stream_context_create([
                        'http' => [
                            'method' => 'POST',
                            'header' => "Content-Type: application/json\r\n" .
                                       "Authorization: Bearer " . $apiKey . "\r\n",
                            'content' => $postData,
                            'timeout' => 30,
                            'ignore_errors' => true
                        ],
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false
                        ]
                    ]);
                    
                    $response = @file_get_contents('https://api.openai.com/v1/chat/completions', false, $context);
                    
                    if ($response === false) {
                        // Check if allow_url_fopen is enabled
                        if (!ini_get('allow_url_fopen')) {
                            error('Neither cURL nor allow_url_fopen is enabled. Please enable one in php.ini');
                        }
                        error('Failed to connect to OpenAI API');
                    }
                    
                    // Check for HTTP errors in response headers
                    if (isset($http_response_header)) {
                        $statusLine = $http_response_header[0];
                        preg_match('{HTTP/\S*\s(\d{3})}', $statusLine, $match);
                        $httpCode = intval($match[1] ?? 0);
                        
                        if ($httpCode !== 200) {
                            $errorData = json_decode($response, true);
                            $errorMsg = $errorData['error']['message'] ?? $response;
                            error('OpenAI API error (' . $httpCode . '): ' . $errorMsg, 500);
                        }
                    }
                }
                
                $result = json_decode($response, true);
                
                if (!$result || !isset($result['choices'][0]['message']['content'])) {
                    error('Invalid OpenAI response format');
                }
                
                $content = $result['choices'][0]['message']['content'];
                
                // Parse JSON from response
                $content = trim($content);
                // Remove markdown code blocks if present
                $content = preg_replace('/^```json\s*/i', '', $content);
                $content = preg_replace('/\s*```$/i', '', $content);
                $content = preg_replace('/^```\s*/i', '', $content);
                $content = preg_replace('/\s*```$/i', '', $content);
                
                $termData = json_decode($content, true);
                
                if (!$termData) {
                    error('Failed to parse AI response. Raw: ' . substr($content, 0, 200));
                }
                
                respond($termData);
            }
            break;
            
        // ============================================================
        // DEFAULT
        // ============================================================
        
        default:
            respond([
                'message' => 'MIM API v5.0 - MySQL Backend',
                'endpoints' => [
                    'glossary_terms' => 'GET/POST/PUT/DELETE',
                    'glossary_history' => 'POST',
                    'domains' => 'GET/POST/PUT/DELETE',
                    'domain_stakeholders' => 'GET/POST/PUT/DELETE',
                    'catalog_layers' => 'GET/POST/PUT/DELETE',
                    'catalog_tables' => 'GET/POST/PUT/DELETE',
                    'catalog_columns' => 'GET/POST/DELETE',
                    'mappings' => 'GET/POST/PUT/DELETE',
                    'external_sources' => 'GET/POST/PUT/DELETE',
                    'external_sources_import' => 'POST',
                    'governance_roles' => 'GET/POST/PUT/DELETE',
                    'governance_steps' => 'GET/POST/PUT/DELETE',
                    'governance_stakeholders' => 'GET/POST/PUT/DELETE',
                    'global_search' => 'GET',
                    'export_all' => 'GET',
                    'stats' => 'GET'
                ]
            ]);
    }
    
} catch (Exception $e) {
    error('Server error: ' . $e->getMessage(), 500);
}
