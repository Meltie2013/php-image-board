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
        $returnState = self::captureLoginReturnState();
        $errors = [];

        self::redirectAuthenticatedLoginUser($returnState['query_return_to']);

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
        {
            $errors = self::processLoginSubmission($returnState['submitted_return_to']);
        }

        self::renderLoginPage($errors, $returnState['submitted_return_to']);
    }

    /**
     * Capture and remember the safest login destination available for the request.
     *
     * The login form supports both a submitted return destination and one passed
     * on the query string. This helper keeps that normalization in one place so
     * the controller action can stay focused on the actual authentication flow.
     *
     * @return array{submitted_return_to: ?string, query_return_to: ?string}
     */
    private static function captureLoginReturnState(): array
    {
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

        return [
            'submitted_return_to' => $submittedReturnTo,
            'query_return_to' => $queryReturnTo,
        ];
    }

    /**
     * Redirect authenticated users away from the login form.
     *
     * @param string|null $queryReturnTo Explicit destination from the query string.
     * @return void
     */
    private static function redirectAuthenticatedLoginUser(?string $queryReturnTo): void
    {
        if (!self::getCurrentUserId())
        {
            return;
        }

        header('Location: ' . RedirectHelper::takeLoginDestination($queryReturnTo, '/profile/overview'));
        exit();
    }

    /**
     * Process one login form submission.
     *
     * @param string|null $submittedReturnTo Safe destination passed by the form.
     * @return array<int, string> Validation or authentication errors.
     */
    private static function processLoginSubmission(?string $submittedReturnTo): array
    {
        $errors = [];

        // Jail / rate-limit auth attempts (guest + member)
        RequestGuard::enforceAuthAttempt('login');

        $payload = self::readLoginFormPayload();
        self::validateLoginFormPayload($payload, $errors);

        $user = self::findLoginUserRecord($payload['email']);
        $userIdToCheck = self::applyLoginUserStateChecks($user, $errors);

        if (empty($errors))
        {
            self::attemptLoginWithUser($user, $userIdToCheck, $payload['password'], $submittedReturnTo, $errors);
        }

        return $errors;
    }

    /**
     * Read and sanitize the login form payload.
     *
     * @return array{email: string, password: string, csrf_token: string}
     */
    private static function readLoginFormPayload(): array
    {
        return [
            'email' => Security::sanitizeEmail($_POST['email'] ?? ''),
            'password' => Security::sanitizeString($_POST['password'] ?? ''),
            'csrf_token' => Security::sanitizeString($_POST['csrf_token'] ?? ''),
        ];
    }

    /**
     * Validate one login form payload before user-specific processing begins.
     *
     * @param array{email: string, password: string, csrf_token: string} $payload
     * @param array<int, string> $errors
     * @return void
     */
    private static function validateLoginFormPayload(array $payload, array &$errors): void
    {
        // Verify CSRF token to prevent cross-site request forgery
        if (!Security::verifyCsrfToken($payload['csrf_token']))
        {
            $errors[] = "Invalid request.";
        }

        // Ensure email and password fields are not empty
        if ($payload['email'] === '' || $payload['password'] === '')
        {
            $errors[] = "All fields required.";
        }
    }

    /**
     * Look up the login-ready user record for one email address.
     *
     * @param string $email
     * @return array<string, mixed>|null
     */
    private static function findLoginUserRecord(string $email): ?array
    {
        if ($email === '')
        {
            return null;
        }

        $user = UserModel::findLoginUserByEmail($email);
        return is_array($user) ? $user : null;
    }

    /**
     * Apply pre-password account status checks to one login user record.
     *
     * @param array<string, mixed>|null $user
     * @param array<int, string> $errors
     * @return int Valid user id for follow-up work, or 0 when unavailable.
     */
    private static function applyLoginUserStateChecks(?array $user, array &$errors): int
    {
        $userIdToCheck = TypeHelper::toInt($user['id'] ?? null) ?? 0;
        if ($userIdToCheck < 1)
        {
            return 0;
        }

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

        return $userIdToCheck;
    }

    /**
     * Attempt to complete the login flow once the user record is known.
     *
     * @param array<string, mixed>|null $user
     * @param int $userIdToCheck
     * @param string $password
     * @param string|null $submittedReturnTo
     * @param array<int, string> $errors
     * @return void
     */
    private static function attemptLoginWithUser(?array $user, int $userIdToCheck, string $password, ?string $submittedReturnTo, array &$errors): void
    {
        // If the user does not exist, avoid touching user-specific counters.
        // Still track the attempt for guest jail logic.
        if (!$user || $userIdToCheck < 1)
        {
            RequestGuard::recordAuthFailure('login', 'unknown_account');
            $errors[] = "Invalid email and/or password.";
            return;
        }

        if (!Security::verifyPassword($password, $user['password_hash']))
        {
            self::handleFailedLoginAttempt($user, $userIdToCheck, $errors);
            return;
        }

        // Enforce per-device account policy (optional)
        $policyError = DevicePolicy::enforceLogin($userIdToCheck);
        if ($policyError)
        {
            $errors[] = $policyError;
            return;
        }

        self::completeSuccessfulLogin($user, $userIdToCheck, $submittedReturnTo);
    }

    /**
     * Track one failed login attempt and apply any configured jail logic.
     *
     * @param array<string, mixed> $user
     * @param int $userIdToCheck
     * @param array<int, string> $errors
     * @return void
     */
    private static function handleFailedLoginAttempt(array $user, int $userIdToCheck, array &$errors): void
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
            return;
        }

        // Generic message avoids confirming whether the email exists
        $errors[] = "Invalid email and/or password.";
    }

    /**
     * Persist the successful login state and redirect the user onward.
     *
     * @param array<string, mixed> $user
     * @param int $userId
     * @param string|null $submittedReturnTo
     * @return void
     */
    private static function completeSuccessfulLogin(array $user, int $userId, ?string $submittedReturnTo): void
    {
        // Successful login: reset failed login counter
        UserModel::resetLoginState($userId);

        // Regenerate session ID to prevent session fixation
        SessionManager::regenerate();

        // Store important user details in session
        SessionManager::setMany([
            'user_id' => $userId,
            'username' => $user['username'],
            'user_avatar' => $user['avatar_path'],
            'user_date_of_birth' => $user['date_of_birth'] ?? null,
        ]);
        GroupPermissionHelper::syncSessionForUser($userId, true);

        // Record device fingerprint for this account
        DevicePolicy::recordForUser($userId);

        $rulesState = RulesHelper::getCurrentStateForUser($userId);
        if (!empty($rulesState['is_blocking']))
        {
            header('Location: /community/rules');
            exit();
        }

        // Redirect user to their intended destination
        header('Location: ' . RedirectHelper::takeLoginDestination($submittedReturnTo, '/profile/overview'));
        exit();
    }

    /**
     * Render the login form with the latest controller state.
     *
     * @param array<int, string> $errors
     * @param string|null $submittedReturnTo
     * @return void
     */
    private static function renderLoginPage(array $errors, ?string $submittedReturnTo): void
    {
        $template = self::initTemplate();
        $template->assign('csrf_token', Security::generateCsrfToken());
        $template->assign('error', $errors);
        $template->assign('return_to', $submittedReturnTo ?? RedirectHelper::getRememberedLoginDestination());
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
        self::ensureRegistrationServiceEnabled();

        // Prevent already logged-in users from registering again
        if (self::getCurrentUserId())
        {
            header('Location: /profile/overview');
            exit();
        }

        $errors = [];
        $success = '';

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
        {
            ['errors' => $errors, 'success' => $success] = self::processRegistrationSubmission();
        }

        self::renderRegisterPage($errors, $success);
    }

    /**
     * Enforce the runtime registration service toggle before rendering the form.
     *
     * @return void
     */
    private static function ensureRegistrationServiceEnabled(): void
    {
        $config = self::getConfig();
        $runtimeState = ControlServer::loadRuntimeState($config);
        if (ControlServer::serviceEnabled($config, 'register', $runtimeState))
        {
            return;
        }

        http_response_code(503);
        $template = self::initTemplate();
        $template->assign('title', 'Registration Temporarily Disabled');
        $template->assign('message', 'New account registration is temporarily unavailable.');
        $template->assign('submessage', 'Please try again later.');
        $template->render('errors/error_page.html');
        exit();
    }

    /**
     * Process one registration form submission.
     *
     * @return array{errors: array<int, string>, success: string}
     */
    private static function processRegistrationSubmission(): array
    {
        $errors = [];
        $success = '';

        // Jail / rate-limit auth attempts (guest + member)
        RequestGuard::enforceAuthAttempt('register');

        $payload = self::readRegistrationFormPayload();
        self::validateRegistrationPayload($payload, $errors);

        if (empty($errors))
        {
            self::validateRegistrationDuplicates($payload['username'], $payload['email'], $errors);
        }

        if (empty($errors))
        {
            $policyError = DevicePolicy::enforceRegister();
            if ($policyError)
            {
                $errors[] = $policyError;
            }
        }

        if (empty($errors))
        {
            $success = self::createPendingRegistration($payload['username'], $payload['email'], $payload['password']);
        }

        return [
            'errors' => $errors,
            'success' => $success,
        ];
    }

    /**
     * Read and sanitize the registration form payload.
     *
     * @return array{username: string, email: string, confirm_email: string, password: string, confirm_password: string, csrf_token: string}
     */
    private static function readRegistrationFormPayload(): array
    {
        return [
            'username' => Security::sanitizeString($_POST['username'] ?? ''),
            'email' => Security::sanitizeEmail($_POST['email'] ?? ''),
            'confirm_email' => Security::sanitizeEmail($_POST['confirm_email'] ?? ''),
            'password' => Security::sanitizeString($_POST['password'] ?? ''),
            'confirm_password' => Security::sanitizeString($_POST['confirm_password'] ?? ''),
            'csrf_token' => Security::sanitizeString($_POST['csrf_token'] ?? ''),
        ];
    }

    /**
     * Validate one registration form payload.
     *
     * @param array{username: string, email: string, confirm_email: string, password: string, confirm_password: string, csrf_token: string} $payload
     * @param array<int, string> $errors
     * @return void
     */
    private static function validateRegistrationPayload(array $payload, array &$errors): void
    {
        // --- Security checks ---
        if (!Security::verifyCsrfToken($payload['csrf_token']))
        {
            $errors[] = "Invalid request. Please refresh the page and try again.";
        }

        // --- Field validation ---
        if (
            $payload['username'] === ''
            || $payload['email'] === ''
            || $payload['confirm_email'] === ''
            || $payload['password'] === ''
            || $payload['confirm_password'] === ''
        )
        {
            $errors[] = "All fields are required.";
        }

        if (!preg_match('/^[a-zA-Z0-9_]{3,15}$/', $payload['username']))
        {
            $errors[] = "Username must be 3–15 characters long, letters, numbers, or underscores only.";
        }

        if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL))
        {
            $errors[] = "Invalid email format.";
        }

        if ($payload['email'] !== $payload['confirm_email'])
        {
            $errors[] = "Email addresses do not match.";
        }

        if ($payload['password'] !== $payload['confirm_password'])
        {
            $errors[] = "Passwords do not match.";
        }

        if (strlen($payload['password']) < 8)
        {
            $errors[] = "Password must be at least 8 characters long.";
        }
    }

    /**
     * Validate registration duplicates against existing users.
     *
     * @param string $username
     * @param string $email
     * @param array<int, string> $errors
     * @return void
     */
    private static function validateRegistrationDuplicates(string $username, string $email, array &$errors): void
    {
        $exists = UserModel::usernameOrEmailExists($username, $email);
        if (!$exists)
        {
            return;
        }

        // Common brute-force pattern: try to register on existing emails/usernames.
        RequestGuard::recordAuthFailure('register', 'duplicate');
        $errors[] = "That username or email is already in use.";
    }

    /**
     * Create one pending user account using the configured registration defaults.
     *
     * @param string $username
     * @param string $email
     * @param string $password
     * @return string Success message.
     */
    private static function createPendingRegistration(string $username, string $email, string $password): string
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

        return "Registration successful! Account pending approval.";
    }

    /**
     * Render the registration form with validation state and defaults.
     *
     * @param array<int, string> $errors
     * @param string $success
     * @return void
     */
    private static function renderRegisterPage(array $errors, string $success): void
    {
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
