<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// If already logged in, redirect to the correct page based on role
if (isLoggedIn()) {
    header('Location: ' . getRedirectByRole($_SESSION['role'] ?? ''));
    exit();
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $result = loginUser($username, $password);

        if ($result === 'success') {
            // Redirect based on role
            header('Location: ' . getRedirectByRole($_SESSION['role']));
            exit();
        } elseif ($result === 'disabled') {
            $error = 'Your account has been deactivated. Please contact the administrator.';
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-logo">
            <i class="fas fa-building"></i>
        </div>
        <h1><?php echo APP_NAME; ?></h1>
        <p><?php echo APP_TAGLINE; ?></p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="username"><i class="fas fa-user"></i> Username</label>
                <input type="text" id="username" name="username" placeholder="Enter your username"
                       value="<?php echo htmlspecialchars($username ?? ''); ?>" required autofocus>
            </div>
            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>
    </div>

    <script src="assets/js/script.js"></script>
</body>
</html>
