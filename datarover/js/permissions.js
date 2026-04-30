/**
 * DataRover - Frontend Permission System
 * 
 * Purpose: Client-side permission checking and UI control
 * Usage: Include in app.js or load separately
 */

// ============================================================
// GLOBAL PERMISSION STATE
// ============================================================

var UserPermissions = {
    authenticated: false,
    user: null,
    roles: [],
    permissions: {},
    can: {},
    loaded: false
};

// ============================================================
// LOAD USER PERMISSIONS FROM BACKEND
// ============================================================

/**
 * Load current user permissions from backend
 * Call this on app initialization
 */
async function loadUserPermissions() {
    try {
        var response = await fetch(API + '?action=user_permissions');
        var json = await response.json();
        
        if (json.success && json.data) {
            UserPermissions = json.data;
            UserPermissions.loaded = true;
            
            console.log('✅ User permissions loaded:', UserPermissions);
            
            // Apply UI restrictions
            applyPermissionBasedUI();
            
            return true;
        } else {
            console.warn('⚠️ Failed to load permissions');
            UserPermissions.loaded = false;
            return false;
        }
    } catch (error) {
        console.error('❌ Error loading permissions:', error);
        UserPermissions.loaded = false;
        return false;
    }
}

// ============================================================
// PERMISSION CHECKING FUNCTIONS
// ============================================================

/**
 * Check if user has a specific permission
 * @param {string} module - Module name (e.g. 'catalog', 'quality')
 * @param {string} action - Action name (e.g. 'view', 'create')
 * @returns {boolean}
 */
function hasPermission(module, action) {
    if (!UserPermissions.loaded || !UserPermissions.authenticated) {
        return false;
    }
    
    // Super admins have all permissions
    if (UserPermissions.user && UserPermissions.user.is_super_admin) {
        return true;
    }
    
    var key = module + '.' + action;
    return UserPermissions.permissions[key] === true;
}

/**
 * Check using the 'can' helper object
 * @param {string} capability - Capability name (e.g. 'editCatalog')
 * @returns {boolean}
 */
function can(capability) {
    if (!UserPermissions.loaded || !UserPermissions.authenticated) {
        return false;
    }
    
    return UserPermissions.can[capability] === true;
}

/**
 * Check if user has a specific role
 * @param {string} roleCode - Role code (e.g. 'GOV_MANAGER')
 * @returns {boolean}
 */
function hasRole(roleCode) {
    if (!UserPermissions.loaded || !UserPermissions.authenticated) {
        return false;
    }
    
    return UserPermissions.roles.some(function(role) {
        return role.code === roleCode;
    });
}

/**
 * Check if user has ANY of the specified roles
 * @param {array} roleCodes - Array of role codes
 * @returns {boolean}
 */
function hasAnyRole(roleCodes) {
    return roleCodes.some(function(code) {
        return hasRole(code);
    });
}

/**
 * Check if user is Data Governance focused
 * @returns {boolean}
 */
function isDataGovernance() {
    return hasAnyRole(['GOV_MANAGER', 'COMPLIANCE', 'DATA_STEWARD']);
}

/**
 * Check if user is Data Quality focused
 * @returns {boolean}
 */
function isDataQuality() {
    return hasRole('QUALITY_MANAGER');
}

/**
 * Check if user is Technical/Engineering
 * @returns {boolean}
 */
function isTechnical() {
    return hasRole('DATA_ENGINEER');
}

// ============================================================
// UI CONTROL BASED ON PERMISSIONS
// ============================================================

/**
 * Apply permission-based UI restrictions
 * Hides/disables elements user doesn't have permission for
 */
function applyPermissionBasedUI() {
    console.log('🔒 Applying permission-based UI restrictions...');
    
    // Hide elements with data-permission attribute
    document.querySelectorAll('[data-permission]').forEach(function(el) {
        var perm = el.getAttribute('data-permission');
        var parts = perm.split('.');
        
        if (parts.length === 2) {
            if (!hasPermission(parts[0], parts[1])) {
                el.style.display = 'none';
            }
        }
    });
    
    // Hide elements with data-role attribute
    document.querySelectorAll('[data-role]').forEach(function(el) {
        var requiredRole = el.getAttribute('data-role');
        
        if (!hasRole(requiredRole)) {
            el.style.display = 'none';
        }
    });
    
    // Disable elements with data-can attribute
    document.querySelectorAll('[data-can]').forEach(function(el) {
        var capability = el.getAttribute('data-can');
        
        if (!can(capability)) {
            el.style.display = 'none';
            // Or disable instead: el.disabled = true;
        }
    });
    
    // Show role-specific welcome messages
    showRoleBasedWelcome();
}

/**
 * Show role-specific welcome message or dashboard customization
 */
function showRoleBasedWelcome() {
    if (!UserPermissions.authenticated) return;
    
    var message = '';
    var icon = '👤';
    
    if (hasRole('SUPER_ADMIN')) {
        message = 'Super Administrator';
        icon = '👑';
    } else if (hasRole('GOV_MANAGER')) {
        message = 'Data Governance Manager';
        icon = '🔒';
    } else if (hasRole('QUALITY_MANAGER')) {
        message = 'Data Quality Manager';
        icon = '✅';
    } else if (hasRole('DATA_STEWARD')) {
        message = 'Data Steward';
        icon = '📚';
    } else if (hasRole('DATA_ENGINEER')) {
        message = 'Data Engineer';
        icon = '🛠️';
    } else if (hasRole('ANALYST')) {
        message = 'Business Analyst';
        icon = '📊';
    } else if (hasRole('COMPLIANCE')) {
        message = 'Compliance Officer';
        icon = '⚖️';
    } else if (hasRole('VIEWER')) {
        message = 'Report Viewer';
        icon = '👀';
    }
    
    console.log(icon + ' User role: ' + message);
    
    // Update UI with role info (if element exists)
    var roleDisplay = document.getElementById('userRoleDisplay');
    if (roleDisplay && message) {
        roleDisplay.textContent = icon + ' ' + message;
    }
}

// ============================================================
// HELPER FUNCTIONS FOR UI ELEMENTS
// ============================================================

/**
 * Create a button only if user has permission
 * @param {string} label - Button label
 * @param {string} module - Module name
 * @param {string} action - Action name
 * @param {function} onClick - Click handler
 * @param {string} className - CSS class (optional)
 * @returns {string} HTML or empty string
 */
function permissionButton(label, module, action, onClick, className) {
    if (!hasPermission(module, action)) {
        return '';
    }
    
    className = className || 'btn';
    var functionName = onClick.name || 'handleClick';
    
    return '<button class="' + className + '" onclick="' + functionName + '()">' + label + '</button>';
}

/**
 * Show/hide menu items based on permissions
 */
function filterMenuByPermissions() {
    // Data Catalog menu
    if (!can('viewCatalog')) {
        hideMenuItem('catalog');
    }
    
    // Data Lineage menu
    if (!can('viewLineage')) {
        hideMenuItem('lineage');
    }
    
    // Business Glossary menu
    if (!can('viewGlossary')) {
        hideMenuItem('glossary');
    }
    
    // Governance menu
    if (!can('viewGovernance')) {
        hideMenuItem('governance');
    }
    
    // Data Quality menu
    if (!can('viewQuality')) {
        hideMenuItem('quality');
    }
    
    // Users menu (admin only)
    if (!can('viewUsers')) {
        hideMenuItem('users');
    }
    
    // Settings menu (admin only)
    if (!can('viewSettings')) {
        hideMenuItem('settings');
    }
}

function hideMenuItem(page) {
    var menuItem = document.querySelector('[data-page="' + page + '"]');
    if (menuItem) {
        menuItem.style.display = 'none';
    }
}

// ============================================================
// GOVERNANCE vs NON-GOVERNANCE UI CUSTOMIZATION
// ============================================================

/**
 * Customize dashboard based on user role category
 */
function customizeDashboardByRole() {
    if (!UserPermissions.loaded) return;
    
    var dashboard = document.getElementById('dashboardContent');
    if (!dashboard) return;
    
    // Data Governance focused users
    if (isDataGovernance()) {
        // Highlight governance metrics
        highlightGovernanceMetrics();
        showGovernanceQuickActions();
    }
    
    // Data Quality focused users
    if (isDataQuality()) {
        // Highlight quality metrics
        highlightQualityMetrics();
        showQualityQuickActions();
    }
    
    // Technical users
    if (isTechnical()) {
        // Highlight technical metrics
        highlightTechnicalMetrics();
        showTechnicalQuickActions();
    }
    
    // Viewers - simplified dashboard
    if (hasRole('VIEWER')) {
        hideComplexDashboardElements();
    }
}

function highlightGovernanceMetrics() {
    console.log('📊 Highlighting governance metrics for governance user');
    // Add governance-specific dashboard widgets
}

function showGovernanceQuickActions() {
    console.log('⚡ Showing governance quick actions');
    // Add quick action buttons for governance tasks
}

function highlightQualityMetrics() {
    console.log('📊 Highlighting quality metrics for quality user');
}

function showQualityQuickActions() {
    console.log('⚡ Showing quality quick actions');
}

function highlightTechnicalMetrics() {
    console.log('📊 Highlighting technical metrics for engineer');
}

function showTechnicalQuickActions() {
    console.log('⚡ Showing technical quick actions');
}

function hideComplexDashboardElements() {
    console.log('🔒 Simplifying dashboard for viewer');
}

// ============================================================
// INITIALIZATION
// ============================================================

/**
 * Initialize permission system
 * Call this when app loads
 */
async function initPermissionSystem() {
    console.log('🔐 Initializing permission system...');
    
    var success = await loadUserPermissions();
    
    if (success) {
        filterMenuByPermissions();
        customizeDashboardByRole();
        console.log('✅ Permission system initialized');
    } else {
        console.warn('⚠️ Permission system initialization failed');
    }
}

// ============================================================
// USAGE EXAMPLES IN APP.JS
// ============================================================

/*

// Example 1: In app initialization
window.addEventListener('DOMContentLoaded', function() {
    initPermissionSystem();
});

// Example 2: Show button only if user has permission
if (can('createCatalog')) {
    html += '<button onclick="showAddTableModal()">➕ Add Table</button>';
}

// Example 3: Check permission before action
function deleteTable(layer, table) {
    if (!can('deleteCatalog')) {
        alert('You don\'t have permission to delete tables');
        return;
    }
    // ... delete logic
}

// Example 4: Role-specific UI
if (hasRole('GOV_MANAGER')) {
    // Show governance dashboard
    showGovernanceDashboard();
} else if (hasRole('DATA_ENGINEER')) {
    // Show engineering dashboard
    showEngineeringDashboard();
}

// Example 5: Data-attributes in HTML
<button data-permission="catalog.create">Add Table</button>
<div data-role="GOV_MANAGER">Governance Panel</div>
<button data-can="executeQuality">Run Quality Check</button>

*/

// ============================================================
// EXPORT FOR USE IN APP.JS
// ============================================================

// If using modules:
// export { UserPermissions, hasPermission, can, hasRole, initPermissionSystem };
