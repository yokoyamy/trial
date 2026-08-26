<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| アンケート管理システム専用セッション設定
|--------------------------------------------------------------------------
|
| 同居する他アプリケーションとセッションを共有しない。
|
*/

const APP_SESSION_NAME = 'survey_management_session';

session_name(APP_SESSION_NAME);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/survey/',   // 実際の公開配置パスに合わせる
    'domain'   => '',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

/*
|--------------------------------------------------------------------------
| アプリ専用セッションキー
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['survey_app'])) {
    $_SESSION['survey_app'] = [
        'csrf_token' => bin2hex(random_bytes(32)),
    ];
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

function getCsrfToken(): string
{
    return $_SESSION['survey_app']['csrf_token'];
}

function verifyCsrfToken(?string $token): bool
{
    if ($token === null || $token === '') {
        return false;
    }

    return hash_equals(
        $_SESSION['survey_app']['csrf_token'],
        $token
    );
}