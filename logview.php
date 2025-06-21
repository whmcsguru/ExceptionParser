<?php
/**
 * Enhanced log viewer and translator for WHMCS/PHP errors (CLI version)
 * Usage: LogViewer::viewAndTranslate('/path/to/your/logfile.log');
 */
class LogViewer {
    // Helper to get last directory and filename only
    private static function safePath($path) {
        $parts = explode(DIRECTORY_SEPARATOR, str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
        $count = count($parts);
        if ($count >= 2) {
            return $parts[$count-2] . DIRECTORY_SEPARATOR . $parts[$count-1];
        } elseif ($count === 1) {
            return $parts[0];
        }
        return '';
    }
    public static function viewAndTranslate($logFile) {
        if (!file_exists($logFile)) { 
            echo "Log file not found.\n";
            return;
        }
        $handle = fopen($logFile, 'r');
        if (!$handle) {
            echo "Unable to open log file.\n";
            return;
        }
        while (($line = fgets($handle)) !== false) {
            // WHMCS error log line
            if (preg_match('/^\[(.*?)\] \[([^\]]+)\] (ERROR|WARNING|NOTICE): ([^:]+): (.+?) in (.+?):(\d+)/', $line, $matches)) {
                $timestamp = $matches[1];
                $context = $matches[2];
                $errType = $matches[3];
                $exceptionType = $matches[4];
                $errMsg = $matches[5];
                $file = $matches[6];
                $lineNum = $matches[7];
                $safeFile = self::safePath($file);
                echo "\n=== ERROR DETECTED ===\n";
                echo "Time: $timestamp\n";
                echo "Context: $context\n";
                echo "Error Type: $errType\n";
                echo "Exception: $exceptionType\n";
                echo "File: $safeFile\n";
                echo "Line: $lineNum\n";
                echo "Message: $errMsg\n";
                // SQL/code (from error message)
                if (preg_match("/\\(SQL: (.+?)\\)/", $errMsg, $sqlMatch)) {
                    $sql = $sqlMatch[1];
                    echo "SQL/Code: $sql\n";
                    echo "Query: $sql\n";
                }
                // SQL/code (from full log line, if not already shown)
                elseif (preg_match("/\\(SQL: (.+?)\\)/", $line, $sqlMatchLine)) {
                    $sql = $sqlMatchLine[1];
                    echo "SQL/Code: $sql\n";
                    echo "Query: $sql\n";
                }
                // Class not found
                if (preg_match('/Class \\\"?([\\\\\w]+)\\\"? not found/', $errMsg, $classMatch)) {
                    $class = $classMatch[1];
                    echo "\n*** TRANSLATION ***\n";
                    echo "The PHP class '$class' could not be found. Check your autoloaders, file includes, or spelling.\n";
                }
                // Parse error
                if (preg_match('/syntax error, (.+?) in (.+?):(\d+)/', $errMsg, $parseMatch)) {
                    echo "\n*** TRANSLATION ***\n";
                    echo "There is a PHP syntax error: {$parseMatch[1]} in file {$parseMatch[2]} on line {$parseMatch[3]}.\n";
                }
                // General Exception
                if (preg_match('/Required WHMCS classes not found/', $errMsg)) {
                    echo "\n*** TRANSLATION ***\n";
                    echo "A required WHMCS class is missing. Make sure WHMCS is loaded and all files are present.\n";
                }
                // SQL/DB column not found
                if (strpos($errMsg, "Unknown column") !== false) {
                    if (preg_match("/Unknown column '([^']+)' in '([^']+)'/", $errMsg, $colMatch)) {
                        $column = $colMatch[1];
                        $context2 = $colMatch[2];
                        echo "\n*** TRANSLATION ***\n";
                        echo "The database is missing the column '$column' in context '$context2'.\n";
                        echo "You need to add this column to your database for the feature to work.\n";
                    }
                }
                // Try to find the last file/line in the stack trace for the true origin
                $originFile = $file;
                $originLine = $lineNum;
                if (preg_match_all('/#\\d+ (?:.+?)\\((\\d+)\\): (?:.+?) in (.+?):(\\d+)/', $line, $traceMatches, PREG_SET_ORDER)) {
                    $last = end($traceMatches);
                    if ($last && isset($last[2], $last[3])) {
                        $originFile = $last[2];
                        $originLine = $last[3];
                    }
                } elseif (preg_match_all('/in ([^\\s:]+):(\\d+)/', $line, $traceMatches2, PREG_SET_ORDER)) {
                    $last = end($traceMatches2);
                    if ($last && isset($last[1], $last[2])) {
                        $originFile = $last[1];
                        $originLine = $last[2];
                    }
                }
                $safeOriginFile = self::safePath($originFile);
                echo "Origin: $safeOriginFile:$originLine\n";
                // Stack trace: print all files and lines
                echo "Stack Trace:\n";
                $tracePrinted = false;
                // Try to match all file:line pairs in the log line (including stack trace)
                if (preg_match_all('/in ([^\\s:]+):(\\d+)/', $line, $traceMatches2, PREG_SET_ORDER)) {
                    foreach ($traceMatches2 as $trace) {
                        if (isset($trace[1], $trace[2])) {
                            $safeTraceFile = self::safePath($trace[1]);
                            echo "  at {$safeTraceFile}:{$trace[2]}\n";
                            $tracePrinted = true;
                        }
                    }
                }
                if (!$tracePrinted) {
                    // Fallback: just show the main file/line
                    $safeFile = self::safePath($file);
                    echo "  at $safeFile:$lineNum\n";
                }
                echo "=====================\n";
            } else {
                // Print non-error lines as-is
                echo $line;
            }
        }
        fclose($handle);
    }
}

// CLI entry point
if (php_sapi_name() === 'cli') {
    if ($argc < 2) {
        echo "Usage: php logview.php /path/to/logfile.log\n";
        exit(1);
    }
    $logFile = $argv[1];
    LogViewer::viewAndTranslate($logFile);
}