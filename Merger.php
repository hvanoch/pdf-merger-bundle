<?php

namespace Hvanoch\Bundle\PdfMergerBundle;

use Symfony\Component\Process\Process;

class Merger
{
    static private $options = [
        '-dBATCH',
        '-dNOPAUSE',
        '-q',
        '-sDEVICE=pdfwrite',
        '-sOutputFile=',
    ];

    /**
     * @var string
     */
    private $binary;

    /**
     * @var string
     */
    protected $temporaryFolder;

    /**
     * @var array
     */
    public $temporaryFiles = array();

    /**
     * Merger constructor.
     * @param $binary
     * @param null $temporaryFolder
     */
    public function __construct($binary, $temporaryFolder = null)
    {
        $this->binary = $binary;
        register_shutdown_function(array($this, 'removeTemporaryFiles'));
    }


    public function __destruct()
    {
        $this->removeTemporaryFiles();
    }


    /**
     * @param $output
     * @param array $input
     * @param bool $overwrite
     * @throws \Exception
     */
    public function merge($output, array$input, $overwrite = false)
    {
        $this->prepareOutput($output, $overwrite);

        $command = $this->buildCommand($output, $input);

        list($status, $stdout, $stderr) = $this->executeCommand($command);

        $this->checkProcessStatus($status, $stdout, $stderr, $command);

        $this->checkOutput($output, $command);

    }

    /**
     * @param array $input
     * @param bool $overwrite
     * @return string
     */
    public function getOutput(array$input, $overwrite = false)
    {
        $output = $this->createTemporaryFile();
        $this->merge($output, $input, $overwrite);

        return $this->getFileContents($output);

    }

    /**
     * Checks the process return status
     *
     * @param int $status The exit status code
     * @param string $stdout The stdout content
     * @param string $stderr The stderr content
     * @param string $command The run command
     *
     * @throws \RuntimeException if the output file generation failed
     */
    protected function checkProcessStatus($status, $stdout, $stderr, $command)
    {
        if (0 !== $status and '' !== $stderr) {
            throw new \RuntimeException(sprintf(
                'The exit status code \'%s\' says something went wrong:' . "\n"
                . 'stderr: "%s"' . "\n"
                . 'stdout: "%s"' . "\n"
                . 'command: %s.',
                $status, $stderr, $stdout, $command
            ));
        }
    }

    protected function buildCommand($output, array$input)
    {
        $command = $this->binary;
        $escapedCommand = escapeshellarg($command);
        if (is_executable($escapedCommand)) {
            $command = $escapedCommand;
        }

        foreach (self::$options as $option) {
            $command .= ' ' . $option;
        }

        $command .= escapeshellarg($output);

        foreach ($input as $inputFile) {
            $command .= ' ' . escapeshellarg($inputFile);
        }

        return $command;

    }

    protected function executeCommand($command)
    {
        $process = new Process($command);

        $process->run();

        return array(
            $process->getExitCode(),
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }


    /**
     * @param $filename
     * @param $overwrite
     * @throws \Exception
     */
    protected function prepareOutput($filename, $overwrite)
    {
        $directory = dirname($filename);

        if ($this->fileExists($filename)) {
            if (!$this->isFile($filename)) {
                throw new \InvalidArgumentException(sprintf(
                    'The output file \'%s\' already exists and it is a %s.',
                    $filename, $this->isDir($filename) ? 'directory' : 'link'
                ));
            } elseif (false === $overwrite) {
                throw new \Exception(sprintf(
                    'The output file \'%s\' already exists.',
                    $filename
                ));
            } elseif (!$this->unlink($filename)) {
                throw new \RuntimeException(sprintf(
                    'Could not delete already existing output file \'%s\'.',
                    $filename
                ));
            }
        } elseif (!$this->isDir($directory) && !$this->mkdir($directory)) {
            throw new \RuntimeException(sprintf(
                'The output file\'s directory \'%s\' could not be created.',
                $directory
            ));
        }
    }

    /**
     * Checks the specified output
     *
     * @param string $output The output filename
     * @param string $command The generation command
     *
     * @throws \RuntimeException if the output file generation failed
     */
    protected function checkOutput($output, $command)
    {
        // the output file must exist
        if (!$this->fileExists($output)) {
            throw new \RuntimeException(sprintf(
                'The file \'%s\' was not created (command: %s).',
                $output, $command
            ));
        }

        // the output file must not be empty
        if (0 === $this->filesize($output)) {
            throw new \RuntimeException(sprintf(
                'The file \'%s\' was created but is empty (command: %s).',
                $output, $command
            ));
        }
    }

    /**
     * Creates a temporary file.
     *
     * @return string The filename
     */
    protected function createTemporaryFile()
    {
        $dir = rtrim($this->getTemporaryFolder(), DIRECTORY_SEPARATOR);

        if (!is_dir($dir)) {
            if (false === @mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf("Unable to create directory: %s\n", $dir));
            }
        } elseif (!is_writable($dir)) {
            throw new \RuntimeException(sprintf("Unable to write in directory: %s\n", $dir));
        }

        $filename = $dir . DIRECTORY_SEPARATOR . uniqid('hvanoch_pdf_merger', true);

        $this->temporaryFiles[] = $filename;
        dump($filename);
        return $filename;
    }

    /**
     * Removes all temporary files
     */
    public function removeTemporaryFiles()
    {
        foreach ($this->temporaryFiles as $file) {
            $this->unlink($file);
        }
    }


    /**
     * Get TemporaryFolder
     *
     * @return string
     */
    public function getTemporaryFolder()
    {
        if ($this->temporaryFolder === null) {
            return sys_get_temp_dir();
        }

        return $this->temporaryFolder;
    }

    /**
     * Set temporaryFolder
     *
     * @param string $temporaryFolder
     *
     * @return $this
     */
    public function setTemporaryFolder($temporaryFolder)
    {
        $this->temporaryFolder = $temporaryFolder;

        return $this;
    }

    /**
     * Wrapper for the "file_get_contents" function
     *
     * @param string $filename
     *
     * @return string
     */
    protected function getFileContents($filename)
    {
        return file_get_contents($filename);
    }

    /**
     * Wrapper for the "file_exists" function
     *
     * @param string $filename
     *
     * @return boolean
     */
    protected function fileExists($filename)
    {
        return file_exists($filename);
    }

    /**
     * Wrapper for the "is_file" method
     *
     * @param string $filename
     *
     * @return boolean
     */
    protected function isFile($filename)
    {
        return is_file($filename);
    }

    /**
     * Wrapper for the "filesize" function
     *
     * @param string $filename
     *
     * @return integer or FALSE on failure
     */
    protected function filesize($filename)
    {
        return filesize($filename);
    }

    /**
     * Wrapper for the "unlink" function
     *
     * @param string $filename
     *
     * @return boolean
     */
    protected function unlink($filename)
    {
        return $this->fileExists($filename) ? unlink($filename) : false;
    }

    /**
     * Wrapper for the "is_dir" function
     *
     * @param string $filename
     *
     * @return boolean
     */
    protected function isDir($filename)
    {
        return is_dir($filename);
    }

    /**
     * Wrapper for the mkdir function
     *
     * @param string $pathname
     *
     * @return boolean
     */
    protected function mkdir($pathname)
    {
        return mkdir($pathname, 0777, true);
    }
}