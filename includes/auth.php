<?php
// DormEase - Authentication Helper
// Uses prepared statements & sessions for secure authentication
require_once __DIR__ . '/config.php';

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Require authentication - redirect to login if not authenticated
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit();
    }
}

/**
 * Require specific role(s) to access a page.
 * Accepts a single role string or an array of allowed roles.
 * Redirects unauthorized users to their correct landing page.
 */
function requireRole($allowedRoles) {
    requireLogin();

    if (!is_array($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    $currentRole = $_SESSION['role'] ?? '';

    if (!in_array($currentRole, $allowedRoles)) {
        // Send them to whichever page their role should see
        header('Location: ' . getRedirectByRole($currentRole));
        exit();
    }
}

/**
 * Return the correct landing page URL based on user role.
 *   - Dormitory Owner & Caretaker → dashboard.php
 *   - Tenant                      → profile.php
 */
function getRedirectByRole($role) {
    switch ($role) {
        case 'Dormitory Owner':
        case 'Caretaker':
            return 'dashboard.php';
        case 'Tenant':
            return 'profile.php';
        default:
            return 'index.php';
    }
}

/**
 * Get current logged-in user data (prepared statement)
 */
function getCurrentUser() {
    global $conn;
    if (!isLoggedIn()) return null;

    $stmt = $conn->prepare("SELECT id, username, full_name, email, phone, role, avatar, is_active, created_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    return $user;
}

/**
 * Authenticate user with username and password (prepared statement).
 * Returns an associative array on success, or false on failure.
 *   - Also checks that the account is active (is_active = 1).
 */
function loginUser($username, $password) {
    global $conn;

    // Prepared statement prevents SQL injection
    $stmt = $conn->prepare("SELECT id, username, password, full_name, email, role, is_active FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $stmt->close();

        // Check if account is deactivated
        if ((int)$user['is_active'] === 0) {
            return 'disabled';
        }

        // Verify hashed password
        if (password_verify($password, $user['password'])) {
            // Regenerate session ID to prevent session fixation attacks
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email']     = $user['email'];
            $_SESSION['role']      = $user['role'];

            return 'success';
        }
    } else {
        $stmt->close();
    }

    return 'invalid';
}

/**
 * Logout user - destroy session and redirect to login
 */
function logoutUser() {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    session_destroy();
    header('Location: index.php');
    exit();
}

/**
 * Get the current page name for sidebar active state
 */
function getCurrentPage() {
    return basename($_SERVER['PHP_SELF'], '.php');
}
?>
