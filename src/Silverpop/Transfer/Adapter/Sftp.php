<?php
/**
 * Furnace Project
 *
 * This source file is subject to the BSD license bundled with
 * this package in the LICENSE.txt file. It is also available
 * on the world-wide-web at http://www.opensource.org/licenses/bsd-license.php.
 * If you are unable to receive a copy of the license or have
 * questions concerning the terms, please send an email to
 * me@andrewkandels.com.
 *
 * @category    akandels
 * @package     furnace
 * @author      Andrew Kandels (me@andrewkandels.com)
 * @copyright   Copyright (c) 2013 Andrew P. Kandels (http://andrewkandels.com)
 * @license     http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link        http://contain-project.org/furnace
 */

namespace Silverpop\Transfer\Adapter;

use RuntimeException;
use Contain\Entity\EntityInterface;

/**
 * Silverpop Secure-FTP File Transfer Adapter
 *
 * @category    akandels
 * @package     furnace
 * @copyright   Copyright (c) 2013 Andrew P. Kandels (http://andrewkandels.com)
 * @license     http://www.opensource.org/licenses/bsd-license.php BSD License
 */
class Sftp extends AbstractAdapter
{
    /**
     * @var array
     */
    protected $config;

    /**
     * SSH2 resource
     *
     * @var resource
     */
    protected $connection;

    /**
     * @var string
     */
    protected $fp;

    /**
     * @var string
     */
    protected $file;

    /**
     * @var string
     */
    protected $remoteFile;

    /**
     * @var integer
     */
    protected $bufferSize = 8192;

    /**
     * Constructor
     *
     * @param   array
     * @return  void
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        if (!extension_loaded('ssh2')) {
            throw new RuntimeException('Sftp adapter requires the PHP ssh2 extension');
        }

        if (isset($this->config['remoteFile'])) {
            $this->setRemoteFile($this->config['remoteFile']);
        }

        if (isset($this->config['bufferSize'])) {
            $this->setBufferSize($this->config['bufferSize']);
        }
    }

    /**
     * Connects to a Silverpop S-FTP server.
     *
     * @return  resource
     */
    public function getConnection()
    {
        if (!$this->connection) {
            $conn = ssh2_connect($this->config['host'], $this->config['port']);

            if (!ssh2_auth_password($conn, $this->config['user'], $this->config['pass'])) {
                throw new RuntimeException(sprintf('ssh2_auth_password failed to connect to %s:******@%s:%d',
                    $this->config['user'],
                    $this->config['host'],
                    $this->config['port']
                ));
            }

            if (!$this->connection = ssh2_sftp($conn)) {
                throw new RuntimeException("Failed to open '$conn' via ssh2_sftp.");
            }
        }

        return $this->connection;
    }

    /**
     * Opens a stream to start writing rows to.
     *
     * @return  $this
     */
    public function open()
    {
        if ($this->fp) {
            return $this->fp;
        }

        $this->file = tempnam('/tmp', 'silverpop');

        if (!$this->fp = fopen($this->file, 'wt')) {
            throw new RuntimeException("Failed to open '{$this->file}' for writing.");
        }

        return $this->fp;
    }

    /**
     * Writes a single entity's values to the active stream.
     *
     * @return  $this
     */
    public function write(EntityInterface $entity)
    {
        if (!$this->properties) {
            $this->properties = array_keys($entity->export());
        }

        if (!$this->fp) {
            $this->open();
        }

        $export = array();
        foreach ($this->properties as $property) {
            $export[] = $entity->property($property)->getExport() ?: '';
        }

        fprintf($this->fp, "%s\n", implode("\t", $export));

        return $this;
    }

    /**
     * Closes the stream file.
     *
     * @return  $this
     */
    public function close()
    {
        if (!$this->fp) {
            return $this;
        }

        fclose($this->fp);
        $this->fp = null;

        return $this;
    }

    /**
     * Sets what the file should be named on Silverpop's side.
     *
     * @param   string                                      File name
     * @return  $this
     */
    public function setRemoteFile($file)
    {
        $this->remoteFile = $file;
        return $this;
    }

    /**
     * Gets what the file should be named on Silverpop's side.
     *
     * @return  string
     */
    public function getRemoteFile()
    {
        return $this->remoteFile;
    }

    /**
     * Sets the size of the buffer for uploading your list.
     *
     * @param   integer                                     Bytes
     * @return  $this
     */
    public function setBufferSize($bytes)
    {
        $this->bufferSize = (int) $bytes;
        return $this;
    }

    /**
     * Flushes a connection, writing all rows in the stream through a new
     * SSH2 FTP connection.
     *
     * @return  $this
     */
    public function flush()
    {
        if ($this->getRemoteFile() === null) {
            throw new RuntimeException('Cannot determine name of remote file, call setRemoteFile()');
        }

        $this->close();

        if (!$input = fopen($this->file, 'rt')) {
            throw new RuntimeException('Stream file not found, did you call open()?');
        }

        $bytes = filesize($this->file);

        $cn = sprintf('ssh2.sftp://%s%s',
            $this->getConnection(),
            $this->getRemoteFile()
        );

        if (!$output = fopen($cn, 'w')) {
            throw new RuntimeException("Failed to open '$cn' as a stream write resource.");
        }

        for ($bytes = 0, $i = 0; $buffer = fread($input, $this->bufferSize); $bytes += $this->bufferSize, $i++) {
            fwrite($output, $buffer);
        }

        fclose($input);
        fclose($output);

        //unlink($this->file);

        return $this;
    }

    /**
     * Returns a pointer to the map resource as to be used by the Silverpop API.
     *
     * @return  string
     */
    public function getMap()
    {
        if ($this->getRemoteFile() === null) {
            throw new RuntimeException('Cannot determine name of remote file, call setRemoteFile()');
        }

        $parts = explode('/', $this->getRemoteFile());

        return sprintf('%s/map%s.xml',
            implode('/', array_slice($parts, 0, -1)),
            implode('', array_slice($parts, -1))
        );
    }

    /**
     * Sets the XML which maps the columns to fields on the Silverpop API.
     *
     * @param   string                              XML
     * @return  $this
     */
    public function setMap($map)
    {
        $mapFile = $this->getMap();
        $bytes   = strlen($map);

        $cn = sprintf('ssh2.sftp://%s%s',
            $this->getConnection(),
            $mapFile
        );

        if (!$output = fopen($cn, 'w')) {
            throw new RuntimeException("Failed to open '$cn' as a stream write resource.");
        }

        fwrite($output, $map);

        fclose($output);

        return $this;
    }

    /**
     * Reads data from the server.
     *
     * @return  string
     */
    public function read()
    {
        if ($this->getRemoteFile() === null) {
            throw new RuntimeException('Cannot determine name of remote file, call setRemoteFile()');
        }

        $cn = sprintf('ssh2.sftp://%s%s',
            $this->getConnection(),
            $this->getRemoteFile()
        );

        if (!$stream = fopen($cn, 'r')) {
            throw new RuntimeException("Failed to open '$cn' as a stream read resource.");
        }

        $response = '';

        while ($buffer = fread($stream, $this->getBufferSize())) {
            $response .= $buffer;
        }

        return $response;
    }
}
