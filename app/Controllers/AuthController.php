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
class AuthController extends BaseController
{

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

        $submittedReturnTo = RedirectHelper::sanitizeInternalPath($_POST['return_to'] ?? null);
        $queryReturnTo = RedirectHelper::sanitizeInternalPath($_GET['return_to'] ?? null);

        if ($submittedReturnTo !== null)
        {
            RedirectHelper::rememberLoginDestination($submittedReturnTo);
        }
        else if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            $loginReturnTo = $queryReturnTo ?? RedirectHelper::getSafeRefererPath();
            if ($loginReturnTo !== null)
            {
                RedirectHelper::rememberLoginDestination($loginReturnTo);
            }
        }

        // Check if user is already logged in
        $userId = TypeHelper::toInt(SessionManager::get('user_id'));
        if ($userId)
        {
            // Redirect authenticated users to their intended destination
            header('Location: ' . RedirectHelper::takeLoginDestination($queryReturnTo, '/profile/overview'));
            exit();
        }

        // Handle login form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            // Jail / rate-limit auth attempts (guest + member)
            RequestGuard::enforceAuthAttempt('login');

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
            $user = UserModel::findLoginUserByEmail($email);

            // If user exists, enforce status and lockout checks before password validation
            $userIdToCheck = TypeHelper::toInt($user['id'] ?? null);
            if ($userIdToCheck)
            {
                // Block any account that is not active.
                $accountStatus = strtolower(TypeHelper::toString($user['status'] ?? '', allowEmpty: true) ?? '');
                $groupName = strtolower(TypeHelper::toString($user['group_name'] ?? '', allowEmpty: true) ?? '');
                if ($accountStatus !== 'active' || $groupName === 'banned')
                {
                    if ($groupName === 'banned')
                    {
                        $errors[] = "Account banned.";
                    }
                    else if ($accountStatus === 'suspended')
                    {
                        $errors[] = "Account suspended.";
                    }
                    else
                    {
                        $errors[] = "Account pending approval.";
                    }
                }

                // Check if the user is currently jailed (temporary block)
                if (RequestGuard::hasActiveUserDecision($userIdToCheck))
                {
                    $errors[] = "Try again later.";
                }

                // Check for permanent suspension (12 failed attempts)
                if ((TypeHelper::toInt($user['failed_logins'] ?? null) ?? 0) >= 12 && empty($errors))
                {
                    UserModel::suspendById($userIdToCheck);

                    $errors[] = "Account locked.";
                }
            }

            // If no errors so far, attempt password validation
            if (empty($errors))
            {
                // If the user does not exist, avoid touching user-specific counters.
                // Still track the attempt for guest jail logic.
                if (!$user)
                {
                    RequestGuard::recordAuthFailure('login', 'unknown_account');
                    $errors[] = "Invalid email and/or password.";
                }
                else
                {
                    if ($user && Security::verifyPassword($password, $user['password_hash']))
                    {
                        // Enforce per-device account policy (optional)
                        $policyError = DevicePolicy::enforceLogin($userIdToCheck);
                        if ($policyError)
                        {
                            $errors[] = $policyError;
                        }

                        if (empty($errors))
                        {
                            // Successful login: reset failed login counter
                            UserModel::resetLoginState($userIdToCheck);

                            // Regenerate session ID to prevent session fixation
                            SessionManager::regenerate();

                            // Store important user details in session
                            SessionManager::setMany([
                                'user_id' => $userIdToCheck,
                                'username' => $user['username'],
                                'user_avatar' => $user['avatar_path'],
                                'user_date_of_birth' => $user['date_of_birth'] ?? null,
                            ]);
                            GroupPermissionHelper::syncSessionForUser($userIdToCheck, true);

                            // Record device fingerprint for this account
                            DevicePolicy::recordForUser($userIdToCheck);

                            $rulesState = RulesHelper::getCurrentStateForUser($userIdToCheck);
                            if (!empty($rulesState['is_blocking']))
                            {
                                header('Location: /community/rules');
                                exit();
                            }

                            // Redirect user to their intended destination
                            header('Location: ' . RedirectHelper::takeLoginDestination($submittedReturnTo, '/profile/overview'));
                            exit();
                        }
                    }
                    else
                    {
                    // Track brute force attempts (including when the email exists)
                    RequestGuard::recordAuthFailure('login', 'invalid_credentials');

                    // Failed login: calculate attempt count
                    $failedLogins = TypeHelper::toInt($user['failed_logins'] ?? null) ?? 0;
                    $lastFailed = strtotime($user['last_failed_login'] ?? '0');
                    $now = time();
                    $config = self::getConfig();
                    $authCfg = $config['request_guard']['auth'] ?? [];

                    $resetWindow = TypeHelper::toInt($authCfg['failure_window_seconds'] ?? 600);
                    $threshold = TypeHelper::toInt($authCfg['failure_threshold'] ?? 10);
                    $cooldownSeconds = TypeHelper::toInt($authCfg['failure_cooldown_seconds'] ?? 900);

                    if (($now - $lastFailed) > $resetWindow)
                    {
                        $failedLogins = 1; // reset counter
                    }
                    else
                    {
                        $failedLogins++;
                    }

                    // Update user's failed login info
                    UserModel::updateFailedLoginState($userIdToCheck, $failedLogins);

                    // Jail user if threshold reached
                    if ($failedLogins >= $threshold)
                    {
                        // Jail the account (unified RequestGuard system)
                        RequestGuard::jailUser($userIdToCheck, 'failed_login_threshold', $cooldownSeconds);

                        $mins = TypeHelper::toFloat(ceil($cooldownSeconds / 60));
                        $errors[] = "Too many attempts. Wait {$mins} min.";
                    }
                    else
                    {
                        // Generic message avoids confirming whether the email exists
                        $errors[] = "Invalid email and/or password.";
                    }
                    }
                }
            }
        }

        // Initialize template engine for rendering login page
        $template = self::initTemplate();

        // Pass CSRF token and error messages to template
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('error', $errors);
        $template->assign('return_to', $submittedReturnTo ?? RedirectHelper::getRememberedLoginDestination());

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

        $config = self::getConfig();
        $runtimeState = ControlServer::loadRuntimeState($config);
        if (!ControlServer::serviceEnabled($config, 'register', $runtimeState))
        {
            http_response_code(503);
            $template = self::initTemplate();
            $template->assign('title', 'Registration Temporarily Disabled');
            $template->assign('message', 'New account registration is temporarily unavailable.');
            $template->assign('submessage', 'Please try again later.');
            $template->render('errors/error_page.html');
            return;
        }

        // Prevent already logged-in users from registering again
        $userId = TypeHelper::toInt(SessionManager::get('user_id'));
        if ($userId)
        {
            header('Location: /profile/overview');
            exit();
        }

        // Handle registration form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            // Jail / rate-limit auth attempts (guest + member)
            RequestGuard::enforceAuthAttempt('register');

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
                $exists = UserModel::usernameOrEmailExists($username, $email);

                if ($exists)
                {
                    // Common brute-force pattern: try to register on existing emails/usernames.
                    RequestGuard::recordAuthFailure('register', 'duplicate');
                    $errors[] = "That username or email is already in use.";
                }
            }

            // --- Register user ---
            if (empty($errors))
            {
                // Enforce per-device account policy (optional)
                $policyError = DevicePolicy::enforceRegister();
                if ($policyError)
                {
                    $errors[] = $policyError;
                }
            }

            if (empty($errors))
            {
                $hashedPassword = Security::hashPassword($password);

                $defaultGroup = GroupModel::getDefaultRegistrationGroup();
                $defaultGroupId = TypeHelper::toInt($defaultGroup['id'] ?? 0) ?? 0;
                if ($defaultGroupId < 1)
                {
                    $defaultGroupId = 6;
                }

                $newId = UserModel::createPendingUser($username, $email, $hashedPassword, $defaultGroupId, 'pending');

                if ($newId > 0)
                {
                    // Record device fingerprint for this account
                    DevicePolicy::recordForUser($newId);
                }

                $success = "Registration successful! Account pending approval.";
            }
        }

        // Render template
        $template = self::initTemplate();
        $defaultGroup = GroupModel::getDefaultRegistrationGroup();
        $template->assign('registration_default_group_name', TypeHelper::toString($defaultGroup['name'] ?? 'Member'));
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
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            http_response_code(405);
            header('Location: /index.php');
            exit();
        }

        $csrfToken = Security::sanitizeString($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($csrfToken))
        {
            http_response_code(403);
            header('Location: /index.php');
            exit();
        }

        $redirectTo = RedirectHelper::sanitizeInternalPath($_POST['return_to'] ?? null)
            ?? RedirectHelper::getSafeRefererPath()
            ?? '/index.php';

        // Destroy all session data
        SessionManager::destroy();

        // Redirect to the page the user came from when possible
        header('Location: ' . $redirectTo);
        exit();
    }
}
