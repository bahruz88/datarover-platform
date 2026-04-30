<?php
/**
 * DataRover - Role-Based Access Control (RBAC) System
 * 
 * Purpose: Middleware for checking user permissions
 * Usage: Include this file in backend.php
 */

// ============================================================
// SESSION & USER MANAGEMENT
// ============================================================

/**
 * Start session and load current user
 */
function initAuth() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load user from session
    if (isset($_SESSION['user_id'])) {
        $_SESSION['user'] = getCurrentUser($_SESSION['user_id']);
    }
}

/**
 * Get current logged-in user with roles and permissions
 */
function getCurrentUser($userId) {
    $db = db();
    
    // Get user details
    $stmt = $db->prepare("
        SELECT id, username, email, first_name, last_name, is_active
        FROM users 
        WHERE id = ? AND is_active = TRUE
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        return null;
    }
    
    // Get user roles
    $stmt = $db->prepare("
        SELECT ur.id, ur.name, ur.code
        FROM user_roles ur
        JOIN user_role_assignments ura ON ur.id = ura.role_id
        WHERE ura.user_id = ? AND ur.is_active = TRUE
    ");
    $stmt->execute([$userId]);
    $user['roles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user permissions
    $stmt = $db->prepare("
        SELECT DISTINCT p.module, p.action, p.name
        FROM permissions p
        JOIN role_permissions rp ON p.id = rp.permission_id
        JOIN user_role_assignments ura ON rp.role_id = ura.role_id
        WHERE ura.user_id = ? AND p.is_active = TRUE
    ");
    $stmt->execute([$userId]);
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to easy lookup format
    $user['permissions'] = [];
    foreach ($permissions as $perm) {
        $key = $perm['module'] . '.' . $perm['action'];
        $user['permissions'][$key] = true;
    }
    
    // Check if super admin
    $user['is_super_admin'] = false;
    foreach ($user['roles'] as $role) {
        if ($role['code'] === 'SUPER_ADMIN') {
            $user['is_super_admin'] = true;
            break;
        }
    }
    
    return $user;
}

/**
 * Check if user is authenticated
 */
function isAuthenticated() {
    return isset($_SESSION['user']) && $_SESSION['user'] !== null;
}

/**
 * Require authentication (redirect if not logged in)
 */
function requireAuth() {
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Authentication required',
            'redirect' => '/login'
        ]);
        exit;
    }
}

// ============================================================
// PERMISSION CHECKING
// ============================================================

/**
 * Check if current user has a specific permission
 * 
 * @param string $module - Module name (e.g. 'catalog', 'quality')
 * @param string $action - Action name (e.g. 'view', 'create', 'edit')
 * @return bool
 */
function hasPermission($module, $action) {
    if (!isAuthenticated()) {
        return false;
    }
    
    $user = $_SESSION['user'];
    
    // Super admins have all permissions
    if ($user['is_super_admin']) {
        return true;
    }
    
    // Check permission
    $key = $module . '.' . $action;
    return isset($user['permissions'][$key]) && $user['permissions'][$key] === true;
}

/**
 * Require specific permission (exit with 403 if not authorized)
 * 
 * @param string $module
 * @param string $action
 */
function requirePermission($module, $action) {
    if (!hasPermission($module, $action)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Permission denied',
            'message' => "You don't have permission to $action $module"
        ]);
        exit;
    }
}

/**
 * Check if user has ANY of the specified permissions
 * 
 * @param array $permissions - Array of ['module' => 'action'] pairs
 * @return bool
 */
function hasAnyPermission($permissions) {
    foreach ($permissions as $module => $action) {
        if (hasPermission($module, $action)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if user has ALL of the specified permissions
 * 
 * @param array $permissions - Array of ['module' => 'action'] pairs
 * @return bool
 */
function hasAllPermissions($permissions) {
    foreach ($permissions as $module => $action) {
        if (!hasPermission($module, $action)) {
            return false;
        }
    }
    return true;
}

/**
 * Check if user has a specific role
 * 
 * @param string $roleCode - Role code (e.g. 'GOV_MANAGER', 'DATA_ENGINEER')
 * @return bool
 */
function hasRole($roleCode) {
    if (!isAuthenticated()) {
        return false;
    }
    
    $user = $_SESSION['user'];
    foreach ($user['roles'] as $role) {
        if ($role['code'] === $roleCode) {
            return true;
        }
    }
    return false;
}

/**
 * Get user's role codes
 * 
 * @return array
 */
function getUserRoles() {
    if (!isAuthenticated()) {
        return [];
    }
    
    $user = $_SESSION['user'];
    return array_map(function($role) {
        return $role['code'];
    }, $user['roles']);
}

// ============================================================
// PERMISSION FILTERS (For UI)
// ============================================================

/**
 * Filter data based on user permissions
 * Use this to show/hide UI elements based on permissions
 * 
 * @return array - User info with permissions for frontend
 */
function getUserPermissionsForUI() {
    if (!isAuthenticated()) {
        return [
            'authenticated' => false,
            'permissions' => []
        ];
    }
    
    $user = $_SESSION['user'];
    
    return [
        'authenticated' => true,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'full_name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'is_super_admin' => $user['is_super_admin']
        ],
        'roles' => $user['roles'],
        'permissions' => $user['permissions'],
        'can' => [
            // Dashboard
            'viewDashboard' => hasPermission('dashboard', 'view'),
            
            // Catalog
            'viewCatalog' => hasPermission('catalog', 'view'),
            'createCatalog' => hasPermission('catalog', 'create'),
            'editCatalog' => hasPermission('catalog', 'edit'),
            'deleteCatalog' => hasPermission('catalog', 'delete'),
            
            // Lineage
            'viewLineage' => hasPermission('lineage', 'view'),
            'createLineage' => hasPermission('lineage', 'create'),
            'editLineage' => hasPermission('lineage', 'edit'),
            'deleteLineage' => hasPermission('lineage', 'delete'),
            
            // Glossary
            'viewGlossary' => hasPermission('glossary', 'view'),
            'createGlossary' => hasPermission('glossary', 'create'),
            'editGlossary' => hasPermission('glossary', 'edit'),
            'deleteGlossary' => hasPermission('glossary', 'delete'),
            'approveGlossary' => hasPermission('glossary', 'approve'),
            
            // Governance
            'viewGovernance' => hasPermission('governance', 'view'),
            'createGovernance' => hasPermission('governance', 'create'),
            'editGovernance' => hasPermission('governance', 'edit'),
            'deleteGovernance' => hasPermission('governance', 'delete'),
            'approveGovernance' => hasPermission('governance', 'approve'),
            
            // Quality
            'viewQuality' => hasPermission('quality', 'view'),
            'createQuality' => hasPermission('quality', 'create'),
            'editQuality' => hasPermission('quality', 'edit'),
            'deleteQuality' => hasPermission('quality', 'delete'),
            'executeQuality' => hasPermission('quality', 'execute'),
            'approveQuality' => hasPermission('quality', 'approve'),
            
            // Reports
            'viewReports' => hasPermission('reports', 'view'),
            'createReports' => hasPermission('reports', 'create'),
            'exportReports' => hasPermission('reports', 'export'),
            
            // Users
            'viewUsers' => hasPermission('users', 'view'),
            'createUsers' => hasPermission('users', 'create'),
            'editUsers' => hasPermission('users', 'edit'),
            'deleteUsers' => hasPermission('users', 'delete'),
            
            // Roles
            'viewRoles' => hasPermission('roles', 'view'),
            'createRoles' => hasPermission('roles', 'create'),
            'editRoles' => hasPermission('roles', 'edit'),
            'deleteRoles' => hasPermission('roles', 'delete'),
            
            // Audit
            'viewAudit' => hasPermission('audit', 'view'),
            'exportAudit' => hasPermission('audit', 'export'),
            
            // Settings
            'viewSettings' => hasPermission('settings', 'view'),
            'editSettings' => hasPermission('settings', 'edit'),
            
            // API
            'accessAPI' => hasPermission('api', 'access')
        ]
    ];
}

// ============================================================
// USAGE EXAMPLES IN BACKEND.PHP
// ============================================================

/*

// Example 1: Require authentication
case 'catalog_tables':
    requireAuth();
    requirePermission('catalog', 'view');
    // ... your code

// Example 2: Check permission before action
case 'create_table':
    requireAuth();
    requirePermission('catalog', 'create');
    // ... create table code

// Example 3: Different permissions for different methods
case 'quality_rules':
    requireAuth();
    
    if ($method === 'GET') {
        requirePermission('quality', 'view');
    } elseif ($method === 'POST') {
        requirePermission('quality', 'create');
    } elseif ($method === 'PUT') {
        requirePermission('quality', 'edit');
    } elseif ($method === 'DELETE') {
        requirePermission('quality', 'delete');
    }
    // ... your code

// Example 4: Conditional logic based on role
if (hasRole('GOV_MANAGER')) {
    // Show governance-specific features
} elseif (hasRole('DATA_ENGINEER')) {
    // Show engineering-specific features
}

// Example 5: API endpoint to get user permissions (for frontend)
case 'user_permissions':
    requireAuth();
    echo json_encode([
        'success' => true,
        'data' => getUserPermissionsForUI()
    ]);
    break;

*/
