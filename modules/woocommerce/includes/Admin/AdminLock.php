<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Admin;

/**
 * Optional second lock on the plugin's admin page: besides the WordPress capability, a user name
 * and password of the plugin's own, so that only the people running the integration can see or
 * change it, not every administrator of the store.
 *
 * Off unless both constants are defined in wp-config.php (they can't be changed from the admin):
 *
 *     define('TUDORSYNC_ADMIN_USER', 'name');
 *     define('TUDORSYNC_ADMIN_PASSWORD_HASH', '$2y$10$...'); // php -r 'echo password_hash("...", PASSWORD_DEFAULT);'
 *
 * Unlocking sets a cookie signed with the WordPress salts, tied to the WordPress user and to the
 * password hash (changing the password ends every session), valid SESSION_SECONDS. Five failed
 * attempts from an IP block it for LOCKOUT_SECONDS. WP-CLI is not affected.
 */
final class AdminLock
{
    public const UNLOCK_ACTION = 'tudorsync_unlock';
    public const LOCK_ACTION = 'tudorsync_lock';

    private const COOKIE = 'tudorsync_admin_unlock';
    private const SESSION_SECONDS = 8 * HOUR_IN_SECONDS;
    private const MAX_FAILURES = 5;
    private const LOCKOUT_SECONDS = 15 * MINUTE_IN_SECONDS;
    private const FAILURES_TRANSIENT = 'tudorsync_unlock_fail_';

    public function isEnabled(): bool
    {
        return $this->user() !== '' && $this->passwordHash() !== '';
    }

    public function isUnlocked(): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $cookie = isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) ? $_COOKIE[self::COOKIE] : '';
        $parts = explode('|', $cookie);

        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return false;
        }

        [$userId, $expires, $signature] = $parts;

        return (int) $userId === get_current_user_id()
            && (int) $expires > time()
            && hash_equals($this->sign((int) $userId, (int) $expires), $signature);
    }

    /**
     * admin-post handler of the unlock form (capability and nonce already checked).
     */
    public function handleUnlock(): never
    {
        $failuresKey = self::FAILURES_TRANSIENT . md5($this->clientIp());
        $failures = (int) get_transient($failuresKey);

        if ($failures >= self::MAX_FAILURES) {
            Admin::redirect('settings', false, __('Too many failed attempts: try again in 15 minutes.', 'tudorsync'));
        }

        // phpcs:disable WordPress.Security.NonceVerification -- Admin::guard()
        $user = trim((string) wp_unslash($_POST['tudorsync_user'] ?? ''));
        $password = (string) wp_unslash($_POST['tudorsync_password'] ?? '');
        // phpcs:enable

        if (!$this->isEnabled() || !hash_equals($this->user(), $user) || !password_verify($password, $this->passwordHash())) {
            set_transient($failuresKey, $failures + 1, self::LOCKOUT_SECONDS);
            Admin::redirect('settings', false, __('Wrong user name or password.', 'tudorsync'));
        }

        delete_transient($failuresKey);
        $expires = time() + self::SESSION_SECONDS;
        $this->setCookie(get_current_user_id() . '|' . $expires . '|' . $this->sign(get_current_user_id(), $expires), $expires);

        Admin::redirect('settings', true, __('Unlocked.', 'tudorsync'));
    }

    public function handleLock(): never
    {
        $this->setCookie('', time() - YEAR_IN_SECONDS);

        wp_safe_redirect(admin_url());
        exit;
    }

    public function renderForm(): void
    {
        echo '<div class="tudorsync-box" style="max-width:420px"><h2>' . esc_html__('Locked', 'tudorsync') . '</h2>';
        echo '<p>' . esc_html__('This page has its own user name and password.', 'tudorsync') . '</p>';
        View::formStart(self::UNLOCK_ACTION);
        printf(
            '<p><label>%s<br><input type="text" name="tudorsync_user" class="regular-text" autocomplete="username" required></label></p>'
            . '<p><label>%s<br><input type="password" name="tudorsync_password" class="regular-text" autocomplete="current-password" required></label></p>',
            esc_html__('User name', 'tudorsync'),
            esc_html__('Password', 'tudorsync'),
        );
        submit_button(__('Unlock', 'tudorsync'));
        echo '</form></div>';
    }

    public function renderLockButton(): void
    {
        View::formStart(self::LOCK_ACTION, 'class="tudorsync-inline-form" style="float:right"');
        submit_button(__('Lock', 'tudorsync'), 'secondary small', 'submit', false);
        echo '</form>';
    }

    private function user(): string
    {
        return defined('TUDORSYNC_ADMIN_USER') ? trim((string) constant('TUDORSYNC_ADMIN_USER')) : '';
    }

    private function passwordHash(): string
    {
        return defined('TUDORSYNC_ADMIN_PASSWORD_HASH') ? trim((string) constant('TUDORSYNC_ADMIN_PASSWORD_HASH')) : '';
    }

    private function sign(int $userId, int $expires): string
    {
        return hash_hmac('sha256', $userId . '|' . $expires . '|' . $this->user() . '|' . $this->passwordHash(), wp_salt('auth'));
    }

    private function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => ADMIN_COOKIE_PATH,
            'domain' => COOKIE_DOMAIN ?: '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private function clientIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

        return $ip !== '' ? $ip : 'unknown';
    }
}
