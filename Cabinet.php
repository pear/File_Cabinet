<?php
/**
 * Microsoft Cabinet file extraction wrapper.  Uses either cabextract or expand.
 * 
 * PHP version 5
 * 
 * Notes:
 *  - If running on windows, requires php 5.2.1 or greater
 *  - Currently only reads cabinet files.
 *  - This package does not assume the filename will have a cab extension
 *  - CAB files may be generated under Windows XP by running "iexpress"
 *  - Microsoft Infopath uses the CAB format to store it's xml files
 * 
 * Todo:
 *  - Add ability to create cabinets.
 *  - Expand multi-file cabinents.
 *
 * Resources:
 *  - Microsoft Cabinet SDK. Contains tools and documentation:
 *    http://support.microsoft.com/kb/310618 
 *
 * 
 * A note about writing this package in pure PHP: Although the cab file format documentation
 * is readily available with the CAB SDK and the format itself is quite straight forward,
 * there is a complication with decompressing the data blocks when compressed with
 * the Deflate algorithm.  Data compressed with Deflate may be easily inflated using
 * gzinflate() from PHP's zlib extension, however when there is more than one cabinet
 * data block for the same folder, successive data blocks may contain back references to
 * previous data blocks, which is beyond gzinflate()'s capabilities.  Zlib itself
 * can handle this, but the zlib extension for PHP cannot.  Therefore to put together
 * a package entirely in PHP would mean a very own implementation of inflate.
 * 
 * 
 * @category File Formats
 * @package File_Cabinet
 * @author David Sanders <shangxiao@php.net>
 * @license http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @link http://pear.php.net/package/File_Cabinet
 * @version @package_release@
 */


require_once 'PEAR/Exception.php';
class File_Cabinet_Exception extends PEAR_Exception {}



/**
 * Microsoft Cabinet file extraction wrapper.
 * 
 * @category File Formats
 * @package File_Cabinet_Exception
 * @author David Sanders <shangxiao@php.net>
 * @license http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @link http://pear.php.net/package/File_Cabinet
 * @version @package_release@
 */

class File_Cabinet
{
    /**
     * MIME Type for cabinet files
     */
    const MIME_TYPE = 'application/vnd.ms-cab-compressed';

    /**
     * Path to cabextract - Used on Unix
     * 
     * @var string
     * @see http://www.cabextract.org.uk/
     * @access public
     */
    const UNIX_COMMAND = '/usr/bin/cabextract';

    /**
     * Path to Windows utility for expanding files including cabinets
     * 
     * @var string
     * @access public
     */
    const WIN_COMMAND = '%SYSTEMROOT%\System32\expand.exe';

    /**
     * Command to use
     *
     * @var string
     * @access public
     */
    static public $command;

    /**
     * Filename of cabinet file
     * 
     * @var string
     * @access private
     */
    private $_filename;

    /**
     * Constructor
     * 
     * @param string $filename Path to cabinet file to extract
     */
    public function __construct($filename)
    {
        $this->_filename = $filename;

        // If PEAR.php not included, define OS_WINDOWS
        if (!defined('OS_WINDOWS')) {
            define('OS_WINDOWS', (substr(PHP_OS, 0, 3) == 'WIN'));
        }

        if (is_null(self::$command)) {
            self::$command = OS_WINDOWS ? self::WIN_COMMAND : self::UNIX_COMMAND;
        }

        if (!OS_WINDOWS && !is_executable(self::$command)) {
            throw new File_Cabinet_Exception('Cabinet extraction command not available');
        }
    }

    /**
     * List files inside cabinet.
     *
     * Cabextract will return the filename, size and last modification time whereas
     * expand will only return the filename.
     * 
     * @return array An array of associative arrays containing filenames along with attributes if using cabextract.
     * @access public
     */
    public function listFiles()
    {
        $output = '';
        $rv = 0;
        $files = array();
        if (OS_WINDOWS) {
            // escapeshellcmd escapes %
            $command = self::$command . ' -D ' . escapeshellarg($this->_filename);
            exec($command, $output, $rv);
            if ($rv == 1) {
                throw new File_Cabinet_Exception('Error listing contents of cabinet');
            }
            foreach ($output as $key => $val) {
                if (strpos($val, strtolower($this->_filename . ':')) !== false) {
                    $files[] = array(
                        'filename' => substr($val, strpos($val, ':') + 2),
                    );
                }
            }
        } else {
            $command = escapeshellcmd(self::$command) . ' -q --list ' . escapeshellarg($this->_filename);
            exec($command, $output, $rv);
            if ($rv == 1) {
                throw new File_Cabinet_Exception('Error listing contents of cabinet');
            }
            foreach ($output as $key => $val) {
                if ($key === 0 || $key === 1) {
                    continue;
                }
                list($size, $datetime, $name) = explode('|', $val);
                $files[] = array(
                    'filename'      => trim($name),
                    'size'          => (int) trim($size),
                    'last_modified' => new DateTime(trim($datetime)),
                    );
            }
        }

        return $files;
    }

    /**
     * Extract files inside cabinet
     * 
     * @param array/string $files Either one filename or array of filenames.  The filename
     *                              may contain wildcards.
     * @param string $to_directory Directory to expand to. Leave null to return 
     *                            file contents.
     * @return string File contents if to_directory is null, null otherwise.
     * @access public
     */
    public function extract($files = null, $to_directory = null)
    {
        return OS_WINDOWS ? $this->extractWithExpand($files, $to_directory)
                          : $this->extractWithCabextract($files, $to_directory);
    }

    /**
     * Extract a file using Cabextract
     *
     * @param array/string $files Either one filename or array of filenames.  The filename
     *                              may contain wildcards.
     * @param string $to_directory Directory to expand to. Leave null to return 
     *                            file contents.
     * @return string File contents if to_directory is null, null otherwise.
     * @access private
     */
    private function extractWithCabextract($files = null, $to_directory = null)
    {
        $arguments = '';

        if (is_array($files)) {
            // one by one
            $output = '';
            foreach ($files as $file) {
                $output .= $this->extractWithCabextract($file, $to_directory);
            }
            if (is_null($to_directory)) {
                return $output;
            } else {
                return;
            }
        } else if (is_string($files)) {
            // filter
            $arguments .= ' --filter ' . escapeshellarg($files);
        }

        if (is_null($to_directory)) {
            $arguments .= ' --pipe';
        } else {
            if (!is_dir($to_directory)) {
                throw new File_Cabinet_Exception('Directory doesn\'t exist');
            }
            $arguments .= ' --directory ' . escapeshellarg($to_directory);
        }

        $rv = 0;
        $output = '';
        $command = escapeshellcmd(self::$command) . ' ' . $arguments . ' ' . escapeshellarg($this->_filename);
        exec($command, $output, $rv);
        if ($rv == 1) {
            throw new File_Cabinet_Exception('Error extracting contents of cabinet');
        }
        $output = implode("\n", $output);

        if (is_null($to_directory)) {
            return $output;
        }
    }

    /**
     * Extract file using the Windows' tool expand.exe
     *
     * @param array/string $files Either one filename or array of filenames.  The filename
     *                              may contain wildcards.
     * @param string $to_directory Directory to expand to. Leave null to return 
     *                            file contents.
     * @return string File contents if to_directory is null, null otherwise.
     * @access private
     */
    private function extractWithExpand($files = null, $to_directory = null)
    {
        $arguments = '';

        if (is_array($files)) {
            // one by one
            $output = '';
            foreach ($files as $file) {
                $output .= $this->extractWithExpand($file, $to_directory);
            }
            if (is_null($to_directory)) {
                return $output;
            } else {
                return;
            }
        } else if (is_string($files)) {
            $arguments .= '-R -F:' . escapeshellarg($files);
        } else {
            $arguments .= '-R -F:*';
        }

        if (is_null($to_directory)) {
            // requires >= 5.2.1
            $temp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . time();
            mkdir($temp_dir);
        } else {
            $temp_dir = $to_directory;
        }

        $rv = 0;
        $output = '';
        // escapeshellcmd escapes %
        $command = self::$command . ' ' . escapeshellarg($this->_filename) . ' ' . $arguments . ' ' . escapeshellarg($temp_dir);
        exec($command, $output, $rv);
        if ($rv == 1) {
            throw new File_Cabinet_Exception('Error extracting contents of cabinet');
        }

        if (is_null($to_directory)) {
            $output = '';

            $glob = is_string($files) ? $temp_dir . DIRECTORY_SEPARATOR . $files
                                      : $temp_dir . DIRECTORY_SEPARATOR . '*';

            foreach (glob($glob) as $filename) {
                $output .= file_get_contents($filename);
                unlink($filename);
            }

            rmdir($temp_dir);
            return $output;
        }
    }
}

?>
