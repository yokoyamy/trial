<script>
(() => {
    'use strict';

    /*
     * =========================================================
     * 単一入口API通信
     * =========================================================
     *
     * 重要:
     * window.location.href から完全URLを組み立ててfetchするのではなく、
     * 現在表示している index.php を基準に相対URLを生成する。
     *
     * これにより、
     *
     * https://host/gojacic/.poc/draft/アンケートアプリ/index.php
     *
     * のように日本語ディレクトリ名を含む配置でも、
     * ブラウザ自身にURL解決を任せる。
     *
     * pathnameや物理ディレクトリ名を業務上の意味には使用しない。
     */

    const API_ENTRY_URL = new URL(
        window.location.pathname + window.location.search,
        window.location.origin
    );

    /*
     * 現在のindex.php自身をAPI入口として使用する。
     *
     * fetch側では相対URLを使用する。
     */
    function buildApiUrl(action) {
        const url = new URL(
            window.location.href
        );

        /*
         * 現在のページURLからqueryだけを作り直す。
         */
        url.search = '';
        url.hash = '';

        url.searchParams.set('action', action);

        /*
         * URL文字列を返すのではなく、
         * ブラウザが同一originとして扱えるURLオブジェクトを返す。
         */
        return url;
    }

    /*
     * =========================================================
     * 通信タイムアウト
     * =========================================================
     */
    const API_TIMEOUT_MS = 15000;

    /*
     * =========================================================
     * API共通通信関数
     * =========================================================
     */
    async function apiGet(action) {
        const url = buildApiUrl(action);

        const controller = new AbortController();

        const timeoutId = window.setTimeout(() => {
            controller.abort();
        }, API_TIMEOUT_MS);

        try {
            /*
             * credentials:
             *
             * CSRFトークンはPHPセッションに紐付いているため、
             * 同一オリジンCookieを必ず送信する。
             */
            const response = await fetch(url, {
                method: 'GET',

                headers: {
                    'Accept': 'application/json'
                },

                credentials: 'same-origin',

                cache: 'no-store',

                redirect: 'same-origin',

                mode: 'same-origin',

                signal: controller.signal
            });

            const contentType =
                response.headers.get('Content-Type') || '';

            const text = await response.text();

            /*
             * HTTPレスポンス自体が返っている場合は
             * Failed to fetchではなく、ここで詳細を表示できる。
             */
            if (text === '') {
                throw new ApiCommunicationError(
                    'EMPTY_RESPONSE',
                    'サーバーから空のレスポンスが返されました。',
                    response.status,
                    contentType,
                    url.toString()
                );
            }

            let data;

            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new ApiCommunicationError(
                    'INVALID_JSON',
                    'サーバーからJSONではないレスポンスが返されました。'
                    + '\nHTTP: ' + response.status
                    + '\nContent-Type: ' + contentType
                    + '\nレスポンス先頭: '
                    + text.slice(0, 500),
                    response.status,
                    contentType,
                    url.toString()
                );
            }

            /*
             * HTTPエラー
             */
            if (!response.ok) {
                const apiMessage =
                    data?.error?.message
                    || data?.message
                    || 'HTTPエラーが発生しました。';

                const apiCode =
                    data?.error?.code
                    || 'HTTP_ERROR';

                throw new ApiCommunicationError(
                    apiCode,
                    apiMessage,
                    response.status,
                    contentType,
                    url.toString()
                );
            }

            /*
             * API共通レスポンスのsuccess検証
             */
            if (
                !data
                || data.success !== true
            ) {
                const apiCode =
                    data?.error?.code
                    || 'API_ERROR';

                const apiMessage =
                    data?.error?.message
                    || 'API処理に失敗しました。';

                throw new ApiCommunicationError(
                    apiCode,
                    apiMessage,
                    response.status,
                    contentType,
                    url.toString()
                );
            }

            return data;

        } catch (error) {

            /*
             * AbortControllerによるタイムアウト
             */
            if (
                error instanceof DOMException
                && error.name === 'AbortError'
            ) {
                throw new ApiCommunicationError(
                    'TIMEOUT',
                    'API通信がタイムアウトしました。'
                    + '\nURL: ' + url.toString()
                    + '\nタイムアウト: '
                    + API_TIMEOUT_MS
                    + 'ms',
                    0,
                    '',
                    url.toString()
                );
            }

            /*
             * こちらで作成したAPIエラーはそのまま返す。
             */
            if (error instanceof ApiCommunicationError) {
                throw error;
            }

            /*
             * fetch()そのものが失敗した場合。
             *
             * ここが今回の
             * "Failed to fetch"
             * に該当する。
             */
            const message =
                error instanceof Error
                    ? error.message
                    : String(error);

            throw new ApiCommunicationError(
                'NETWORK_ERROR',
                'ブラウザからAPIへ接続できませんでした。'
                + '\nURL: ' + url.toString()
                + '\nHTTPメソッド: GET'
                + '\nエラー: ' + message
                + '\n\n確認項目:'
                + '\n・ApacheがこのURLを受け付けているか'
                + '\n・PHPが正常に実行されているか'
                + '\n・PHP Fatal Errorが発生していないか'
                + '\n・HTTPステータスが返っているか'
                + '\n・Content-Typeが返っているか'
                + '\n・HTTPS/HTTP混在になっていないか'
                + '\n・ブラウザのネットワークエラーがないか'
                + '\n・CORS等のブラウザ制約がないか'
                + '\n・証明書エラーが発生していないか',
                0,
                '',
                url.toString()
            );

        } finally {
            window.clearTimeout(timeoutId);
        }
    }

    /*
     * =========================================================
     * APIエラークラス
     * =========================================================
     */
    class ApiCommunicationError extends Error {

        constructor(
            code,
            message,
            httpStatus,
            contentType,
            url
        ) {
            super(message);

            this.name = 'ApiCommunicationError';

            this.code = code;
            this.httpStatus = httpStatus;
            this.contentType = contentType;
            this.url = url;
        }
    }

    /*
     * =========================================================
     * 画面要素
     * =========================================================
     */
    const button =
        document.getElementById('healthButton');

    const loading =
        document.getElementById('loading');

    const result =
        document.getElementById('result');

    if (
        !button
        || !loading
        || !result
    ) {
        /*
         * 画面側のDOM不備。
         * API通信より前に明示的に検出する。
         */
        console.error(
            'アンケートアプリ: 必要なDOM要素がありません。'
        );

        return;
    }

    /*
     * =========================================================
     * 処理中状態
     * =========================================================
     */
    let processing = false;

    function setProcessing(value) {

        processing = Boolean(value);

        button.disabled = processing;

        loading.classList.toggle(
            'active',
            processing
        );

        if (processing) {
            button.setAttribute(
                'aria-busy',
                'true'
            );
        } else {
            button.removeAttribute(
                'aria-busy'
            );
        }
    }

    /*
     * =========================================================
     * エラー表示
     * =========================================================
     */
    function renderApiError(error) {

        if (error instanceof ApiCommunicationError) {

            const lines = [
                '通信失敗',
                '',
                error.message,
                '',
                '--- 通信診断 ---',
                'API URL: ' + error.url,
                'HTTP: '
                    + (
                        error.httpStatus
                        ? String(error.httpStatus)
                        : '取得できませんでした'
                    ),
                'Content-Type: '
                    + (
                        error.contentType
                        || '取得できませんでした'
                    ),
                'APIエラーコード: '
                    + error.code
            ];

            result.textContent = lines.join('\n');

            return;
        }

        result.textContent =
            '予期しないエラーが発生しました。'
            + '\n'
            + (
                error instanceof Error
                    ? error.message
                    : String(error)
            );
    }

    /*
     * =========================================================
     * GET health
     * =========================================================
     */
    async function requestHealth() {

        if (processing) {
            return;
        }

        setProcessing(true);

        result.textContent =
            'API通信中…';

        try {

            const data =
                await apiGet('health');

            result.textContent =
                (data.message || '通信成功')
                + '\n\n'
                + JSON.stringify(
                    data.data,
                    null,
                    2
                );

        } catch (error) {

            renderApiError(error);

        } finally {

            /*
             * 成功・失敗・タイムアウトの
             * どの場合でも必ず解除する。
             */
            setProcessing(false);
        }
    }

    /*
     * =========================================================
     * イベント
     * =========================================================
     */
    button.addEventListener(
        'click',
        requestHealth
    );

    /*
     * =========================================================
     * CSRFトークン取得
     * =========================================================
     *
     * ページロード時にCSRFを取得する場合も、
     * healthと同じapiGet()を使用する。
     *
     * これによって、
     *
     * - URL生成
     * - credentials
     * - timeout
     * - JSON検証
     * - HTTPエラー
     * - Failed to fetch
     *
     * の処理がバラバラにならない。
     */
    async function getCsrfToken() {

        try {

            const data =
                await apiGet('csrf');

            if (
                !data.data
                || typeof data.data.csrfToken !== 'string'
                || data.data.csrfToken === ''
            ) {
                throw new ApiCommunicationError(
                    'CSRF_RESPONSE_INVALID',
                    'CSRFトークンを含む正常なAPIレスポンスが返されませんでした。',
                    200,
                    'application/json',
                    buildApiUrl('csrf').toString()
                );
            }

            /*
             * POST処理で利用できるよう、
             * windowへ一時的に保持する。
             *
             * 秘密情報ではなくCSRFトークンなので、
             * 同一ページのJavaScriptから利用する。
             */
            window.__csrfToken =
                data.data.csrfToken;

            return window.__csrfToken;

        } catch (error) {

            /*
             * CSRF取得失敗を
             * 「Failed to fetch」だけで終わらせない。
             */
            renderApiError(error);

            return null;
        }
    }

    /*
     * =========================================================
     * POST共通通信関数
     * =========================================================
     *
     * 今後の業務APIは必ずここを経由する。
     *
     * 重要:
     * 現在の画面URLをAPI入口として使用する。
     * /api/xxx 等の物理パスは生成しない。
     */
    async function apiPost(action, payload = {}) {

        const url =
            buildApiUrl(action);

        const csrfToken =
            window.__csrfToken
            || await getCsrfToken();

        if (
            typeof csrfToken !== 'string'
            || csrfToken === ''
        ) {
            throw new ApiCommunicationError(
                'CSRF_UNAVAILABLE',
                'CSRFトークンを取得できないため、POST処理を開始できません。',
                0,
                '',
                url.toString()
            );
        }

        const controller =
            new AbortController();

        const timeoutId =
            window.setTimeout(() => {
                controller.abort();
            }, API_TIMEOUT_MS);

        try {

            const body = {
                ...payload,
                action: action
            };

            const response =
                await fetch(url, {
                    method: 'POST',

                    headers: {
                        'Accept':
                            'application/json',

                        'Content-Type':
                            'application/json',

                        'X-CSRF-Token':
                            csrfToken
                    },

                    credentials:
                        'same-origin',

                    cache:
                        'no-store',

                    redirect:
                        'same-origin',

                    mode:
                        'same-origin',

                    body:
                        JSON.stringify(body),

                    signal:
                        controller.signal
                });

            const contentType =
                response.headers.get(
                    'Content-Type'
                ) || '';

            const text =
                await response.text();

            if (text === '') {
                throw new ApiCommunicationError(
                    'EMPTY_RESPONSE',
                    'POST APIから空のレスポンスが返されました。',
                    response.status,
                    contentType,
                    url.toString()
                );
            }

            let data;

            try {

                data =
                    JSON.parse(text);

            } catch (error) {

                throw new ApiCommunicationError(
                    'INVALID_JSON',
                    'POST APIからJSONではないレスポンスが返されました。'
                    + '\nHTTP: '
                    + response.status
                    + '\nContent-Type: '
                    + contentType
                    + '\nレスポンス先頭: '
                    + text.slice(0, 500),
                    response.status,
                    contentType,
                    url.toString()
                );
            }

            if (!response.ok) {

                throw new ApiCommunicationError(
                    data?.error?.code
                    || 'HTTP_ERROR',

                    data?.error?.message
                    || 'POST APIでHTTPエラーが発生しました。',

                    response.status,
                    contentType,
                    url.toString()
                );
            }

            if (
                !data
                || data.success !== true
            ) {

                throw new ApiCommunicationError(
                    data?.error?.code
                    || 'API_ERROR',

                    data?.error?.message
                    || 'POST API処理に失敗しました。',

                    response.status,
                    contentType,
                    url.toString()
                );
            }

            return data;

        } catch (error) {

            if (
                error instanceof DOMException
                && error.name === 'AbortError'
            ) {

                throw new ApiCommunicationError(
                    'TIMEOUT',
                    'POST API通信がタイムアウトしました。'
                    + '\nURL: '
                    + url.toString(),
                    0,
                    '',
                    url.toString()
                );
            }

            if (
                error instanceof ApiCommunicationError
            ) {
                throw error;
            }

            const message =
                error instanceof Error
                    ? error.message
                    : String(error);

            throw new ApiCommunicationError(
                'NETWORK_ERROR',
                'ブラウザからPOST APIへ接続できませんでした。'
                + '\nURL: '
                + url.toString()
                + '\nHTTPメソッド: POST'
                + '\nエラー: '
                + message
                + '\n\n確認項目:'
                + '\n・ApacheがこのURLを受け付けているか'
                + '\n・PHPが正常に実行されているか'
                + '\n・PHP Fatal Errorが発生していないか'
                + '\n・HTTPステータスが返っているか'
                + '\n・Content-Typeが返っているか'
                + '\n・HTTPS/HTTP混在になっていないか'
                + '\n・ブラウザのネットワークエラーがないか'
                + '\n・CORS等のブラウザ制約がないか'
                + '\n・証明書エラーが発生していないか',
                0,
                '',
                url.toString()
            );

        } finally {

            window.clearTimeout(
                timeoutId
            );
        }
    }

    /*
     * =========================================================
     * 起動時CSRF取得
     * =========================================================
     *
     * ここでは画面をブロックしない。
     * 取得失敗した場合は画面上に原因を表示する。
     */
    getCsrfToken();

    /*
     * =========================================================
     * 他の画面コードから利用できるようにする
     * =========================================================
     *
     * 業務API実装時には、
     *
     * apiGet('xxx')
     * apiPost('xxx', {...})
     *
     * を使用する。
     */
    window.QuestionnaireApi = {
        get: apiGet,
        post: apiPost,
        getCsrfToken: getCsrfToken
    };

})();
</script>