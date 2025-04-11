<?php

namespace Mfonte\Arkhive\Services;

use Symfony\Component\Console\Style\OutputStyle;

/**
 * FileEnumeratorService
 *
 * Scans a directory and produces a temporary file that lists all files
 * (one per line) that are NOT excluded via the simplified EXCLUSION_PATTERNS.
 *
 * The exclusion patterns support only the asterisk wildcard, and work as follows:
 *   - "/example/"  -> matches exactly "/example/"
 *   - "* /example"  -> matches anything that ends with "/example"
 *   - "/example/*" -> matches anything that starts with "/example/"
 *   - "*.png"     -> matches anything that ends with ".png"
 *
 * All file paths are normalized to use a leading slash (e.g. "/subdir/file.txt")
 * for consistent matching.
 */
class FileEnumeratorService
{
    /**
     * @var OutputStyle|null
     */
    protected $output;

    /**
     * Cache mapping input directory => temporary file path.
     *
     * @var array<string, string>
     */
    protected $tempFiles = [];

    /**
     * @param string             $directory The directory to scan.
     * @param array              $exclusionPatterns An array of exclusion patterns.
     * @param OutputStyle|null   $output An optional Console output for logging messages.
     */
    public function __construct(?OutputStyle $output = null)
    {
        $this->output = $output;
    }

    /**
     * Enumerates the input directory and writes a list of found files (one per line)
     * to a temporary file. Returns a tuple containing the path to the
     * temporary file and the total size of files excluded in bytes.
     *
     * @return array [string, int]
     * @throws \RuntimeException
     */
    public function enumerateDirectory(string $directory, array $exclusionPatterns = []): array
    {
        if (!is_dir($directory) || !is_readable($directory)) {
            throw new \RuntimeException("Invalid directory provided: {$directory}. Check that it exists and is readable.");
        }

        // compile the exclusion patterns into regexes
        $compiledExclusions = $this->compileExclusionPatterns($exclusionPatterns);

        // Create a temporary file and open it for writing.
        $tempFile = tempnam(sys_get_temp_dir(), 'file_discovery_');
        if ($tempFile === false) {
            throw new \RuntimeException("Failed to create a temporary file.");
        }
        $fp = fopen($tempFile, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open temporary file for writing: {$tempFile}");
        }

        // enumerate
        $this->writeln(" 💻 Enumerating directory {$directory} ...");
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
        } catch (\UnexpectedValueException $e) {
            fclose($fp);
            throw new \RuntimeException("Failed to open directory: {$directory}", 0, $e);
        }

        // filter out excluded files
        $this->writeln(" 🔍 Filtering files as per exclusions patterns...");
        $written = 0;
        $excluded = 0;
        $excludedBytes = 0;
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $absolutePath = $fileInfo->getPathname();
            $relativePath = substr($absolutePath, strlen(rtrim($directory, DIRECTORY_SEPARATOR)));
            $relativePath = '/' . ltrim($relativePath, DIRECTORY_SEPARATOR);

            $exclude = false;
            foreach ($compiledExclusions as $regex) {
                if (preg_match($regex, $relativePath)) {
                    $excluded++;
                    $excludedBytes += $fileInfo->getSize();
                    $exclude = true;
                    break;
                }
            }
            if ($exclude) {
                continue;
            }
            if (fwrite($fp, $absolutePath . PHP_EOL) === false) {
                fclose($fp);
                throw new \RuntimeException("Failed to write to temporary file: {$tempFile}");
            }
            $written++;
        }
        fclose($fp);

        $this->writeln(" ✅ Found {$written} files, with {$excluded} exclusions.");
        $this->tempFiles[$directory] = $tempFile;

        return [$tempFile, $excludedBytes];
    }

    /**
     * Removes any temporary file created by scanDir().
     */
    public function clean(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
                $this->writeln(" 🗑 Removed temporary FileEnumeratorService cache file \"{$tempFile}\"");
            }
        }
        $this->tempFiles = [];
    }

    public function __clone()
    {
        $this->clean();
    }

    public function __destruct()
    {
        $this->clean();
    }

    /**
     * Writes a message to the output (if available).
     *
     * @param string $message
     * @return void
     */
    protected function writeln(string $message): void
    {
        if ($this->output) {
            $this->output->writeln($message);
        }
    }

    /**
     * Converts an array of exclusion patterns into an array of regex patterns.
     *
     * @param array $patterns
     * @return array
     */
    protected function compileExclusionPatterns(array $patterns): array
    {
        return collect($patterns)->map(function ($pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                return null;
            }

            return $this->convertPatternToRegex($pattern);
        })->filter()->values()->toArray();
    }

    /**
     * Converts a single exclusion pattern to a regex.
     *
     * The rules are:
     *   - If the pattern starts with '/', it is anchored to the beginning.
     *     - e.g. "/example/*" becomes '^/example/.*' (matches files under /example/)
     *     - e.g. "/example/" becomes '^/example/$' (matches exactly /example/)
     *   - Otherwise, the pattern is matched against the end of the path.
     *     - e.g. "* /example" becomes '.*\/example$'
     *     - e.g. "*.png" becomes '.*\.png$'
     *
     * @param string $pattern
     * @return string The regex pattern delimiter-wrapped.
     */
    protected function convertPatternToRegex(string $pattern): string
    {
        $isAnchored = false;
        $hasTrailingWildcard = false;

        // If the pattern starts with '/', it's anchored.
        if (strpos($pattern, '/') === 0) {
            $isAnchored = true;
            // Remove the leading slash.
            $pattern = substr($pattern, 1);
            // If the pattern ends with "/*", mark it and remove that part.
            if (substr($pattern, -2) === '/*') {
                $hasTrailingWildcard = true;
                $pattern = substr($pattern, 0, -2);
            }
        }

        // Replace all asterisks with a placeholder.
        $placeholder = '___WILDCARD___';
        $pattern = str_replace('*', $placeholder, $pattern);
        // Escape the pattern.
        $pattern = preg_quote($pattern, '#');
        // Replace placeholder with regex wildcard.
        $converted = str_replace($placeholder, '.*', $pattern);

        if ($isAnchored) {
            // Anchored pattern must match from the beginning.
            if ($hasTrailingWildcard) {
                $regex = '^/' . $converted . '/.*';
            } else {
                $regex = '^/' . $converted . '$';
            }
        } else {
            // For unanchored patterns, match any prefix.
            if (strpos($converted, '.*') !== 0) {
                $converted = '.*' . $converted;
            }
            $regex = $converted . '$';
        }

        return '#' . $regex . '#';
    }
}
