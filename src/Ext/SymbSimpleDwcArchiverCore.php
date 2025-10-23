<?php

/**
 * SymbSimpleDwcArchiverCore - Direct Working Example
 *
 * This class extends the Symbiota DwcArchiverCore and simplifies the backup process.
 * It handles the 'backup' parts of DwcArchiverCore without requiring authentication,
 * just needs a collection ID and makes it easy to set a target directory for output.
 */

/**
 * SimpleDwcWrapper - Simplified DwcArchiverCore Extension
 */
class SymbSimpleDwcArchiverCore extends DwcArchiverCore
{
    private $customTargetPath;
    private $collectionId;

    /**
     * Constructor
     *
     * @param int $collectionId Collection ID to backup
     * @param string $outputDir Target directory for backup files
     */
    public function __construct($collectionId, $outputDir)
    {
        parent::__construct();

        $this->collectionId = $collectionId;
        $this->customTargetPath = rtrim($outputDir, '/') . '/';

        // Ensure output directory exists
        if (!is_dir($this->customTargetPath)) {
            if (!mkdir($this->customTargetPath, 0755, true)) {
                throw new Exception("Failed to create output directory: {$this->customTargetPath}");
            }
        }

        // Configure for backup operation
        $this->setCollArr($collectionId);
        $this->setSchemaType('backup');
        $this->setCharSetOut('utf-8');
        $this->setVerboseMode(1);
        $this->setIncludeDets(1);
        $this->setIncludeImgs(1);
        $this->setIncludeAttributes(1);
        $this->setRedactLocalities(0);

        echo "✅ SimpleDwcWrapper initialized for collection $collectionId\n";
        echo "   Output directory: {$this->customTargetPath}\n";
    }

    /**
     * Simplified setTargetPath method - overrides parent
     */
    public function setTargetPath($target = '')
    {
        if ($target) {
            $this->customTargetPath = rtrim($target, '/') . '/';
        }

        // Ensure directory exists
        if (!is_dir($this->customTargetPath)) {
            if (!mkdir($this->customTargetPath, 0755, true)) {
                throw new Exception("Failed to create target directory: {$this->customTargetPath}");
            }
        }

        echo "📁 Setting target path: {$this->customTargetPath}\n";

        // Set the parent's targetPath property via reflection since it's private
        $reflection = new ReflectionClass(get_parent_class($this));
        $targetPathProperty = $reflection->getProperty('targetPath');
        $targetPathProperty->setAccessible(true);
        $targetPathProperty->setValue($this, $this->customTargetPath);

        return true;
    }

    /**
     * Simplified createArchive method - uses parent's createDwcArchive
     */
    public function createArchive()
    {
        try {
            echo "🚀 Creating Darwin Core Archive for collection {$this->collectionId}...\n";

            // Set target path
            $this->setTargetPath();

            // Use the parent's createDwcArchive method which has all the SQL logic
            $archivePath = parent::createDwcArchive();

            if ($archivePath && file_exists($archivePath)) {
                $fileSize = filesize($archivePath);
                echo "✅ SUCCESS! Archive created: " . basename($archivePath) . "\n";
                echo "   Full path: $archivePath\n";
                echo "   Size: " . number_format($fileSize) . " bytes\n";

                return [
                    'success' => true,
                    'archive_path' => $archivePath,
                    'file_name' => basename($archivePath),
                    'file_size' => $fileSize
                ];
            } else {
                throw new Exception("createDwcArchive returned false or file doesn't exist");
            }

        } catch (Exception $e) {
            echo "❌ ERROR: " . $e->getMessage() . "\n";
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
