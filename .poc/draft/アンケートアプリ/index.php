<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| アンケート管理システム
| 最小構造・通信基盤テスト版
|--------------------------------------------------------------------------
|
| このファイルだけで動作する。
|
| GET:
|   index.php?action=health
|
| POST:
|   index.php
|   {
|       "action": "health"
|   }
|
| ブラウザ側通信:
|   fetch() は使用しない。
|   XMLHttpRequest を使用する。
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| PHP 基本設定
|--------------------------------------------------------------------------
*/

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header_remove('X-Powered-By');


/*
|--------------------------------------------------------------------------
| 共通JSONレスポンス
|--------------------------------------------------------------------------
*/

function successResponse(
    array $data = [],
    string $message = '',
    int $status = 200
): never {
    http_response_code($status);

    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


function errorResponse(
    string $code,
    string $message,
    int $status = 400,
    array $extra = []
): never {
    http_response_code($status);

    header('Content-Type: application/json; charset=UTF-8');

    $error = [
        'code' => $code,
        'message' => $message,
    ];

    if ($extra !== []) {
        $error['detail'] = $extra;
    }

    echo json_encode(
        [
            'success' => false,
            'error' => $error,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| POST JSON取得
|--------------------------------------------------------------------------
*/

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    try {
        $data = json_decode(
            $raw,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable $e) {
        error_log(
            'JSON decode error: ' . $e->getMessage()
        );

        errorResponse(
            'INVALID_JSON',
            'リクエストJSONが不正です。',
            400
        );
    }

    if (!is_array($data)) {
        errorResponse(
            'INVALID_JSON',
            'JSONオブジェクトを指定してください。',
            400
        );
    }

    return $data;
}


/*
|--------------------------------------------------------------------------
| API判定
|--------------------------------------------------------------------------
|
| action が存在する場合はAPIとして処理する。
|
*/

$action = isset($_GET['action'])
    ? (string) $_GET['action']
    : '';

$method = strtoupper(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
);


/*
|--------------------------------------------------------------------------
| API処理
|--------------------------------------------------------------------------
*/

if ($action !== '') {

    /*
    |--------------------------------------------------------------------------
    | GET
    |--------------------------------------------------------------------------
    */

    if ($method === 'GET') {

        switch ($action) {

            /*
            |--------------------------------------------------------------------------
            | health
            |--------------------------------------------------------------------------
            */

            case 'health':

                successResponse(
                    [
                        'status' => 'ok',
                        'phpVersion' => PHP_VERSION,
                        'method' => $method,
                        'timestamp' => date('c'),
                    ],
                    ''
                );


            /*
            |--------------------------------------------------------------------------
            | 未定義GET action
            |--------------------------------------------------------------------------
            */

            default:

                errorResponse(
                    'INVALID_ACTION',
                    '指定されたactionは存在しません。',
                    400
                );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | POST
    |--------------------------------------------------------------------------
    */

    if ($method === 'POST') {

        $body = getJsonBody();

        /*
        |--------------------------------------------------------------------------
        | POST action
        |--------------------------------------------------------------------------
        |
        | 要件上は
        |
        | POST index.php
        | {
        |     "action": "xxx"
        | }
        |
        | だが、現在はGETのaction指定にも対応しておく。
        |
        */

        $postAction = isset($body['action'])
            ? (string) $body['action']
            : '';

        if ($postAction === '') {
            errorResponse(
                'INVALID_ACTION',
                'POSTのactionが指定されていません。',
                400
            );
        }


        switch ($postAction) {

            /*
            |--------------------------------------------------------------------------
            | POST health
            |--------------------------------------------------------------------------
            |
            | 本番業務では使用しない。
            | 通信基盤確認用。
            |
            */

            case 'health':

                successResponse(
                    [
                        'status' => 'ok',
                        'phpVersion' => PHP_VERSION,
                        'method' => $method,
                        'receivedAction' => $postAction,
                        'receivedBody' => $body,
                        'timestamp' => date('c'),
                    ],
                    ''
                );


            /*
            |--------------------------------------------------------------------------
            | 未定義POST action
            |--------------------------------------------------------------------------
            */

            default:

                errorResponse(
                    'INVALID_ACTION',
                    '指定されたPOST actionは存在しません。',
                    400
                );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | その他HTTPメソッド
    |--------------------------------------------------------------------------
    */

    errorResponse(
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。',
        405
    );
}


/*
|--------------------------------------------------------------------------
| ここから通常画面
|--------------------------------------------------------------------------
|
| action が無い場合だけHTMLを返す。
|
*/

$baseUrl = (function (): string {

    /*
     * 現在のindex.php自身を基準にする。
     *
     * 物理ディレクトリをハードコードしない。
     */

    $scriptName = (string) (
        $_SERVER['SCRIPT_NAME'] ?? '/index.php'
    );

    return $scriptName;

})();

?>
<!DOCTYPE html>
<html lang="ja">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>アンケート管理システム - 通信基盤テスト</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
            background: #f5f6f8;
            color: #222;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        h1 {
            margin-top: 0;
        }

        .card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        button {
            appearance: none;
            border: 0;
            border-radius: 6px;
            padding: 10px 16px;
            background: #1565c0;
            color: #fff;
            font-size: 14px;
            cursor: pointer;
            margin-right: 8px;
            margin-bottom: 8px;
        }

        button:hover {
            background: #0d47a1;
        }

        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        pre {
            white-space: pre-wrap;
            word-break: break-word;
            background: #111;
            color: #eee;
            padding: 16px;
            border-radius: 6px;
            min-height: 100px;
            overflow: auto;
        }

        .status {
            padding: 12px;
            border-radius: 6px;
            background: #eee;
            margin-bottom: 12px;
        }

        .success {
            background: #e8f5e9;
            color: #1b5e20;
        }

        .error {
            background: #ffebee;
            color: #b71c1c;
        }

        .loading {
            background: #fff8e1;
            color: #795548;
        }

        code {
            background: #eee;
            padding: 2px 5px;
            border-radius: 3px;
        }

    </style>

</head>


<body>

<div class="container">

    <h1>アンケート管理システム</h1>

    <div class="card">

        <h2>通信基盤テスト</h2>

        <p>
            この画面では業務処理を行わず、
            Apache → PHP → index.php → HTTP通信
            の成立だけを確認します。
        </p>

        <p>
            ブラウザ通信には
            <strong>fetch()を使用しません。</strong>
        </p>

        <button
            type="button"
            id="getHealthButton"
        >
            GET API テスト
        </button>

        <button
            type="button"
            id="postHealthButton"
        >
            POST API テスト
        </button>

    </div>


    <div class="card">

        <h2>通信状態</h2>

        <div
            id="status"
            class="status"
        >
            未実行
        </div>

        <pre id="result">未実行</pre>

    </div>


    <div class="card">

        <h2>接続先</h2>

        <pre id="connectionInfo"></pre>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| ブラウザ側通信
|--------------------------------------------------------------------------
|
| fetch() は使わない。
|
| XMLHttpRequestだけを使用する。
|
*/


(function () {

    'use strict';


    /*
    |--------------------------------------------------------------------------
    | DOM
    |--------------------------------------------------------------------------
    */

    const getButton =
        document.getElementById('getHealthButton');

    const postButton =
        document.getElementById('postHealthButton');

    const statusElement =
        document.getElementById('status');

    const resultElement =
        document.getElementById('result');

    const connectionInfoElement =
        document.getElementById('connectionInfo');


    /*
    |--------------------------------------------------------------------------
    | 現在のindex.php URL
    |--------------------------------------------------------------------------
    |
    | PHPから渡されたSCRIPT_NAMEを使用。
    |
    | 例:
    |
    | /gojacic/.poc/draft/アンケートアプリ/index.php
    |
    */

    const INDEX_URL =
        <?= json_encode(
            $baseUrl,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT
        ) ?>;


    /*
    |--------------------------------------------------------------------------
    | API URL生成
    |--------------------------------------------------------------------------
    */

    function createApiUrl(action) {

        const url = new URL(
            INDEX_URL,
            window.location.origin
        );

        url.search = '';

        url.searchParams.set(
            'action',
            action
        );

        return url.href;
    }


    /*
    |--------------------------------------------------------------------------
    | 画面表示
    |--------------------------------------------------------------------------
    */

    function setStatus(
        message,
        type = ''
    ) {

        statusElement.textContent = message;

        statusElement.className =
            'status ' + type;
    }


    function showResult(data) {

        resultElement.textContent =
            JSON.stringify(
                data,
                null,
                2
            );
    }


    /*
    |--------------------------------------------------------------------------
    | 通信中UI
    |--------------------------------------------------------------------------
    */

    function setLoading(isLoading) {

        getButton.disabled = isLoading;

        postButton.disabled = isLoading;

        if (isLoading) {

            setStatus(
                '通信中...',
                'loading'
            );

        }

    }


    /*
    |--------------------------------------------------------------------------
    | 共通API通信
    |--------------------------------------------------------------------------
    |
    | fetchではなくXMLHttpRequest。
    |
    */

    function callApi(
        method,
        action,
        body = null
    ) {

        return new Promise(
            function (resolve, reject) {

                const url =
                    createApiUrl(action);

                const xhr =
                    new XMLHttpRequest();


                /*
                |--------------------------------------------------------------------------
                | 接続
                |--------------------------------------------------------------------------
                */

                try {

                    xhr.open(
                        method,
                        url,
                        true
                    );

                } catch (error) {

                    reject({
                        code: 'OPEN_ERROR',
                        message:
                            error.message,
                        url: url,
                        method: method,
                        status: null,
                        contentType: null,
                        response: null
                    });

                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | タイムアウト
                |--------------------------------------------------------------------------
                */

                xhr.timeout = 10000;


                /*
                |--------------------------------------------------------------------------
                | Request Header
                |--------------------------------------------------------------------------
                */

                xhr.setRequestHeader(
                    'Accept',
                    'application/json'
                );


                if (method !== 'GET') {

                    xhr.setRequestHeader(
                        'Content-Type',
                        'application/json; charset=UTF-8'
                    );

                }


                /*
                |--------------------------------------------------------------------------
                | 正常完了
                |--------------------------------------------------------------------------
                */

                xhr.onload =
                    function () {

                        const status =
                            xhr.status;

                        const contentType =
                            xhr.getResponseHeader(
                                'Content-Type'
                            );

                        const responseText =
                            xhr.responseText;


                        let responseJson = null;


                        if (responseText !== '') {

                            try {

                                responseJson =
                                    JSON.parse(
                                        responseText
                                    );

                            } catch (error) {

                                responseJson = null;

                            }

                        }


                        resolve({

                            code:
                                status >= 200 &&
                                status < 300
                                    ? 'OK'
                                    : 'HTTP_ERROR',

                            message:
                                status >= 200 &&
                                status < 300
                                    ? 'HTTP通信成功'
                                    : 'HTTPエラー',

                            url: url,

                            method: method,

                            status: status,

                            contentType:
                                contentType,

                            response:
                                responseText,

                            json:
                                responseJson

                        });

                    };


                /*
                |--------------------------------------------------------------------------
                | ネットワークエラー
                |--------------------------------------------------------------------------
                */

                xhr.onerror =
                    function () {

                        reject({

                            code:
                                'NETWORK_ERROR',

                            message:
                                'XMLHttpRequestによるネットワーク通信に失敗しました。',

                            detail:
                                'status=0 の場合、HTTPレスポンスを取得する前に通信が失敗しています。',

                            url: url,

                            method: method,

                            status:
                                xhr.status,

                            contentType:
                                null,

                            response:
                                null

                        });

                    };


                /*
                |--------------------------------------------------------------------------
                | タイムアウト
                |--------------------------------------------------------------------------
                */

                xhr.ontimeout =
                    function () {

                        reject({

                            code:
                                'TIMEOUT',

                            message:
                                'HTTP通信がタイムアウトしました。',

                            url: url,

                            method: method,

                            status:
                                xhr.status,

                            contentType:
                                null,

                            response:
                                null

                        });

                    };


                /*
                |--------------------------------------------------------------------------
                | 中断
                |--------------------------------------------------------------------------
                */

                xhr.onabort =
                    function () {

                        reject({

                            code:
                                'ABORTED',

                            message:
                                'HTTP通信が中断されました。',

                            url: url,

                            method: method,

                            status:
                                xhr.status,

                            contentType:
                                null,

                            response:
                                null

                        });

                    };


                /*
                |--------------------------------------------------------------------------
                | 送信
                |--------------------------------------------------------------------------
                */

                try {

                    if (body === null) {

                        xhr.send();

                    } else {

                        xhr.send(
                            JSON.stringify(body)
                        );

                    }

                } catch (error) {

                    reject({

                        code:
                            'SEND_ERROR',

                        message:
                            error.message,

                        url: url,

                        method: method,

                        status:
                            xhr.status,

                        contentType:
                            null,

                        response:
                            null

                    });

                }

            }
        );

    }


    /*
    |--------------------------------------------------------------------------
    | GET
    |--------------------------------------------------------------------------
    */

    async function testGet() {

        setLoading(true);

        showResult({
            status: '通信開始'
        });


        const url =
            createApiUrl('health');


        try {

            const result =
                await callApi(
                    'GET',
                    'health'
                );


            if (
                result.code === 'OK' &&
                result.json !== null
            ) {

                setStatus(
                    'GET API 成功',
                    'success'
                );

            } else {

                setStatus(
                    'GET API HTTPエラー',
                    'error'
                );

            }


            showResult(result);


        } catch (error) {

            setStatus(
                'GET API 通信失敗',
                'error'
            );

            showResult(error);

        } finally {

            setLoading(false);

        }

    }


    /*
    |--------------------------------------------------------------------------
    | POST
    |--------------------------------------------------------------------------
    */

    async function testPost() {

        setLoading(true);

        showResult({
            status: '通信開始'
        });


        try {

            const result =
                await callApi(
                    'POST',
                    'health',
                    {
                        action: 'health'
                    }
                );


            if (
                result.code === 'OK' &&
                result.json !== null
            ) {

                setStatus(
                    'POST API 成功',
                    'success'
                );

            } else {

                setStatus(
                    'POST API HTTPエラー',
                    'error'
                );

            }


            showResult(result);


        } catch (error) {

            setStatus(
                'POST API 通信失敗',
                'error'
            );

            showResult(error);

        } finally {

            setLoading(false);

        }

    }


    /*
    |--------------------------------------------------------------------------
    | ボタンイベント
    |--------------------------------------------------------------------------
    */

    getButton.addEventListener(
        'click',
        testGet
    );


    postButton.addEventListener(
        'click',
        testPost
    );


    /*
    |--------------------------------------------------------------------------
    | 接続情報表示
    |--------------------------------------------------------------------------
    */

    connectionInfoElement.textContent =
        JSON.stringify(
            {
                browserOrigin:
                    window.location.origin,

                currentUrl:
                    window.location.href,

                currentPath:
                    window.location.pathname,

                indexUrl:
                    new URL(
                        INDEX_URL,
                        window.location.origin
                    ).href,

                getHealthUrl:
                    createApiUrl('health'),

                communication:
                    'XMLHttpRequest',

                fetch:
                    false

            },
            null,
            2
        );


})();

</script>

</body>
</html>