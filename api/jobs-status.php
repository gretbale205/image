<?php
/**
 * jobs-status.php
 *
 * GET:
 *   ?job_id=123
 */

error_reporting(E_ALL);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header(
    'Content-Type: application/json; charset=utf-8'
);


try {

    require_once __DIR__ . '/../app/helpers.php';
    require_once __DIR__ . '/../app/db.php';

} catch (Throwable $e) {

    http_response_code(500);

    error_log(
        '[jobs-status] Bağımlılık: '
        . $e->getMessage()
    );

    echo json_encode(
        [
            'success' => false,
            'error' => 'Sunucu yapılandırma hatası.'
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


/* -----------------------------------------------------------------------
 * JSON response
 * --------------------------------------------------------------------- */

if (!function_exists('json_response')) {

    function json_response(
        $data,
        $status = 200
    ) {

        http_response_code($status);

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}


/* -----------------------------------------------------------------------
 * job_id
 * --------------------------------------------------------------------- */

$jobIdRaw =
    $_GET['job_id'] ?? null;

if (!is_scalar($jobIdRaw)) {

    json_response(
        [
            'success' => false,
            'error' => 'Geçersiz job_id'
        ],
        400
    );
}

$jobId = filter_var(
    $jobIdRaw,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

if ($jobId === false) {

    json_response(
        [
            'success' => false,
            'error' => 'Geçersiz job_id'
        ],
        400
    );
}


/* -----------------------------------------------------------------------
 * Job
 * --------------------------------------------------------------------- */

try {

    $db = getDB();

    $stmt = $db->prepare(
        "
        SELECT
            id,
            sku,
            user_ref,
            status,
            stage,
            progress,
            error_msg,
            file_count,
            created_at,
            updated_at,
            approved_at
        FROM jobs
        WHERE id = ?
        LIMIT 1
        "
    );

    $stmt->execute(
        [$jobId]
    );

    $job = $stmt->fetch();

} catch (Throwable $e) {

    error_log(
        '[jobs-status] DB: '
        . $e->getMessage()
    );

    json_response(
        [
            'success' => false,
            'error' => 'Sunucu hatası.'
        ],
        500
    );
}


if (!$job) {

    json_response(
        [
            'success' => false,
            'error' => 'İş bulunamadı'
        ],
        404
    );
}


/* -----------------------------------------------------------------------
 * Normalize
 * --------------------------------------------------------------------- */

$job['id'] =
    (int) $job['id'];

$job['file_count'] =
    (int) ($job['file_count'] ?? 0);

$job['count'] =
    $job['file_count'];

if ($job['progress'] !== null) {

    $job['progress'] =
        max(
            0,
            min(
                100,
                (int) $job['progress']
            )
        );
}


/* -----------------------------------------------------------------------
 * Files
 * --------------------------------------------------------------------- */

$previewFile = null;
$masterFile = null;

try {

    $fileStmt = $db->prepare(
        "
        SELECT
            id,
            role,
            path
        FROM job_files
        WHERE job_id = ?
          AND role IN ('preview', 'master')
        ORDER BY id DESC
        "
    );

    $fileStmt->execute(
        [$jobId]
    );

    $files =
        $fileStmt->fetchAll();

    foreach ($files as $file) {

        $role =
            $file['role'] ?? '';

        if (
            $role === 'preview'
            && $previewFile === null
        ) {

            $previewFile = $file;
        }

        if (
            $role === 'master'
            && $masterFile === null
        ) {

            $masterFile = $file;
        }

        if (
            $previewFile !== null
            && $masterFile !== null
        ) {

            break;
        }
    }

} catch (Throwable $e) {

    error_log(
        '[jobs-status] files: '
        . $e->getMessage()
    );
}


/* -----------------------------------------------------------------------
 * Image URL
 *
 * ÖNEMLİ:
 * index.php:
 * /pro/image/index.php
 *
 * Buradan:
 * /pro/image/api/get-image.php
 *
 * otomatik oluşturulur.
 * --------------------------------------------------------------------- */

$scriptDir =
    rtrim(
        str_replace(
            '\\',
            '/',
            dirname(
                $_SERVER['SCRIPT_NAME']
            )
        ),
        '/'
    );

$previewUrl = null;
$masterUrl = null;


if (
    $previewFile
    && !empty($previewFile['path'])
) {

    $previewUrl =
        $scriptDir
        . '/api/get-image.php'
        . '?job_id='
        . rawurlencode(
            (string) $jobId
        )
        . '&role=preview';
}


if (
    $masterFile
    && !empty($masterFile['path'])
) {

    $masterUrl =
        $scriptDir
        . '/api/get-image.php'
        . '?job_id='
        . rawurlencode(
            (string) $jobId
        )
        . '&role=master';
}


/* -----------------------------------------------------------------------
 * Payload
 * --------------------------------------------------------------------- */

$job['preview_url'] =
    $previewUrl;

$job['master_url'] =
    $masterUrl;


/* -----------------------------------------------------------------------
 * Response
 * --------------------------------------------------------------------- */

json_response(
    [
        'success' => true,

        'job' => $job,

        'id' =>
            $job['id'],

        'sku' =>
            $job['sku'],

        'status' =>
            $job['status'],

        'stage' =>
            $job['stage'],

        'progress' =>
            $job['progress'],

        'error_msg' =>
            $job['error_msg'],

        'file_count' =>
            $job['file_count'],

        'count' =>
            $job['count'],

        'preview_url' =>
            $previewUrl,

        'master_url' =>
            $masterUrl,
    ]
);