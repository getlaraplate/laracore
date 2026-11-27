<?php

declare(strict_types=1);

/*
 * PUB400 spike (Task 1 of the Db2 plan). Never committed.
 *
 * Reads the credentials from the environment only:
 *   DB2_SPIKE_USER, DB2_SPIKE_PASSWORD, DB2_SPIKE_LIBRARY (one of your PUB400 libraries)
 *   DB2_SPIKE_DRIVER (optional, default "IBM i Access ODBC Driver", as `odbcinst -q -d` lists it)
 *   DB2_SPIKE_CREATE_SCHEMA=1 (optional): create DB2_SPIKE_LIBRARY with CREATE SCHEMA when missing,
 *   so Db2 gives it the QSQJRN journal and journals every table created in it.
 *   DB2_SPIKE_JOURNAL=1 (optional): give an existing DB2_SPIKE_LIBRARY a QSQJRN journal when it has none
 *   (CRTJRNRCV, CRTJRN with system-managed receivers, STRJRNLIB), for accounts that cannot CREATE SCHEMA.
 *   DB2_SPIKE_TRIGGER=1 (optional): report the authorities on QSYS/ADDPFTRG and try a trivial SQL trigger.
 *
 * Proves: ODBC login, IBM i release, create table, commit, rollback held, drop.
 */

$user = getenv('DB2_SPIKE_USER') ?: exit("DB2_SPIKE_USER is not set\n");
$password = getenv('DB2_SPIKE_PASSWORD') ?: exit("DB2_SPIKE_PASSWORD is not set\n");
$library = strtoupper(getenv('DB2_SPIKE_LIBRARY') ?: exit("DB2_SPIKE_LIBRARY is not set\n"));
$driver = getenv('DB2_SPIKE_DRIVER') ?: 'IBM i Access ODBC Driver';
$createSchema = getenv('DB2_SPIKE_CREATE_SCHEMA') === '1';
$addJournal = getenv('DB2_SPIKE_JOURNAL') === '1';
$probeTrigger = getenv('DB2_SPIKE_TRIGGER') === '1';

if (! extension_loaded('pdo_odbc')) {
    exit("pdo_odbc is not loaded\n");
}

$dsn = $createSchema
    ? sprintf('odbc:DRIVER={%s};SYSTEM=pub400.com;NAMING=0;COMMITMODE=2;CCSID=1208', $driver)
    : sprintf('odbc:DRIVER={%s};SYSTEM=pub400.com;NAMING=0;COMMITMODE=2;DBQ=%s;CCSID=1208', $driver, $library);

$step = static function (string $label): void {
    echo str_pad($label, 40, '.') . ' ';
};

try {
    $step('connect');
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "ok\n";

    $step('release');
    $row = $pdo->query('SELECT OS_VERSION, OS_RELEASE FROM SYSIBMADM.ENV_SYS_INFO')->fetch(PDO::FETCH_NUM);
    echo 'IBM i ' . trim((string) $row[0]) . '.' . trim((string) $row[1]) . "\n";

    if ($createSchema) {
        $step('schema');
        $exists = (int) $pdo->query("SELECT COUNT(*) FROM QSYS2.SYSSCHEMAS WHERE SCHEMA_NAME = '{$library}'")->fetchColumn();
        if ($exists === 1) {
            echo "exists\n";
        } else {
            $pdo->exec("CREATE SCHEMA {$library}");
            echo "created\n";
        }
    }

    if ($addJournal) {
        $step('journal QSQJRN');
        $hasJournal = (int) $pdo->query("SELECT COUNT(*) FROM TABLE(QSYS2.OBJECT_STATISTICS('{$library}', '*JRN', 'QSQJRN')) X")->fetchColumn();
        if ($hasJournal === 1) {
            echo "exists\n";
        } else {
            $run = static fn (string $command): int|false => $pdo->exec("CALL QSYS2.QCMDEXC('" . str_replace("'", "''", $command) . "')");
            $run("CRTJRNRCV JRNRCV({$library}/QSQJRN0001)");
            $run("CRTJRN JRN({$library}/QSQJRN) JRNRCV({$library}/QSQJRN0001) MNGRCV(*SYSTEM) DLTRCV(*YES)");
            echo "created\n";
        }

        $step('start library journaling');
        try {
            $pdo->exec("CALL QSYS2.QCMDEXC('STRJRNLIB LIB({$library}) JRN({$library}/QSQJRN)')");
            echo "ok\n";
        } catch (PDOException $exception) {
            echo 'skipped (QSQJRN alone journals new SQL tables): ' . $exception->getMessage() . "\n";
        }
    }

    $table = $library . '.SPIKE_T';

    $step('drop leftover table');
    try {
        $pdo->exec("DROP TABLE {$table}");
        echo "dropped\n";
    } catch (PDOException) {
        echo "none\n";
    }

    $step('create table');
    $pdo->exec("CREATE TABLE {$table} (ID INTEGER NOT NULL)");
    echo "ok\n";

    $step('journaled');
    $journal = $pdo->query("SELECT JOURNALED, JOURNAL_LIBRARY, JOURNAL_NAME FROM TABLE(QSYS2.OBJECT_STATISTICS('{$library}', '*FILE', 'SPIKE_T')) X")->fetch(PDO::FETCH_NUM);
    echo var_export($journal, true) . "\n";

    $step('insert 1 and commit');
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO {$table} (ID) VALUES (1)");
    $pdo->commit();
    echo "ok\n";

    $step('insert 2 and roll back');
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO {$table} (ID) VALUES (2)");
    $pdo->rollBack();
    echo "ok\n";

    $step('count after rollback');
    $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    echo $count . ($count === 1 ? " (rollback held)\n" : " (ROLLBACK DID NOT HOLD: library not journaled)\n");

    if ($probeTrigger) {
        $step('ADDPFTRG authorities');
        try {
            $rows = $pdo->query("SELECT AUTHORIZATION_NAME, OBJECT_AUTHORITY FROM QSYS2.OBJECT_PRIVILEGES WHERE SYSTEM_OBJECT_SCHEMA = 'QSYS' AND SYSTEM_OBJECT_NAME = 'ADDPFTRG' AND OBJECT_TYPE = '*CMD'")->fetchAll(PDO::FETCH_NUM);
            echo json_encode($rows) . "\n";
        } catch (PDOException $exception) {
            echo 'FAILED: ' . $exception->getMessage() . "\n";
        }

        $step('user special authorities');
        try {
            $profile = $pdo->query("SELECT SPECIAL_AUTHORITIES, GROUP_PROFILE_NAME FROM QSYS2.USER_INFO WHERE AUTHORIZATION_NAME = CURRENT USER")->fetch(PDO::FETCH_NUM);
            echo json_encode($profile) . "\n";
        } catch (PDOException $exception) {
            echo 'FAILED: ' . $exception->getMessage() . "\n";
        }

        $step('create trigger');
        try {
            $pdo->exec("CREATE OR REPLACE TRIGGER {$library}.SPIKE_TRG NO CASCADE BEFORE INSERT ON {$table} REFERENCING NEW AS N FOR EACH ROW BEGIN ATOMIC SET N.ID = N.ID + 100; END");
            echo "ok\n";
            $pdo->exec("DROP TRIGGER {$library}.SPIKE_TRG");
        } catch (PDOException $exception) {
            echo 'FAILED: ' . $exception->getMessage() . "\n";
        }
    }

    $step('drop table');
    $pdo->exec("DROP TABLE {$table}");
    echo "ok\n";
} catch (Throwable $exception) {
    echo "FAILED\n" . get_class($exception) . ': ' . $exception->getMessage() . "\n";
    exit(1);
}
