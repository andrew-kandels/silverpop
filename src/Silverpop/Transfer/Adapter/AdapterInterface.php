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

use Contain\Entity\EntityInterface;
use RuntimeException;

/**
 * Silverpop Secure-FTP File Transfer Adapter Interface
 *
 * @category    akandels
 * @package     furnace
 * @copyright   Copyright (c) 2013 Andrew P. Kandels (http://andrewkandels.com)
 * @license     http://www.opensource.org/licenses/bsd-license.php BSD License
 */
interface AdapterInterface
{
    /**
     * Opens a stream to start writing rows to.
     *
     * @return  $this
     */
    public function open();

    /**
     * Writes a single entity's values to the active stream.
     *
     * @return  $this
     */
    public function write(EntityInterface $entity);

    /**
     * Closes the stream file.
     *
     * @return  $this
     */
    public function close();

    /**
     * Flushes a connection, writing all rows in the stream through a new
     * SSH2 FTP connection.
     *
     * @return  $this
     */
    public function flush();

    /**
     * Sets the XML which maps the columns to fields on the Silverpop API.
     *
     * @param   string                              XML
     * @return  $this
     */
    public function setMap($map);

    /**
     * Returns a pointer to the map resource as to be used by the Silverpop API.
     *
     * @return  string
     */
    public function getMap();
}
