<?php
declare(strict_types=1);


/*
 * アンケート管理システム
 * Single Entry: index.php
 */

const DATA_DIR = __DIR__ . '/data';

const DEFAULT_SCREEN = 'admin-survey-list';

const ALLOWED_SCREENS = [
    'admin-survey-list',
    'admin-survey-edit',
    'admin-preview',
    'admin-send',
    'admin-aggregation',
    'admin-kintone',
    'admin-mail',
    'answer',
    'confirm',
    'complete',
];

const ALLOWED_ACTIONS = [
    'get_csrf',
    'get_surveys',
    'get_survey',
    'save_survey',
    'delete_survey',
    'duplicate_survey',
    'change_survey_status',
    'save_response',
    'send_mail',
    'send_test_mail',
    'save_kintone_settings',
    'kintone_test',
    'kintone_get_fields',
    'kintone_sync',
    'save_mail_settings',
    'export_csv',
    'export_pdf',
];

const CHANGE_ACTIONS = [
    'save_survey',
    'delete_survey',
    'duplicate_survey',
    'change_survey_status',
    'save_response',
    'send_mail',
    'send_test_mail',
    'save_kintone_settings',
    'kintone_sync',
    'save_mail_settings',
];

const FETCH_TIMEOUT_MS = 15000;

bootstrap();

function bootstrap(): void
{
    configureRuntime();
    registerErrorHandlers();

    /*
     * CORS判定は業務処理より前。
     * ただしOrigin:nullを無条件許可しない。
     */
    handleCors();

    /*
     * preflightには副作用を発生させない。
     */
    if (requestMethod() === 'OPTIONS') {
        handlePreflight();
    }

    startSession();
    ensureDataDirectory();

    if (requestMethod() === 'GET') {
        handleGetRequest();
        return;
    }

    if (requestMethod() === 'POST') {
        handlePostRequest();
        return;
    }

    apiError(
        405,
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。'
    );
}