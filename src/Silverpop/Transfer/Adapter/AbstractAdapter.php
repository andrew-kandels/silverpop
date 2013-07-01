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

use InvalidArgumentException;
use RuntimeException;
use Silverpop\Service\Silverpop as SilverpopService;
use Contain\Entity\Definition\AbstractDefinition;

/**
 * Silverpop Secure-FTP File Transfer Adapter
 *
 * @category    akandels
 * @package     furnace
 * @copyright   Copyright (c) 2013 Andrew P. Kandels (http://andrewkandels.com)
 * @license     http://www.opensource.org/licenses/bsd-license.php BSD License
 */
abstract class AbstractAdapter implements AdapterInterface
{
    /**
     * @var array
     */
    protected $properties;

    /**
     * @var integer
     */
    protected $databaseId;

    /**
     * @var string
     */
    protected $action;

    /**
     * @var Contain\Entity\Definition\AbstractDefinition
     */
    protected $definition;

    /**
     * @var integer
     */
    protected $listType;

    /**
     * Properties to include as hydrated.
     *
     * @return  array
     */
    public function getProperties()
    {
        return $this->properties;
    }

    /**
     * Sets the entities to populate in the upload.
     *
     * @param   array                           Properties
     * @return  $this
     */
    public function setProperties(array $properties)
    {
        $this->properties = $properties;
        return $this;
    }

    /**
     * Sets the action for the transfer request.
     *
     * @param   string                                  Silverpop\Service\Silverpop::ACTION_*
     * @return  $this
     */
    public function setAction($action)
    {
        switch ($action) {
            case SilverpopService::ACTION_ADD_ONLY:
            case SilverpopService::ACTION_ADD_AND_UPDATE:
                break;

            default:
                throw new InvalidArgumentException('$action of \'' . $action . '\' not a valid ACTION_* '
                    . 'constant on Silverpop\Service\Silverpop.'
                );
                break;
        }

        $this->action = $action;
        return $this;
    }

    /**
     * Gets the action for the transfer request.
     *
     * @return  string
     */
    public function getAction()
    {
        return $this->action ?: SilverpopService::ACTION_ADD_AND_UPDATE;
    }

    /**
     * Gets the database id for the target of the transfer.
     *
     * @return  string
     */
    public function getDatabaseId()
    {
        if (!$this->databaseId) {
            throw new RuntimeException('No database id set, call setDatabaseId() first');
        }

        return $this->databaseId;
    }

    /**
     * Sets the database id for the target of the transfer.
     *
     * @param   string
     * @return  $this
     */
    public function setDatabaseId($id)
    {
        $this->databaseId = (int) $id;
        return $this;
    }

    /**
     * Sets the type of entity we will pass.
     *
     * @param   Contain\Entity\Definition\AbstractDefinition
     * @return  $this
     */
    public function setDefinition(AbstractDefinition $definition)
    {
        $this->definition = $definition;
        return $this;
    }

    /**
     * Gets the type of entity we will pass.
     *
     * @return  Contain\Entity\Definition\AbstractDefinition
     */
    public function getDefinition()
    {
        return $this->definition;
    }

    /**
     * Sets the type of list (see the service's LIST_* constants)
     *
     * @param   integer
     * @return  $this
     */
    public function setListType($listType)
    {
        switch ($action) {
            case SilverpopService::LIST_CONTACT:
            case SilverpopService::LIST_RELATIONAL:
            case SilverpopService::LIST_SUPPRESSION:
                break;

            default:
                throw new InvalidArgumentException('$listType of \'' . $listType . '\' not a valid LIST_* '
                    . 'constant on Silverpop\Service\Silverpop.'
                );
                break;
        }

        $this->listType = $listType;
        return $this;
    }

    /**
     * Gets the type of list.
     *
     * @return  integer
     */
    public function getListType()
    {
        return $this->listType ?: SilverpopService::LIST_CONTACT;
    }
}
