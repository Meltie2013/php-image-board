<?php

/**
 * AuthController
 *
 * Handles all user authentication actions including:
 * - User login (with session and CSRF protection)
 * - User registration (with validation and password hashing)
 * - User logout (session destruction and redirection)
 *
 * This controller integrates with:
 * - SessionManager for session handling
 * - Security class for CSRF validation, sanitization, and password hashing
 * - Database for querying and inserting user records
 * - TemplateEngine for rendering views
 */
class AuthController
{
    /**
     * Handle user login requests.
     *
     * - Displays login form
     * - Validates form submission (CSRF, required fields)
     * - Authenticates user with username/email + password
     * - Sets session data on success
     * - Redirects to profile overview if already logged in
     *
     * @return void
     */
    public static function login(): void
    {
        $errors = [];

        // Load configuration settings
        $config = require __DIR__ . '/../config/config.php';

        // Check if user is already logged in
        $userId = SessionManager::get('user_id');
        if ($userId)
        {
            // Redirect authenticated users to their profile overview
            header('Location: /profile/overview');
            exit();
        }

        // Handle login form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            // Sanitize and retrieve submitted form fields
            $username  = Security::sanitizeString($_POST['username'] ?? '');
            $password  = $_POST['password'] ?? '';
            $csrfToken = $_POST['csrf_token'] ?? '';

            // Verify CSRF token to prevent cross-site request forgery
            if (!Security::verifyCsrfToken($csrfToken))
            {
                $errors[] = "Invalid CSRF token.";
            }

            // Ensure username/email and password fields are not empty
            if (empty($username) || empty($password))
            {
                $errors[] = "Please fill in all fields.";
            }

            // Attempt login only if no validation errors
            if (empty($errors))
            {
                // Fetch user by username
                $user = Database::fetch(
                    "SELECT * FROM app_users WHERE username = :username LIMIT 1",
                    ['username' => $username]
                );

                // Validate password against stored hash
                if ($user && Security::verifyPassword($password, $user['password_hash']))
                {
                    // Store important user details in session
                    SessionManager::set('user_id', $user['id']);
                    SessionManager::set('user_role', $user['role_id']);
                    SessionManager::set('username', $user['username']);
                    SessionManager::set('display_name', $user['display_name']);

                    // Update last login timestamp
                    Database::query("UPDATE app_users SET last_login = NOW() WHERE id = :id", ['id' => $user['id']]);

                    // Redirect user to profile overview
                    header('Location: /profile/overview');
                    exit();
                }
                else
                {
                    // Invalid login attempt
                    $errors[] = "Invalid username or password.";
                }
            }
        }

        // Initialize template engine for rendering login page
        $template = new TemplateEngine(__DIR__ . '/../templates', __DIR__ . '/../cache/templates', $config);

        // Clear template cache if disabled in config
        if (!empty($config['template']['disable_cache']))
        {
            $template->clearCache();
        }

        // Pass CSRF token and error messages to template
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('error', $errors);

        // Render login page
        $template->render('login.html');
    }

    /**
     * Handle user registration requests.
     *
     * - Displays registration form
     * - Validates form submission (CSRF, required fields, regex checks)
     * - Ensures username/email uniqueness
     * - Inserts new user into database
     * - Redirects to login on success
     *
     * @return void
     */
    public static function register(): void
    {
        $errors = [];
        $success = '';

        // Load configuration settings
        $config = require __DIR__ . '/../config/config.php';

        // Prevent already logged-in users from accessing registration
        $userId = SessionManager::get('user_id');
        if ($userId)
        {
            header('Location: /profile/overview');
            exit();
        }

        // Handle registration form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            // Sanitize and retrieve submitted form fields
            $username    = Security::sanitizeString($_POST['username'] ?? '');
            $email       = Security::sanitizeEmail($_POST['email'] ?? '');
            $cemail      = Security::sanitizeEmail($_POST['confirm_email'] ?? '');
            $password    = $_POST['password'] ?? '';
            $confirmPass = $_POST['confirm_password'] ?? '';
            $csrfToken   = $_POST['csrf_token'] ?? '';

            // Verify CSRF token
            if (!Security::verifyCsrfToken($csrfToken))
            {
                $errors[] = "Invalid CSRF token.";
            }

            // Validate input fields
            if (empty($username) || empty($email) || empty($cemail) || empty($password) || empty($confirmPass))
            {
                $errors[] = "All fields are required.";
            }
            elseif (!preg_match('/^[a-zA-Z0-9_]{3,15}$/', $username))
            {
                $errors[] = "Username must be 3-15 characters, letters, numbers, or underscore only.";
            }
            elseif ($password !== $confirmPass)
            {
                $errors[] = "Passwords do not match.";
            }
            else if ($email !== $cemail)
            {
                $errors[] = "Email address does not match";
            }

            // Check if username or email already exists
            if (empty($errors))
            {
                $exists = Database::fetch(
                    "SELECT id FROM app_users WHERE username = :username OR email = :email LIMIT 1",
                    ['username' => $username, 'email' => $email]
                );

                if ($exists)
                {
                    $errors[] = "Username or email already exists.";
                }
            }

            // Insert new user into database if no validation errors
            if (empty($errors))
            {
                // Hash password securely
                $hashedPassword = Security::hashPassword($password);

                // Insert new user with default role (member)
                Database::insert(
                    "INSERT INTO app_users (username, email, password_hash, role_id, created_at)
                     VALUES (:username, :email, :password_hash, :role_id, NOW())",
                    [
                        'username' => $username,
                        'email' => $email,
                        'password_hash' => $hashedPassword,
                        'role_id' => 3, // default group (member)
                    ]
                );

                // Success message for user feedback
                $success = "Registration successful! You can now log in.";
            }
        }

        // Initialize template engine for rendering registration page
        $template = new TemplateEngine(__DIR__ . '/../templates', __DIR__ . '/../cache/templates', $config);

        // Clear template cache if disabled in config
        if (!empty($config['template']['disable_cache']))
        {
            $template->clearCache();
        }

        // Pass CSRF token, errors, and success message to template
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('error', $errors);
        $template->assign('success', $success);

        // Render registration page
        $template->render('register.html');
    }

    /**
     * Log out the current user.
     *
     * - Destroys all session data
     * - Redirects user to login page
     *
     * @return void
     */
    public static function logout(): void
    {
        // Destroy all session data
        SessionManager::destroy();

        // Redirect to login page
        header('Location: /user/login');
        exit();
    }
}
