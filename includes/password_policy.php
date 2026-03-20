<?php
/**
 * Centralized password policy validation
 */

require_once __DIR__ . '/../config/security_config.php';

/**
 * Validates password against policy - reduces brute-force success (stronger = harder to guess)
 */
function validate_password_policy($password, &$errors = []) {
    $errors = [];

    // Length bounds
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = "Password must be at least " . PASSWORD_MIN_LENGTH . " characters.";
    }
    if (strlen($password) > PASSWORD_MAX_LENGTH) {
        $errors[] = "Password must not exceed " . PASSWORD_MAX_LENGTH . " characters.";
    }
    if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter.";
    }
    if (PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter.";
    }
    if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number.";
    }
    if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?`~]/', $password)) {
        $errors[] = "Password must contain at least one special character (!@#$%^&* etc.).";
    }

    // Block common passwords - easily guessed by attackers
    $common = ['password', 'password1', 'password123', 'admin', 'admin123', 'letmein', 'welcome', 'qwerty123', '12345678', 'abc123', 'monkey', 'dragon', 'master', 'login', 'sunshine', 'princess', 'football', 'iloveyou', 'admin1234', 'welcome1'];
    if (in_array(strtolower($password), $common)) {
        $errors[] = "This password is too common. Please choose a stronger password.";
    }

    return empty($errors);
}

function get_password_requirements_text() {
    $reqs = [
        "At least " . PASSWORD_MIN_LENGTH . " characters",
        "At least one uppercase letter",
        "At least one lowercase letter",
        "At least one number",
        "At least one special character (!@#$%^&* etc.)",
    ];
    return implode(", ", $reqs);
}
