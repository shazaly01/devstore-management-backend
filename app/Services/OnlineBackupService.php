<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Spatie\DbDumper\Databases\MySql;
use Symfony\Component\Process\Process;
use Exception;

class OnlineBackupService
{
    protected string $backupPath;
    protected string $tempPath;

    public function __construct()
    {
        // تأمين بيئة ويندوز للعمليات الفرعية
        $this->ensureSystemEnvironment();

        // مسارات حفظ النسخ الاحتياطية
        $this->backupPath = storage_path('app' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'online');
        $this->tempPath   = storage_path('app' . DIRECTORY_SEPARATOR . 'backup-temp');
    }

    /**
     * كشف معماري ديناميكي لمسار أدوات MySQL:
     * 1. المتغير الصريح في .env (إن وُجد)
     * 2. السيرفر المحمول للعميل (Portable Bundle)
     * 3. بيئة التطوير المحلية (WampServer)
     * 4. البيئة الافتراضية (Linux / System PATH)
     */
    protected function getBinaryPath(): ?string
    {
        // 1. أولوية التخصيص اليدوي عبر .env
        if ($envPath = env('MYSQL_BINARY_PATH')) {
            if (File::isDirectory($envPath)) {
                return realpath($envPath);
            }
        }

        // 2. فحص بيئة العميل المحمولة (نسبية للمشروع: ../server/mysql/bin)
        $portablePath = dirname(base_path()) . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin';
        if (File::isDirectory($portablePath)) {
            $exe = $portablePath . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'mysqldump.exe' : 'mysqldump');
            if (File::exists($exe)) {
                return realpath($portablePath);
            }
        }

        // 3. فحص بيئة التطوير المحلية WampServer على ويندوز تلقائياً
        if (PHP_OS_FAMILY === 'Windows') {
            $wampMysqlRoot = 'C:\wamp64\bin\mysql';
            if (File::isDirectory($wampMysqlRoot)) {
                $versions = File::directories($wampMysqlRoot);
                if (!empty($versions)) {
                    // اختيار أحدث إصدار MySQL مثبت داخل WAMP
                    rsort($versions);
                    $wampBin = $versions[0] . DIRECTORY_SEPARATOR . 'bin';
                    if (File::exists($wampBin . DIRECTORY_SEPARATOR . 'mysqldump.exe')) {
                        return realpath($wampBin);
                    }
                }
            }
        }

        // 4. خوادم Linux أو الأنظمة التي تملك MySQL في الـ PATH العام
        return null;
    }

    /**
     * تأمين تمرير متغيرات بيئة نظام ويندوز الأساسية (خاصة SystemRoot و windir)
     * لمنع انهيار مآخذ Winsock برمز الخطأ 10106
     */
    protected function ensureSystemEnvironment(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $systemRoot = getenv('SystemRoot') ?: (getenv('windir') ?: 'C:\Windows');
        $windir     = getenv('windir') ?: $systemRoot;
        $path       = getenv('PATH') ?: "{$systemRoot}\\system32;{$systemRoot};{$systemRoot}\\System32\\Wbem";
        $temp       = getenv('TEMP') ?: (getenv('TMP') ?: "{$systemRoot}\\Temp");

        // إذا تم اكتشاف مسار MySQL، ندمجه ديناميكياً في بيئة التنفيذ الحالية
        if ($binPath = $this->getBinaryPath()) {
            $path = "{$binPath};{$path}";
        }

        $envVars = [
            'SystemRoot'  => $systemRoot,
            'SYSTEMROOT'  => $systemRoot,
            'windir'      => $windir,
            'WINDIR'      => $windir,
            'PATH'        => $path,
            'TEMP'        => $temp,
            'TMP'         => $temp,
            'SystemDrive' => getenv('SystemDrive') ?: 'C:',
            'ComSpec'     => getenv('ComSpec') ?: "{$systemRoot}\\system32\\cmd.exe",
        ];

        foreach ($envVars as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }

        return $envVars;
    }

    /**
     * جلب المسار التنفيذي لأداة mysql للاستيراد
     */
    protected function getMysqlExecutable(): string
    {
        $binPath = $this->getBinaryPath();
        if ($binPath) {
            $exe = $binPath . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'mysql.exe' : 'mysql');
            if (File::exists($exe)) {
                return $exe;
            }
        }

        return 'mysql';
    }

    protected function getDatabaseName(): string
    {
        $connection = config('database.default');
        return config("database.connections.{$connection}.database") ?? 'database';
    }

    protected function getBackupTimezone(): string
    {
        return env('BACKUP_TIMEZONE', config('app.timezone', 'UTC'));
    }

    /**
     * إنشاء نسخة احتياطية جديدة
     */
    public function generateBackup(): array
    {
        $this->ensureSystemEnvironment();

        $connection = config('database.default');
        if ($connection !== 'mysql') {
            throw new Exception('هذه الخدمة مخصصة حصرياً لقواعد بيانات MySQL.');
        }

        $dbConfig = config("database.connections.{$connection}");
        $dbName   = $dbConfig['database'];

        if (!File::exists($this->backupPath)) {
            File::makeDirectory($this->backupPath, 0755, true, true);
        }

        if (!File::exists($this->tempPath)) {
            File::makeDirectory($this->tempPath, 0755, true, true);
        }

        $timezone  = $this->getBackupTimezone();
        $timestamp = now($timezone)->format('Y-m-d_H\hi\ms\s');
        $fileName  = "{$dbName}_{$timestamp}.sql.gz";
        $finalPath = $this->backupPath . DIRECTORY_SEPARATOR . $fileName;

        $tempSqlFile = $this->tempPath . DIRECTORY_SEPARATOR . "temp_dump_{$timestamp}.sql";

        try {
            $dumper = MySql::create()
                ->setDbName($dbName)
                ->setUserName($dbConfig['username'])
                ->setPassword($dbConfig['password'] ?? '')
                ->setHost($dbConfig['host'] ?? '127.0.0.1')
                ->setPort((int) ($dbConfig['port'] ?? 3306))
                ->addExtraOption('--single-transaction')
                ->addExtraOption('--skip-lock-tables')
                ->addExtraOption('--quick');

            if ($binPath = $this->getBinaryPath()) {
                $dumper->setDumpBinaryPath($binPath);
            }

            $dumper->dumpToFile($tempSqlFile);

            $this->gzipFile($tempSqlFile, $finalPath);
            File::delete($tempSqlFile);

            $this->rotateBackups();

            return [
                'success'  => true,
                'filename' => $fileName,
                'path'     => $finalPath,
                'size'     => $this->formatSize(File::size($finalPath)),
                'date'     => now($timezone)->format('Y-m-d H:i:s'),
            ];
        } catch (Exception $e) {
            if (File::exists($tempSqlFile)) {
                File::delete($tempSqlFile);
            }
            throw new Exception('فشل إنشاء النسخة الاحتياطية: ' . $e->getMessage());
        }
    }

    /**
     * استعادة قاعدة البيانات
     */
    public function restoreBackup(string $fileName): array
    {
        $this->ensureSystemEnvironment();

        $fileName   = basename($fileName);
        $sourcePath = $this->backupPath . DIRECTORY_SEPARATOR . $fileName;

        if (!File::exists($sourcePath)) {
            throw new Exception('ملف النسخة الاحتياطية المحددة غير موجود على السيرفر.');
        }

        $connection = config('database.default');
        $dbConfig   = config("database.connections.{$connection}");

        $this->generateEmergencyBackup($dbConfig);

        $tempSqlFile = $this->tempPath . DIRECTORY_SEPARATOR . 'temp_restore_extract.sql';

        try {
            if (!File::exists($this->tempPath)) {
                File::makeDirectory($this->tempPath, 0755, true, true);
            }
            $this->gunzipFile($sourcePath, $tempSqlFile);

            $this->dropAllTables($dbConfig['database']);

            try {
                $this->executeMysqlImport($dbConfig, $tempSqlFile);
            } catch (Exception $ex) {
                $this->executePdoImport($tempSqlFile);
            }

            File::delete($tempSqlFile);
            $this->clearEmergencyBackup();

            return [
                'success' => true,
                'message' => 'تمت استعادة قاعدة البيانات بنجاح تام وبشكل متكامل.',
            ];
        } catch (Exception $e) {
            if (File::exists($tempSqlFile)) {
                File::delete($tempSqlFile);
            }

            $this->rollbackEmergency($dbConfig);

            throw new Exception('فشلت عملية الاستعادة لخطأ فني، وتم التراجع تلقائياً وإعادة النظام لحالته السليمة: ' . $e->getMessage());
        }
    }

    public function getBackupsList(): array
    {
        $backups  = [];
        $dbName   = $this->getDatabaseName();
        $prefix   = "{$dbName}_";
        $timezone = $this->getBackupTimezone();

        if (File::exists($this->backupPath)) {
            $files = File::files($this->backupPath);
            foreach ($files as $file) {
                if ($file->getExtension() === 'gz' && str_starts_with($file->getFilename(), $prefix)) {
                    $backups[] = [
                        'name' => $file->getFilename(),
                        'size' => $this->formatSize($file->getSize()),
                        'date' => now($timezone)->setTimestamp($file->getMTime())->format('Y-m-d H:i:s'),
                    ];
                }
            }
        }

        usort($backups, function ($a, $b) {
            return strcmp($b['date'], $a['date']);
        });

        return $backups;
    }

    public function deleteBackup(string $fileName): bool
    {
        $fileName = basename($fileName);
        $filePath = $this->backupPath . DIRECTORY_SEPARATOR . $fileName;

        if (File::exists($filePath)) {
            return File::delete($filePath);
        }

        return false;
    }

    public function generateDownloadUrl(string $fileName): string
    {
        return URL::temporarySignedRoute(
            'backups.download',
            now()->addMinutes(2),
            ['file_name' => $fileName],
            false
        );
    }

    protected function rotateBackups(): void
    {
        if (!File::exists($this->backupPath)) {
            return;
        }

        $files       = File::files($this->backupPath);
        $backupFiles = [];
        $dbName      = $this->getDatabaseName();
        $prefix      = "{$dbName}_";

        foreach ($files as $file) {
            if ($file->getExtension() === 'gz' && str_starts_with($file->getFilename(), $prefix)) {
                $backupFiles[] = [
                    'path'  => $file->getPathname(),
                    'mtime' => $file->getMTime(),
                ];
            }
        }

        usort($backupFiles, function ($a, $b) {
            return $b['mtime'] <=> $a['mtime'];
        });

        if (count($backupFiles) > 50) {
            $filesToDelete = array_slice($backupFiles, 50);
            foreach ($filesToDelete as $file) {
                File::delete($file['path']);
            }
        }
    }

    protected function executeMysqlImport(array $dbConfig, string $filePath): void
    {
        $mysqlBin  = $this->getMysqlExecutable();
        $systemEnv = $this->ensureSystemEnvironment();

        $command = [
            $mysqlBin,
            '-h', $dbConfig['host'] ?? '127.0.0.1',
            '-P', (string) ($dbConfig['port'] ?? 3306),
            '-u', (string) $dbConfig['username'],
            '--execute=source ' . $filePath,
            (string) $dbConfig['database'],
        ];

        $process = new Process($command, null, array_merge($systemEnv, [
            'MYSQL_PWD' => (string) ($dbConfig['password'] ?? ''),
        ]));

        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new Exception($process->getErrorOutput());
        }
    }

    protected function executePdoImport(string $filePath): void
    {
        $file = fopen($filePath, 'r');
        if (!$file) {
            throw new Exception('تعذر فتح ملف الاستيراد المؤقت.');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        $query = '';
        while (($line = fgets($file)) !== false) {
            $trimmedLine = trim($line);

            if (empty($trimmedLine) || str_starts_with($trimmedLine, '--') || str_starts_with($trimmedLine, '/*')) {
                continue;
            }

            $query .= $line;

            if (str_ends_with($trimmedLine, ';')) {
                DB::unprepared($query);
                $query = '';
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        fclose($file);
    }

    protected function dropAllTables(string $dbName): void
    {
        $tables  = DB::select('SHOW FULL TABLES');
        $colName = "Tables_in_{$dbName}";

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $name = $table->$colName;
            $type = $table->Table_type;

            if ($type === 'VIEW') {
                DB::statement("DROP VIEW IF EXISTS `{$name}`");
            } else {
                DB::statement("DROP TABLE IF EXISTS `{$name}`");
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function gzipFile(string $source, string $destination): void
    {
        $fpOut = gzopen($destination, 'wb9');
        $fpIn  = fopen($source, 'rb');

        if ($fpOut === false || $fpIn === false) {
            throw new Exception('فشل في فتح قنوات البيانات لضغط الملف.');
        }

        while (!feof($fpIn)) {
            gzwrite($fpOut, fread($fpIn, 1024 * 512));
        }

        fclose($fpIn);
        gzclose($fpOut);
    }

    protected function gunzipFile(string $source, string $destination): void
    {
        $fpOut = fopen($destination, 'wb');
        $fpIn  = gzopen($source, 'rb');

        if ($fpOut === false || $fpIn === false) {
            throw new Exception('فشل في فتح قنوات البيانات لفك ضغط الملف.');
        }

        while (!gzeof($fpIn)) {
            fwrite($fpOut, gzread($fpIn, 1024 * 512));
        }

        fclose($fpIn);
        gzclose($fpOut);
    }

    protected function generateEmergencyBackup(array $dbConfig): void
    {
        $this->ensureSystemEnvironment();

        $dbName        = $dbConfig['database'];
        $emergencyFile = $this->backupPath . DIRECTORY_SEPARATOR . "EMERGENCY_ROLLBACK_{$dbName}.sql.gz";
        $tempSql       = $this->tempPath . DIRECTORY_SEPARATOR . "temp_emergency_{$dbName}.sql";

        if (!File::exists($this->tempPath)) {
            File::makeDirectory($this->tempPath, 0755, true, true);
        }

        $dumper = MySql::create()
            ->setDbName($dbName)
            ->setUserName($dbConfig['username'])
            ->setPassword($dbConfig['password'] ?? '')
            ->setHost($dbConfig['host'] ?? '127.0.0.1')
            ->setPort((int) ($dbConfig['port'] ?? 3306))
            ->addExtraOption('--single-transaction')
            ->addExtraOption('--skip-lock-tables')
            ->addExtraOption('--quick');

        if ($binPath = $this->getBinaryPath()) {
            $dumper->setDumpBinaryPath($binPath);
        }

        $dumper->dumpToFile($tempSql);

        $this->gzipFile($tempSql, $emergencyFile);
        File::delete($tempSql);
    }

    protected function rollbackEmergency(array $dbConfig): void
    {
        $this->ensureSystemEnvironment();

        $dbName        = $dbConfig['database'];
        $emergencyFile = $this->backupPath . DIRECTORY_SEPARATOR . "EMERGENCY_ROLLBACK_{$dbName}.sql.gz";
        if (!File::exists($emergencyFile)) {
            return;
        }

        try {
            $tempEmergencySql = $this->tempPath . DIRECTORY_SEPARATOR . "temp_emergency_rollback_{$dbName}.sql";
            $this->gunzipFile($emergencyFile, $tempEmergencySql);

            $this->dropAllTables($dbName);

            try {
                $this->executeMysqlImport($dbConfig, $tempEmergencySql);
            } catch (Exception $ex) {
                $this->executePdoImport($tempEmergencySql);
            }

            File::delete($tempEmergencySql);
            File::delete($emergencyFile);
        } catch (Exception $criticalError) {
            logger()->critical("فشل حرج: تعذر استعادة نسخة الطوارئ التلقائية لقاعدة البيانات {$dbName}! التفاصيل: " . $criticalError->getMessage());
        }
    }

    protected function clearEmergencyBackup(): void
    {
        $dbName        = $this->getDatabaseName();
        $emergencyFile = $this->backupPath . DIRECTORY_SEPARATOR . "EMERGENCY_ROLLBACK_{$dbName}.sql.gz";
        if (File::exists($emergencyFile)) {
            File::delete($emergencyFile);
        }
    }

    protected function formatSize($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}