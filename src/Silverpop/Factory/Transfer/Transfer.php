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

namespace Silverpop\Factory\Transfer;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Silverpop\Factory\Adapter\Sftp as SftpAdapter;

/**
 * Factory class for the active Silverpop transfer adapter.
 *
 * @category    akandels
 * @package     furnace
 * @copyright   Copyright (c) 2013 Andrew P. Kandels (http://andrewkandels.com)
 * @license     http://www.opensource.org/licenses/bsd-license.php BSD License
 */
class Transfer implements FactoryInterface
{
    /**
     * Create the service (factory)
     *
     * @param   Zend\ServiceManager\ServiceLocatorInterface
     * @return  Service|null
     */
    public function createService(ServiceLocatorInterface $sm)
    {
        $config = $sm->get('config');
        $config = $config['silverpop'];

        $name = implode('', array_map(function($a) {
            return ucfirst($a);
        }, explode('-', $config['adapter']['name'])));

        $alias = sprintf('Silverpop%sAdapter', $name);

        return $sm->get($alias);
    }
}
