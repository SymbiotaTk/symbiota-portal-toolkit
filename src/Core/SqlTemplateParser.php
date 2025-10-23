<?php

namespace Symbiota\Helpers\Core;

/**
 * SQL Template Parser
 * 
 * Handles SQL template files with:
 * - Comment removal (-- style comments)
 * - Variable interpolation
 * - Statement splitting
 */
class SqlTemplateParser
{
    private string $templateDir;

    /**
     * Constructor
     *
     * @param string $templateDir Base directory for SQL templates
     */
    public function __construct(string $templateDir = null)
    {
        if ($templateDir === null) {
            // Default to templates/sql directory
            $templateDir = dirname(__DIR__, 2) . '/templates/sql';
        }
        $this->templateDir = rtrim($templateDir, '/');
    }

    /**
     * Parse template file and return as single SQL string
     *
     * @param string $templatePath Relative path to template (e.g., 'images/eav/create_cache_eav.sql')
     * @param array $variables Variables for interpolation
     * @return string SQL string ready for execution
     */
    public function parse(string $templatePath, array $variables = []): string
    {
        $fullPath = $this->templateDir . '/' . $templatePath;
        $statements = self::parseFile($fullPath, $variables);
        return implode(";\n", $statements);
    }

    /**
     * Load and parse SQL template file
     *
     * @param string $templateFile Path to SQL template file
     * @param array $variables Variables for interpolation (optional)
     * @return array Array of SQL statements ready for execution
     */
    public static function parseFile(string $templateFile, array $variables = []): array
    {
        if (!file_exists($templateFile)) {
            throw new \Exception(sprintf('SQL template file not found: %s', $templateFile));
        }

        $sql = file_get_contents($templateFile);
        return self::parseString($sql, $variables);
    }
    
    /**
     * Parse SQL string
     *
     * @param string $sql SQL content
     * @param array $variables Variables for interpolation (optional)
     * @return array Array of SQL statements ready for execution
     */
    public static function parseString(string $sql, array $variables = []): array
    {
        // Step 1: Remove block comments (/* ... */)
        $sql = self::removeBlockComments($sql);

        // Step 2: Remove line comments (-- ...)
        $sql = self::removeLineComments($sql);

        // Step 3: Interpolate variables
        if (!empty($variables)) {
            $sql = self::interpolateVariables($sql, $variables);
        }

        // Step 4: Split into statements
        $statements = self::splitStatements($sql);

        return $statements;
    }
    
    /**
     * Remove SQL block comments (C-style multi-line comments)
     *
     * Handles multi-line block comments like: slash-asterisk ... asterisk-slash
     */
    private static function removeBlockComments(string $sql): string
    {
        // Remove block comments (non-greedy, handles multiple comments)
        // Use DOTALL flag to match across newlines
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        return $sql;
    }

    /**
     * Remove SQL line comments (-- style)
     *
     * Preserves blank lines for readability during debugging
     */
    private static function removeLineComments(string $sql): string
    {
        $lines = explode("\n", $sql);
        $cleanedLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip comment lines
            if (str_starts_with($trimmed, '--')) {
                continue;
            }

            // Handle inline comments (e.g., "SELECT * FROM table -- comment")
            $commentPos = strpos($line, '--');
            if ($commentPos !== false) {
                // Check if -- is inside a string literal
                if (!self::isInsideString($line, $commentPos)) {
                    $line = substr($line, 0, $commentPos);
                }
            }

            $cleanedLines[] = $line;
        }

        return implode("\n", $cleanedLines);
    }
    
    /**
     * Check if position is inside a string literal
     */
    private static function isInsideString(string $line, int $pos): bool
    {
        $inSingleQuote = false;
        $inDoubleQuote = false;
        
        for ($i = 0; $i < $pos; $i++) {
            $char = $line[$i];
            
            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
            } elseif ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
            }
        }
        
        return $inSingleQuote || $inDoubleQuote;
    }
    
    /**
     * Interpolate variables into SQL
     * 
     * Supports {VARIABLE_NAME} syntax
     */
    private static function interpolateVariables(string $sql, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $placeholder = sprintf('{%s}', strtoupper($key));
            $sql = str_replace($placeholder, $value, $sql);
        }
        
        return $sql;
    }
    
    /**
     * Split SQL into individual statements
     * 
     * Splits on semicolons, but respects string literals
     */
    private static function splitStatements(string $sql): array
    {
        $statements = [];
        $currentStatement = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $length = strlen($sql);
        
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            
            // Track quote state
            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
            } elseif ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
            }
            
            // Split on semicolon if not inside quotes
            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote) {
                $trimmed = trim($currentStatement);
                if (!empty($trimmed)) {
                    $statements[] = $trimmed;
                }
                $currentStatement = '';
            } else {
                $currentStatement .= $char;
            }
        }
        
        // Add final statement if exists
        $trimmed = trim($currentStatement);
        if (!empty($trimmed)) {
            $statements[] = $trimmed;
        }
        
        return $statements;
    }
    
    /**
     * Execute SQL statements against PDO connection
     * 
     * @param \PDO $db Database connection
     * @param array $statements Array of SQL statements
     * @param bool $useTransaction Wrap in transaction (default: false)
     */
    public static function executeStatements(\PDO $db, array $statements, bool $useTransaction = false): void
    {
        if ($useTransaction) {
            $db->beginTransaction();
        }
        
        try {
            foreach ($statements as $statement) {
                $db->exec($statement);
            }
            
            if ($useTransaction) {
                $db->commit();
            }
        } catch (\Exception $e) {
            if ($useTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

