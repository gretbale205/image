<?php

header('Content-Type: text/plain; charset=utf-8');

set_time_limit(1800);

/* -----------------------------------------------------------------------
 * Güvenlik
 * --------------------------------------------------------------------- */

$SECRET = 'gizli-anahtar-12345';

if (
    !hash_equals(
        $SECRET,
        (string)($_GET['key'] ?? '')
    )
) {
    http_response_code(403);
    exit('Yetkisiz');
}


/* -----------------------------------------------------------------------
 * Paths
 * --------------------------------------------------------------------- */

$PROJECT =
    '/home/sularkuyumculuk/htdocs/www.sularkuyumculuk.com/pro/image';

$PYTHON =
    $PROJECT . '/worker/venv/bin/python';

$HOME =
    '/home/sularkuyumculuk';

$LOG_DIR =
    $PROJECT . '/storage/logs';

if (!is_dir($LOG_DIR)) {
    @mkdir(
        $LOG_DIR,
        0775,
        true
    );
}


/* -----------------------------------------------------------------------
 * Log
 * --------------------------------------------------------------------- */

$logFile =
    $LOG_DIR .
    '/worker-run-' .
    date('Ymd_His') .
    '.log';


function write_log(
    string $message
): void {

    global $logFile;

    $line =
        '[' .
        date('Y-m-d H:i:s') .
        '] ' .
        $message .
        PHP_EOL;

    file_put_contents(
        $logFile,
        $line,
        FILE_APPEND
    );
}


/* -----------------------------------------------------------------------
 * Basic checks
 * --------------------------------------------------------------------- */

write_log('=== worker-run.php başladı ===');

if (!is_dir($PROJECT)) {

    write_log(
        'HATA: PROJECT bulunamadı: ' .
        $PROJECT
    );

    http_response_code(500);

    exit(
        "PROJECT bulunamadı\n"
    );
}


if (!is_file($PYTHON)) {

    write_log(
        'HATA: Python bulunamadı: ' .
        $PYTHON
    );

    http_response_code(500);

    exit(
        "Python bulunamadı\n"
    );
}


write_log(
    'PROJECT=' .
    $PROJECT
);

write_log(
    'PYTHON=' .
    $PYTHON
);


/* -----------------------------------------------------------------------
 * Command
 * --------------------------------------------------------------------- */

$cmd = sprintf(
    'cd %s && HOME=%s PATH=/usr/local/bin:/usr/bin:/bin %s -m worker.worker --once 2>&1',
    escapeshellarg($PROJECT),
    escapeshellarg($HOME),
    escapeshellarg($PYTHON)
);

write_log(
    'CMD=' .
    $cmd
);


/* -----------------------------------------------------------------------
 * Execute + EXIT CODE
 *
 * shell_exec() yerine exec() kullanıyoruz.
 *
 * Böylece:
 *
 * 0   = başarılı
 * 1+  = Python/uygulama hatası
 * 137 = SIGKILL / çoğunlukla OOM
 * --------------------------------------------------------------------- */

$output = [];

$returnCode = 0;

exec(
    $cmd,
    $output,
    $returnCode
);

$outputText =
    implode(
        PHP_EOL,
        $output
    );


/* -----------------------------------------------------------------------
 * Output log
 * --------------------------------------------------------------------- */

if ($outputText !== '') {

    write_log(
        "PYTHON OUTPUT:\n" .
        $outputText
    );
} else {

    write_log(
        'Python stdout/stderr boş.'
    );
}


/* -----------------------------------------------------------------------
 * EXIT CODE
 * --------------------------------------------------------------------- */

write_log(
    'Python exit code=' .
    $returnCode
);


/* -----------------------------------------------------------------------
 * OOM detection
 * --------------------------------------------------------------------- */

if ($returnCode === 137) {

    write_log(
        '!!! OOM TESPİT EDİLDİ !!!'
    );

    write_log(
        'Python process SIGKILL ile sonlandırılmış.'
    );

    echo
        "WORKER OOM / SIGKILL\n" .
        "Exit code: 137\n\n";

    echo $outputText;

    exit;
}


/* -----------------------------------------------------------------------
 * Other failure
 * --------------------------------------------------------------------- */

if ($returnCode !== 0) {

    write_log(
        '!!! WORKER HATA İLE SONLANDI !!!'
    );

    http_response_code(500);

    echo
        "Worker hata ile sonlandı.\n" .
        "Exit code: " .
        $returnCode .
        "\n\n";

    echo $outputText;

    exit;
}


/* -----------------------------------------------------------------------
 * SUCCESS
 * --------------------------------------------------------------------- */

write_log(
    'Worker başarıyla tamamlandı.'
);

write_log(
    '=== worker-run.php bitti ==='
);

echo
    "Worker başarıyla çalıştı.\n" .
    "Exit code: 0\n\n";

echo $outputText;