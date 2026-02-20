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
     * Cached config for controller usage.
     *
     * Stored statically so config is loaded once per request and reused across
     * controller methods (login/register/logout) without repeated disk reads.
     *
     * @var array
     */
    private static array $config;

    /**
     * Load and cache config once per request.
     *
     * The config file is read on first use, then retained for subsequent calls.
     * This keeps controller methods focused on request logic rather than setup.
     *
     * @return array
     */
    private static function getConfig(): array
    {
        if (empty(self::$config))
        {
            self::$config = require __DIR__ . '/../config/config.php';
        }

        return self::$config;
    }

    /**
     * Initialize template engine with optional cache clearing.
     *
     * Creates a TemplateEngine instance pointed at the project's templates/cache
     * directories. When template caching is disabled in config, the compiled cache
     * is cleared to ensure changes are reflected immediately (development-friendly).
     *
     * @return TemplateEngine
     */
    private static function initTemplate(): TemplateEngine
    {
        $config = self::getConfig();
        $template = new TemplateEngine(__DIR__ . '/../templates', __DIR__ . '/../cache/templates', $config);
        if (!empty($config['template']['disable_cache']))
        {
            $template->clearCache();
        }

        return $template;
    }

    /**
     * Handle user login requests.
     *
     * Flow:
     * - If already authenticated, redirect to the profile overview
     * - On POST, validate CSRF token and required fields
     * - Look up user record + role, then enforce status/lockout rules
     * - Verify password and establish session on success
     * - Track failed attempts and apply temporary jail / escalation on failure
     *
     * Security considerations:
     * - CSRF validation for form submission
     * - Session regeneration on successful login to mitigate fixation
     * - Lockout + jail logic to slow brute-force attempts
     *
     * @return void
     */
    public static function login(): void
    {
        $errors = [];

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
            $email  = Security::sanitizeEmail($_POST['email'] ?? '');
            $password  = Security::sanitizeString($_POST['password'] ?? '');
            $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');

            // Verify CSRF token to prevent cross-site request forgery
            if (!Security::verifyCsrfToken($csrfToken))
            {
                $errors[] = "Invalid request.";
            }

            // Ensure email and password fields are not empty
            if (empty($email) || empty($password))
            {
                $errors[] = "All fields required.";
            }

            // Fetch user by email with role details
            $user = Database::fetch("SELECT u.id, u.username, u.email, u.password_hash, u.avatar_path, u.failed_logins, u.last_failed_login, u.status, r.name AS role_name
                 FROM app_users u INNER JOIN app_roles r ON u.role_id = r.id WHERE u.email = :email LIMIT 1",
                ['email' => $email]
            );

            $userIdToCheck = $user['id'] ?? null;

            // If user exists, enforce status and lockout checks before password validation
            if ($userIdToCheck)
            {
                // Block suspended accounts immediately
                if ($user['status'] === 'suspended')
                {
                    $errors[] = "Account suspended.";
                }

                // Check if the user is currently jailed (temporary block)
                $blocked = Database::fetch("SELECT blocked_until FROM user_security_events WHERE user_id = :uid ORDER BY blocked_until DESC LIMIT 1",
                    ['uid' => $userIdToCheck]
                );

                if ($blocked && strtotime($blocked['blocked_until']) > time())
                {
                    $errors[] = "Try again later.";
                }

                // Check for permanent suspension (6+ failed attempts)
                if ((int)$user['failed_logins'] >= 6 && empty($errors))
                {
                    Database::query("UPDATE app_users SET status = 'suspended' WHERE id = :id",
                        ['id' => $userIdToCheck]
                    );

                    $errors[] = "Account locked.";
                }
            }

            // If no errors so far, attempt password validation
            if (empty($errors))
            {
                if ($user && Security::verifyPassword($password, $user['password_hash']))
                {
                    // Successful login: reset failed login counter
                    Database::query("UPDATE app_users SET failed_logins = 0, last_failed_login = NULL, last_login = NOW() WHERE id = :id",
                        ['id' => $userIdToCheck]
                    );

                    // Regenerate session ID to prevent session fixation
                    SessionManager::regenerate();

                    // Store important user details in session
                    SessionManager::set('user_id', $user['id']);
                    SessionManager::set('user_role', $user['role_name']);
                    SessionManager::set('username', $user['username']);
                    SessionManager::set('user_avatar', $user['avatar_path']);

                    // Redirect user to profile overview
                    header('Location: /profile/overview');
                    exit();
                }
                else
                {
                    // Failed login: calculate attempt count
                    $failedLogins = (int)$user['failed_logins'];
                    $lastFailed = strtotime($user['last_failed_login'] ?? '0');
                    $now = time();
                    $resetWindow = 600; // 10 minutes
                    $threshold = 3;     // attempts before temporary jail

                    if (($now - $lastFailed) > $resetWindow)
                    {
                        $failedLogins = 1; // reset counter
                    }
                    else
                    {
                        $failedLogins++;
                    }

                    // Update user's failed login info
                    Database::query("UPDATE app_users SET failed_logins = :fails, last_failed_login = NOW() WHERE id = :id",
                        ['fails' => $failedLogins, 'id' => $userIdToCheck]
                    );

                    // Jail user if threshold reached
                    if ($failedLogins >= $threshold)
                    {
                        $blockedUntil = date('Y-m-d H:i:s', $now + $resetWindow); // 10 min jail
                        Database::query("INSERT INTO user_security_events (user_id, ip, event_type, blocked_until) VALUES (:uid, :ip, :event, :blocked)",
                            [
                                'uid' => $userIdToCheck,
                                'ip' => inet_pton($_SERVER['REMOTE_ADDR'] ?? ''),
                                'event' => 'failed_login_threshold',
                                'blocked' => $blockedUntil
                            ]
                        );

                        $errors[] = "Too many attempts. Wait 10 min.";
                    }
                    else
                    {
                        // Generic message avoids confirming whether the email exists
                        $errors[] = "Invalid email and/or password.";
                    }
                }
            }
        }

        // Initialize template engine for rendering login page
        $template = self::initTemplate();

        // Pass CSRF token and error messages to template
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('error', $errors);

        // Render login page
        $template->render('login.html');
    }

    /**
     * Handle user registration requests.
     *
     * Flow:
     * - If already authenticated, redirect to the profile overview
     * - On POST, validate CSRF token and required fields
     * - Validate username/email/password requirements
     * - Verify email confirmation matches
     * - Check for existing username/email duplicates
     * - Create the account in a "pending" state for approval workflows
     *
     * Security considerations:
     * - CSRF validation for form submission
     * - Password hashing via Security helper (algorithm configurable)
     * - Avoids leaking sensitive DB errors to the user
     *
     * @return void
     */
    public static function register(): void
    {
        $errors = [];
        $success = '';

        // Prevent already logged-in users from registering again
        $userId = SessionManager::get('user_id');
        if ($userId)
        {
            header('Location: /profile/overview');
            exit();
        }

        // Handle registration form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            // Sanitize inputs
            $username    = Security::sanitizeString($_POST['username'] ?? '');
            $email       = Security::sanitizeEmail($_POST['email'] ?? '');
            $cemail      = Security::sanitizeEmail($_POST['confirm_email'] ?? '');
            $password    = Security::sanitizeString($_POST['password'] ?? '');
            $confirmPass = Security::sanitizeString($_POST['confirm_password'] ?? '');
            $csrfToken   = Security::sanitizeString($_POST['csrf_token'] ?? '');

            // --- Security checks ---
            if (!Security::verifyCsrfToken($csrfToken))
            {
                $errors[] = "Invalid request. Please refresh the page and try again.";
            }

            // --- Field validation ---
            if (empty($username) || empty($email) || empty($cemail) || empty($password) || empty($confirmPass))
            {
                $errors[] = "All fields are required.";
            }

            if (!preg_match('/^[a-zA-Z0-9_]{3,15}$/', $username))
            {
                $errors[] = "Username must be 3–15 characters long, letters, numbers, or underscores only.";
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            {
                $errors[] = "Invalid email format.";
            }

            if ($email !== $cemail)
            {
                $errors[] = "Email addresses do not match.";
            }

            if ($password !== $confirmPass)
            {
                $errors[] = "Passwords do not match.";
            }

            if (strlen($password) < 8)
            {
                $errors[] = "Password must be at least 8 characters long.";
            }

            // --- Check for duplicates ---
            if (empty($errors))
            {
                $exists = Database::fetch("SELECT id FROM app_users WHERE username = :username OR email = :email LIMIT 1",
                    ['username' => $username, 'email' => $email]
                );

                if ($exists)
                {
                    $errors[] = "That username or email is already in use.";
                }
            }

            // --- Register user ---
            if (empty($errors))
            {
                $hashedPassword = Security::hashPassword($password);

                Database::insert("INSERT INTO app_users (username, email, password_hash, role_id, status, created_at) VALUES (:username, :email, :password_hash, :role_id, :status, NOW())",
                    [
                        'username'      => $username,
                        'email'         => $email,
                        'password_hash' => $hashedPassword,
                        'role_id'       => 3, // default role = member
                        'status'        => 'pending'
                    ]
                );

                $success = "Registration successful! Account pending approval.";
            }
        }

        // Render template
        $template = self::initTemplate();
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('error', $errors);
        $template->assign('success', $success);
        $template->render('register.html');
    }

    /**
     * Log out the current user.
     *
     * - Destroys all session data
     * - Redirects user to index page
     *
     * @return void
     */
    public static function logout(): void
    {
        // Destroy all session data
        SessionManager::destroy();

        // Redirect to index page
        header('Location: /index.php');
        exit();
    }
}
