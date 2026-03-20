<?php
/**
 * CAPTCHA helper - Math-based CAPTCHA (works offline, no API keys)
 * For production, consider Google reCAPTCHA (set RECAPTCHA_SITE_KEY in config)
 */

require_once __DIR__ . '/../config/security_config.php';

/**
 * Generates a math CAPTCHA - blocks automated bots (no API keys needed)
 */
function generate_captcha() {
    $num1 = rand(1, 10);
    $num2 = rand(1, 10);
    $operators = ['+', '-', '×'];
    $op = $operators[array_rand($operators)];

    switch ($op) {
        case '+':
            $answer = $num1 + $num2;
            break;
        case '-':
            if ($num1 < $num2) {
                $tmp = $num1;
                $num1 = $num2;
                $num2 = $tmp;
            }
            $answer = $num1 - $num2;
            break;
        case '×':
            $num1 = rand(1, 5);
            $num2 = rand(1, 5);
            $answer = $num1 * $num2;
            break;
    }

    $_SESSION['captcha_answer'] = (string)$answer; // Store for verification, single-use
    return ['question' => "$num1 $op $num2 = ?", 'placeholder' => 'Enter result'];
}

/** Compares user input to expected answer; clears session (one-time use) */
function verify_captcha($user_input) {
    $expected = $_SESSION['captcha_answer'] ?? null;
    unset($_SESSION['captcha_answer']);
    return $expected !== null && trim((string)$user_input) === $expected;
}

/** Returns true when CAPTCHA should be shown (e.g. after N failed logins) */
function captcha_required($failed_attempts = 0) {
    if (!empty(RECAPTCHA_SITE_KEY)) {
        return true; // Always use reCAPTCHA if configured
    }
    return $failed_attempts >= CAPTCHA_AFTER_FAILED_ATTEMPTS;
}

/** Renders CAPTCHA HTML (math or reCAPTCHA) when required */
function render_captcha($failed_attempts = 0) {
    if (!captcha_required($failed_attempts)) {
        return '';
    }

    if (!empty(RECAPTCHA_SITE_KEY)) {
        $script = '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
        return $script . '<div class="g-recaptcha form-group" data-sitekey="' . htmlspecialchars(RECAPTCHA_SITE_KEY) . '"></div>';
    }

    $captcha = generate_captcha();

    $html = '<div class="form-group captcha-group">';
    $html .= '<p>Security check: Solve this</p>';
    $html .= '<p class="captcha-question">' . htmlspecialchars($captcha['question']) . '</p>';
    $html .= '<input type="text" name="captcha_answer" placeholder="' . htmlspecialchars($captcha['placeholder']) . '" required autocomplete="off" class="captcha-input" />';
    $html .= '</div>';
    return $html;
}

/** Validates CAPTCHA on form submit - Google reCAPTCHA or math fallback */
function validate_captcha_response() {
    if (!empty(RECAPTCHA_SITE_KEY) && !empty(RECAPTCHA_SECRET_KEY)) {
        $token = $_POST['g-recaptcha-response'] ?? '';
        if (empty($token)) {
            return false;
        }
        $post = http_build_query([
            'secret'   => RECAPTCHA_SECRET_KEY,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        $opts = ['http' => [
            'method'  => 'POST',
            'header'  => 'Content-type: application/x-www-form-urlencoded',
            'content' => $post
        ]];
        $context = stream_context_create($opts);
        $response = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
        $data = $response ? json_decode($response) : null;
        return $data && !empty($data->success);
    }
    return verify_captcha($_POST['captcha_answer'] ?? '');
}
