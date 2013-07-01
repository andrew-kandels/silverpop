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

namespace Silverpop\Service;

use Zend\Http\Client as HttpClient;
use Zend\Http\Headers as HttpHeaders;
use Zend\View\Renderer\PhpRenderer;
use XMLWriter;
use RuntimeException;
use InvalidArgumentException;
use Contain\Entity\EntityInterface;
use Contain\Entity\Property\Type;
use Silverpop\Transfer\Adapter\AdapterInterface;
use Silverpop\Transfer\Adapter\Sftp;
use Zend\View\Resolver\TemplatePathStack;

/**
 * Silverpop API Service
 *
 * @category    akandels
 * @package     furnace
 * @copyright   Copyright (c) 2013 Andrew P. Kandels (http://andrewkandels.com)
 * @license     http://www.opensource.org/licenses/bsd-license.php BSD License
 */
class Silverpop
{
    /**
     * Silverpop ids for list types
     *
     * @var integer
     */
    const LIST_CONTACT = 0;
    const LIST_RELATIONAL = 13;
    const LIST_SUPPRESSION = 15;

    /**
     * Silverpop data types
     *
     * @var integer
     */
    const TYPE_STRING = 0;
    const TYPE_INTEGER = 2;
    const TYPE_DATE = 3;
    const TYPE_LIST = 6;
    const TYPE_BOOLEAN = 1;
    const TYPE_EMAIL = 9;

    /**
     * Silverpop actions
     *
     * @var string
     */
    const ACTION_ADD_ONLY = 'ADD_ONLY';
    const ACTION_ADD_AND_UPDATE = 'ADD_AND_UPDATE';

    /**
     * @var config
     */
    protected $config;

    /**
     * @var Zend\View\Renderer\PhpRenderer
     */
    protected $renderer;

    /**
     * @var string
     */
    protected $sessionId;

    /**
     * @var Zend\Http\Client
     */
    protected $httpClient;

    /**
     * @var string
     */
    protected $lastRequest;

    /**
     * @var string
     */
    protected $lastRequestUri;

    /**
     * @var Zend\View\Resolver\TemplatePathStack
     */
    protected $resolver;

    /**
     * Constructor
     *
     * @param   array                       Global Config
     * @return  void
     */
    public function __construct(array $config, PhpRenderer $renderer)
    {
        $this->config = $config;
        $this->renderer = $renderer;
    }

    /**
     * Gets the PhpRenderer with this module's path stack for view scripts.
     *
     * @return  Zend\View\Renderer\PhpRenderer
     */
    public function getViewRenderer()
    {
        if (!$this->resolver) {
            $this->resolver = new \Zend\View\Resolver\TemplatePathStack(array(
                'script_paths' => array(
                    __DIR__ . '/../../../view/silverpop/xml',
                ),
            ));

            $this->renderer->resolver()->attach($this->resolver);
        }

        return $this->renderer;
    }

    /**
     * Generates a request URI for the Silverpop API to post a request
     * to.
     *
     * @param   boolean                         Is this request authenticated with a session id?
     * @return  string
     */
    protected function generateRequestUri($authenticated = true)
    {
        if ($authenticated) {
            if (!$this->sessionId) {
                throw new InvalidArgumentException('Cannot generate request uri for authenticated '
                    . 'request until login() is invoked.'
                );
            }

            return sprintf('%s?jsessionid=%s',
                $this->config['api']['uri'],
                urlencode($this->sessionId)
            );
        }

        return $this->config['api']['uri'];
    }

    /**
     * Generates an XML request for the Silverpop API to consume.
     *
     * @param   string              Operation name (e.g.: SendMailing)
     * @param   array               Parameters (key => value)
     * @param   string              Raw XML to append inside the <Body> element (optional)
     * @return  string              Raw XML
     */
    protected function generateXmlRequest($operation, array $params = array(), $raw = '')
    {
        $x = new XMLWriter();
        $x->openMemory();
        $x->startDocument('1.0');
        $x->setIndent(true);
        $x->setIndentString(str_repeat(' ', 4));
        $x->startElement('Envelope');
        $x->startElement('Body');

        $x->startElement($operation);

        foreach ($params as $key => $value) {
            if ($key == 'columns') {
                foreach ($value as $columnKey => $columnValue) {
                    $x->startElement('COLUMN');

                    $x->startElement('NAME');
                    $x->text($columnKey);
                    $x->endElement();

                    $x->startElement('VALUE');
                    $x->text($columnValue);
                    $x->endElement();

                    $x->endElement();
                }
            } else {
                $this->generateXmlElement($x, $key, $value);
            }
        }

        $x->endElement();

        $x->endElement();
        $x->endElement();
        $x->endDocument();

        $xml = $x->outputMemory();
        $xml = substr($xml, strpos($xml, '?>') + 3);

        if ($raw) {
            $xml = str_replace("</{$operation}>", $raw . "</{$operation}>", $xml);
        }

        return $xml;
    }

    /**
     * Writes a parameter, which may recursively nest to a deeper level.
     *
     * @param   XMLWriter
     * @param   string                  Key
     * @param   string|mixed            Value
     * @return  void
     */
    protected function generateXmlElement(XMLWriter $x, $key, $value)
    {
        if (is_array($value)) {
            $x->startElement($key);
            foreach ($value as $subKey => $subValue) {
                $this->generateXmlElement($x, $subKey, $subValue);
            }
            $x->endElement();
        } else {
            $x->startElement($key);
            $x->text($value);
            $x->endElement();
        }
    }

    /**
     * Retrieves a Zend Http Client for making requests to Silverpop.
     *
     * @return  Zend\Http\Client
     */
    public function getHttpClient()
    {
        if (!$this->httpClient) {
            $this->httpClient = new HttpClient();

            $options = array(
                'sslcapath' => $this->config['http_options']['sslcapath'],
                'maxredirects' => 0,
                'timeout' => $this->config['http_options']['timeout'],
                'sslverifypeer' => $this->config['http_options']['sslverifypeer'],
            );

            $this->httpClient->setOptions($options);
        }

        $headers = new HttpHeaders();
        $headers->fromString('Content-type: application/x-www-form-urlencoded');
        $this->httpClient->setHeaders($headers);

        return $this->httpClient;
    }

    /**
     * Sends an XML request to the Silverpop API.
     *
     * @param   string                          URI
     * @param   string                          XML Request Body
     * @return  SimpleXMLElement
     * @throws  RuntimeException
     */
    public function sendXmlRequest($uri, $xml)
    {
        $this->lastRequest = $xml;
        $this->lastRequestUri = $uri;

        $httpClient = $this->getHttpClient()
            ->setUri($uri)
            ->setMethod('POST')
            ->setParameterPost(array(
                'xml' => $xml,
            ));

        $response = $httpClient->send();

        if (!$xml = simplexml_load_string($response->getBody(), 'SimpleXMLElement', LIBXML_NOCDATA)) {
            $this->throwException('Failed to parse XML', $xml);
        }

        if (!isset($xml->Body->RESULT->SUCCESS)) {
            $this->throwException('Expected Body->RESULT->SUCCESS element', $response);
        }

        if (strcasecmp($xml->Body->RESULT->SUCCESS, 'true')) {
            if (isset($xml->Body->Fault->FaultString)) {
                $this->throwException(trim($xml->Body->Fault->FaultString), $response);
            }

            $this->throwException('API Reported Error', $response);
        }

        return $xml->Body->RESULT;
    }

    /**
     * Logs an error and throws an exception upon failed conversions.
     *
     * @param   string          Message
     * @param   string          Response
     * @param   string          Exception class
     * @return  void
     */
    protected function throwException($message, $response, $className = 'RuntimeException')
    {
        if ($outXml = @simplexml_load_string($response)) {
            $dom = dom_import_simplexml($outXml)->ownerDocument;
            $dom->formatOutput = true;
            $outXml = $dom->saveXML();
        } else {
            $outXml = $response;
        }

        if (!$inXml = @simplexml_load_string($this->lastRequest)) {
            $dom = dom_import_simplexml($inXml)->ownerDocument;
            $dom->formatOutput = true;
            $inXml = $dom->saveXML();
        } else {
            $inXml = $this->lastRequest;
        }

        $out = sprintf("[%s]\nURL: %s\nRequest:\n--\n%s\n\nResponse:\n--\n%s\n\n",
            $message,
            $this->lastRequestUri,
            htmlentities($inXml),
            htmlentities($outXml)
        );

        throw new $className($out);
    }

    /**
     * Converts a Contain property's data type to a Silverpop data
     * type id.
     *
     * @param   Contain\Entity\Property\Type\TypeInterface
     * @param   string                                          Property name
     * @return  integer                                         Silverpop data type id
     * @throws  InvalidArgumentException
     */
    public function findTypeIdByProperty(Type\TypeInterface $type)
    {
        if ($type instanceof Type\IntegerType) {
            return self::TYPE_INTEGER;
        }

        if ($type instanceof Type\DateTimeType ||
            $type instanceof Type\DateType) {
            return self::TYPE_DATE;
        }

        if ($type instanceof Type\EnumType) {
            return self::TYPE_LIST;
        }

        if ($type instanceof Type\BooleanType) {
            return self::TYPE_BOOLEAN;
        }

        if ($type instanceof Type\EmailType) {
            return self::TYPE_EMAIL;
        }

        if ($type instanceof Type\StringType) {
            return self::TYPE_STRING;
        }

        throw new InvalidArgumentException('$type of ' . get_class($type) . ' cannot be '
            . 'converted to a Silverpop data type.'
        );
    }

    /**
     * Logs in to Silverpop's Engage API.
     *
     * @return  $this
     * @throws  RuntimeException
     * @see     Login (Silverpop XML API)
     */
    public function login()
    {
        // already logged in?
        if ($this->sessionId !== null) {
            return $this;
        }

        $response = $this->sendXmlRequest(
            $this->generateRequestUri(false),
            $this->generateXmlRequest('Login', array(
                'USERNAME' => $this->config['api']['user'],
                'PASSWORD' => $this->config['api']['pass'],
            ))
        );

        if (!$this->sessionId = $response->SESSIONID) {
            throw new RuntimeException('Failed to login. Successful response; but, no reason given');
        }

        return $this;
    }

    /**
     * Logs out of the API and clears the jsessionID (if set).
     *
     * @return  $this
     * @see     Logout (Silverpop XML API)
     */
    public function logout()
    {
        // already logged in?
        if ($this->sessionId === null) {
            return $this;
        }

        $response = $this->sendXmlRequest(
            $this->generateRequestUri(),
            $this->generateXmlRequest('Logout', array())
        );

        $this->sessionId = null;

        return $this;
    }

    /**
     * Looks up lists of database ids from the Silverpop API by their list type.
     * See the LIST_* contacts for a list of available types.
     *
     * @param   integer                             List Type
     * @param   string                              For relational list type, the name
     * @return  array
     * @see     GetLists (Silverpop XML API)
     */
    public function findDatabaseByListType($listType = null, $relationalName = null)
    {
        if ($listType === null) {
            $listType = self::LIST_CONTACT;
        }

        $this->login(); // requires login

        $response = $this->sendXmlRequest(
            $this->generateRequestUri(),
            $this->generateXmlRequest('GetLists', array(
                'VISIBILITY' => 1,
                'LIST_TYPE'  => $listType,
            ))
        );

        $return = array();

        foreach ($response->LIST as $db) {
            $name = strval($db->NAME);

            switch ($listType) {
                case self::LIST_CONTACT:
                    if (!strcasecmp($name, 'Contacts')) {
                        foreach ($db->children() as $key => $value) {
                            $return[strval($key)] = strval($value);
                        }
                    }
                    break;

                case self::LIST_RELATIONAL:
                    if ($name == $relationalName) {
                        foreach ($db->children() as $key => $value) {
                            $return[strval($key)] = strval($value);
                        }
                    }
                    break;

                case self::LIST_SUPPRESSION:
                    foreach ($db->children() as $key => $value) {
                        $return[strval($key)] = strval($value);
                    }
                    break;
            }
        }

        return $return;
    }

    /**
     * Generates an XML map file for the importList() method.
     *
     * @param   Silverpop\Transfer\Adapter\AdapterInterface
     * @return  string
     */
    public function generalImportListXmlMap(AdapterInterface $transferAdapter)
    {
        if (!$properties = $transferAdapter->getProperties()) {
            throw new InvalidArgumentException('$transferAdapter does not have any exposed properties '
                . 'to import. Call setProperties() first.'
            );
        }

        $entityClass = str_replace('\\Definition\\', '\\', get_class($transferAdapter->getDefinition()));
        $entity      = new $entityClass();

        foreach ($properties as $property) {
            $options = $transferAdapter->getDefinition()->getProperty($property)->getOptions();

            $fields[$property] = array(
                'name' => isset($options['field'])
                    ? $options['field']
                    : $property,
                'type' => isset($options['silverpopType'])
                    ? (int) $options['silverpopType']
                    : $this->findTypeIdByProperty($entity->type($property)),
                'primary' => false,
                'required' => false,
                'key' => false,
                'default' => null,
            );

            if ($fields[$property]['type'] == self::TYPE_LIST) {
                $fields[$property]['value_options'] = array();
                if (isset($options['options']['value_options'])) {
                    $fields[$property]['value_options'] = $options['options']['value_options'];
                }
            }

            if (!empty($options['primary'])) {
                $fields[$property]['primary'] = true;
            }

            if (!empty($options['required'])) {
                $fields[$property]['required'] = true;
            }

            if (!empty($options['key'])) {
                $fields[$property]['key'] = true;
            }

            if (isset($options['defaultValue'])) {
                $fields[$property]['default'] = $type->parse($options['defaultValue']);
            }
        }

        return $this->getViewRenderer()->render('import-list', array(
            'fields'     => $fields,
            'action'     => $transferAdapter->getAction(),
            'listType'   => $transferAdapter->getListType(),
            'databaseId' => $transferAdapter->getDatabaseId(),
        ));
    }

    /**
     * Generates an XML request for the ImportList API in the
     * Silverpop API.
     *
     * @param   Silverpop\Transfer\Adapter\AdapterInterface
     * @see     ImportList (Silverpop XML API)
     */
    public function importList(AdapterInterface $transferAdapter)
    {
        $this->login(); // requires login

        $xml = $this->generalImportListXmlMap($transferAdapter);

        // uploads the map XML file to Silverpop (used to define/map fields)
        $transferAdapter->setMap($xml);

        // upload the contacts to Silverpop
        $transferAdapter->flush();

        // process the uploaded files
        // @todo tweak this if other adapters are introduced
        $response = $this->sendXmlRequest(
            $this->generateRequestUri(),
            $this->generateXmlRequest('ImportList', array(
                'MAP_FILE' => basename($transferAdapter->getMap()),
                'SOURCE_FILE' => basename($transferAdapter->getRemoteFile()),
            ))
        );

        return (int) $response->JOB_ID;
    }

    /**
     * Polls for the status of a running Silverpop job.
     *
     * @param   integer         Job ID
     * @return  array
     * @see     GetJobStatus (Silverpop XML API)
     */
    public function getJobStatus($jobId)
    {
        $this->login(); // requires login

        $response = $this->sendXmlRequest(
            $this->generateRequestUri(),
            $this->generateXmlRequest('GetJobStatus', array(
                'JOB_ID' => (int) $jobId
            ))
        );

        $result = array(
            'status'        => trim($response->JOB_STATUS),
            'description'   => trim($response->JOB_DESCRIPTION),
            'parameters'    => array(),
            'errors'        => '',
            'messages'      => '',
        );

        foreach ($response->PARAMETERS->PARAMETER as $parameter) {
            $result['parameters'][trim($parameter->NAME)] = trim($parameter->VALUE);
        }

        if ($result['status'] == 'ERROR' && !empty($result['parameters']['ERROR_FILE_NAME'])) {
            try {
                $adapter = new Sftp($this->config['adapter']['parameters']);
                $adapter->setRemoteFile('/download/' . $result['parameters']['ERROR_FILE_NAME']);
                $result['errors'] = $adapter->read();
            } catch (Exception $e) {
                printf("WARNING: Failed to download '%s' from Silverpop\n",
                    $result['parameters']['ERROR_FILE_NAME']
                );
            }

        } elseif ($result['status'] == 'COMPLETE' && !empty($result['parameters']['RESULTS_FILE_NAME'])) {
            try {
                $adapter = new Sftp($this->config['adapter']['parameters']);
                $adapter->setRemoteFile('/download/' . $result['parameters']['RESULTS_FILE_NAME']);
                $result['messages'] = $adapter->read();
            } catch (Exception $e) {
                printf("WARNING: Failed to download '%s' from Silverpop\n",
                    $result['parameters']['RESULTS_FILE_NAME']
                );
            }
        }

        return $result;
    }

    /**
     * Clean up
     *
     * @return void
     */
    public function __destruct()
    {
        // $this->logout();
    }





















    /**
     * Delivers a transact email through the Silverpop API.
     *
     * @param   ????
     * @return  $this
     * @throws  RuntimeException
     */
    public function sendTransact()
    {
        $transactionId = uniqid('', true);

        // generate xml

        $response = $this->sendXmlRequest(
            $this->generateRequestUri(false),
            $xml = '??? DO THIS ??? @todo'
        );

        if (!isset($response->TRANSACTION_ID) || $response->TRANSACTION_ID != $transactionId) {
            throw new RuntimeException(sprintf('Received mismatched transaction id from Silverpop API: %s',
                $response->getBody()
            ));
        }

        if (!isset($response->EMAILS_SENT) || (int) $response->EMAILS_SENT != 1) {
            $errorCode = isset($response->ERROR_CODE) ? $response->ERROR_CODE : '???';
            $errorMsg = isset($response->ERROR_STRING) ? $response->ERROR_STRING : 'Unknown Error';

            throw new RuntimeException(
                sprintf('Failed to send Silverpop email, error %s: %s. Response: %s',
                    $errorCode,
                    $errorMsg,
                    $response->getBody()
                )
            );
        }

        return $this;
    }





/*
    /**
     * Sends an email message via Silverpop transact.
     *
     * @param   Network\Entity\EmailMessage
     * @return  boolean
     ..
    public function sendTransact(EmailMessageEntity $email)
    {
        $requestUrl    = $this->config['api']['uri'];
        $viewScript    = getcwd() . '/module/Network/view/network/silverpop/request.xml.phtml';
        $transactionId = uniqid('');

        ob_start();

        if (!$template = include($viewScript)) {
            ob_end_clean();

            Log::write(
                sprintf('Failed to create Silverpop XML request for %s with \'%s\'',
                    $email->getSilverpopId(),
                    $viewScript
                ),
                Log::LOG_EXCEPTION,
                'email'
            );

            return false;
        }

        $request = ob_get_contents();
        ob_end_clean();

        $httpClient = $this->getHttpClient()
            ->setUri($requestUrl)
            ->setMethod('POST')
            ->setParameterPost(array(
                'xml' => $request,
            ));

        $response = $httpClient->send();

        if (!$xml = @simplexml_load_string($response->getBody())) {
            Log::write(
                sprintf('Received BAD RESPONSE from Silverpop for %s, non-XML: %s',
                    $email->getTemplate(),
                    $response->getBody()
                ),
                Log::LOG_EXCEPTION,
                'email'
            );

            return false;
        }

        if (!isset($xml->TRANSACTION_ID) || $xml->TRANSACTION_ID != $transactionId) {
            Log::write(
                sprintf('Received mismatched transaction id from Silverpop API: %s',
                    $response->getBody()
                ),
                Log::LOG_EXCEPTION,
                'email'
            );

            return false;
        }

        if (!isset($xml->EMAILS_SENT) || (int) $xml->EMAILS_SENT != 1) {
            $errorCode = isset($xml->ERROR_CODE) ? $xml->ERROR_CODE : '???';
            $errorMsg = isset($xml->ERROR_STRING) ? $xml->ERROR_STRING : 'Unknown Error';

            Log::write(
                sprintf('Failed to send Silverpop %s email, error %s: %s',
                    $email->getTemplate(),
                    $errorCode,
                    $errorMsg
                ),
                Log::LOG_EXCEPTION,
                'email'
            );

            Log::write(
                sprintf('Silverpop Response: %s',
                    $response->getBody()
                ),
                Log::LOG_NOTICE,
                'email'
            );

            return false;
        }

        Log::write(
            $msg = sprintf('[%s] Sent silverpop "%s" email to "%s" successfully.' . PHP_EOL,
                date('Y-m-d H:i:s'),
                $email->getTemplate(),
                $email->getRecipientEmail()->getAddress()
            ),
            Log::LOG_NOTICE,
            'email'
        );

        file_put_contents(
            getcwd() . '/logs/email.log',
            $msg,
            FILE_APPEND
        );

        return true;
    }































    /**
     * Retrieves the database ID for the master contacts database.
     * This is retrieves from the API and cached.
     *
     * @return  integer
     ..
    public function getDatabaseId()
    {
        if (!$this->database) {
            $this->getDatabases();
        }

        return (int) $this->database['ID'];
    }



    /**
     * Creates a relational table via the Silverpop API. Uses the table
     * definition in APPLICATION_ENV "/config/silverpop/<list>.xml
     *
     * @param   string          Name of the list
     * @return  integer         Table ID
     ..
    public function createTable($table)
    {
        $xml = $this->getTableDefinition($table);
        if (!isset($xml->silverpop->COLUMNS)) {
            throw new UnexpectedValueException("The definition '$xmlFile' should contain a "
                . "silverpop node populated with COLUMNS"
            );
        }

        if (isset($this->relationalTables[strval($xml->silverpop['table'])])) {
            throw new InvalidArgumentException('Table already exists');
        }

        $response = $this->makeRequest('CreateTable', array(
            'TABLE_NAME' => trim($xml->silverpop['table']),
        ), $xml->silverpop->COLUMNS->asXML());

        $id = (int) $response->TABLE_ID;

        $this->database = array();
        $this->relationalTables = array();

        $this->getDatabases();

        return $id;
    }

    /**
     * Joins a relational table against the master database.
     *
     * @param   string                  Name of the relational table
     * @return  integer                 Job ID
     ..
    public function joinTable($table)
    {
        $this->getDatabases();
        $xml = $this->getTableDefinition($table);

        if (empty($this->relationalTables[strval($xml->silverpop['table'])])) {
            throw new InvalidArgumentException("Table '$table' has not "
                . "been created yet, use createTable instead"
            );
        }
        $relationalTable = $this->relationalTables[strval($xml->silverpop['table'])];

        $response = $this->makeRequest('JoinTable', array(
            'TABLE_ID' => (int) $relationalTable['ID'],
            'LIST_ID' => $this->getDatabaseId(),
            'TABLE_VISIBILITY' => 1,
            'LIST_VISIBILITY' => 1,
            'MAP_FIELD' => array(
                'LIST_FIELD' => 'Email ID',
                'TABLE_FIELD' => 'Email ID',
            ),
        ));

        return (int) $response->JOB_ID;
    }

    /**
     * Returns a SimpleXML object for a given relational table definition.
     *
     * @param   string                  Name of the relational table
     * @return  SimpleXML
     ..
    protected function getTableDefinition($table)
    {
        $xmlFile = sprintf('%s/config/silverpop/%s.xml',
            APPLICATION_PATH,
            $table
        );

        if (!file_exists($xmlFile)) {
            throw new RuntimeException("Definition '$xmlFile' file does not exist.");
        }

        if (!$xml = @simplexml_load_file($xmlFile)) {
            throw new RuntimeException("Failed to parse '$xmlFile'");
        }

        return $xml;
    }

    /**
     * Adds a recipient to the mailing list.
     *
     * @param   integer             Email ID
     * @param   string              Email address
     * @param   array               Additional custom fields
     * @return  integer             Recipient ID
     ..
    public function addRecipient($email, array $params = array())
    {
        $params['Email ID'] = $emailId;
        $params['Email'] = $email;

        $response = $this->makeRequest('AddRecipient', array(
            'LIST_ID' => $this->getDatabaseId(),
            'CREATED_FROM' => 1,
            'UPDATE_IF_FOUND' => 'true',
            'columns' => $params,
        ));

        return $response->RecipientId;
    }

    /**
     * Double opts-in an email contact either by their email id or their
     * address.
     *
     * @param   string          Email or email id
     * @return  fluent
     ..
    public function doubleOptInRecipient($email)
    {
        $email = $this->emailService->search($email);

        $this->makeRequest('DoubleOptInRecipient', array(
            'LIST_ID' => $this->getDatabaseId(),
            'columns' => array(
                'Email ID' => $email['id'],
                'Email' => $email['email'],
            ),
        ));

        return $this;
    }

    /**
     * Interactively tracks the status of a job with the API until
     * Silverpop considers the job complete. Routinely prints status
     * information to stdout.
     *
     * @param   integer                 Job ID #
     * @param   integer                 Heartbeat
     * @return  integer                 Exit code
     ..
    public function trackJobStatus($jobId, $heartbeat = 5)
    {
        do {
            sleep($heartbeat);

            $status = $this->getJobStatus($jobId);

            if ($status['status'] == 'WAITING' || $status['status'] == 'RUNNING') {
                do_log(sprintf('%s (%s rows processed) ... ',
                    $status['status'],
                    isset($status['parameters']['TOTAL_ROWS'])
                        ? $status['parameters']['TOTAL_ROWS']
                        : '?'
                ), true);
            } else {
                okay();

                printf("[%s: #%d - %s]\n%s\n--\n",
                    date('Y-m-d H:i:s'),
                    $jobId,
                    $status['status'],
                    $status['description']
                );

                foreach ($status['parameters'] as $key => $value) {
                    printf("%-20s: %s\n", $key, $value);
                }

                if (isset($status['errors']) && file_exists($status['errors'])) {
                    printf("--\nAPI Server Errors:\n%s", file_get_contents($status['errors']));
                }

                if (isset($status['messages']) && file_exists($status['messages'])) {
                    printf("--\nAPI Messages:\n%s", file_get_contents($status['messages']));
                }

                printf("\n--\n\n");
            }

            switch ($status['status']) {
                case 'COMPLETE':
                    return 0;

                case 'CANCELED':
                case 'ERROR':
                    return 1;

                case 'WAITING':
                case 'RUNNING':
                default:
                    // keep running
                    break;
            }
        } while(1);
    }



    /**
     * Tells SilverPop to export recitient data to a file in their ftp area
     *
     * @param   string      $startDate      starting date of update - m/d/y form
     * @param   string      $endDate        ending date of update - m/d/y form
     * @return  xml response            xml response from API call
     ..
    public function exportRecipientData($startDate, $endDate)
    {

        $response = $this->makeRequest('RawRecipientDataExport', array(
            'EVENT_DATE_START' => $startDate,
            'EVENT_DATE_END' => $endDate,
            'EXPORT_FORMAT' => 0,
            //'EMAIL' => 'bi@caringbridge.org',
            ),
            '<ALL_EVENT_TYPES/>
            <MOVE_TO_FTP/>'
        );

        if ('TRUE' <> $response->SUCCESS)
        {
            throw new Exception('SilverPop exporting RawRecipientDataExport failed');
        }

        return $response;

    }



    /**
     * Fetches the recipient data (metrics) from SilverPop
     *    - Tells SilverPop to export data
     *    - Waits for SilverPop to complete
     *    - ftps file to local server
     *    - file is left on SilverPop (they delete it after 30 days - I can't figure out how to delete it
     *          via the API
     *
     * @param   string  $startDate      starting date of data - format of mm/dd/yyyy
     * @param   string  $endDate        ending date of date - format of mm/dd/yyyy
     * @return string                   filename and path of local copy of file
     ..
    public function fetchRecipientData($startDate, $endDate)
    {
        do_log('Fetching metric data from SilverPop');
        $response = $this->exportRecipientData($startDate, $endDate);

        $jobId = $response->MAILING->JOB_ID;
        $fileName = $response->MAILING->FILE_PATH;

        $this->trackJobStatus($jobId);

        $localFile = $this->downloadFile($fileName);

        okay();

        return ($localFile);

    }


    /**
     * Gets the entire list of mailings from SilverPop
     *
     * @return  response            array with mailing information
     ..
    public function getSentMailings(){

        $response = $this->makeRequest('GetSentMailingsForOrg', array(
            'DATE_START' => date("01/01/2012 00:00:00"),
            'DATE_END' => date("m/d/Y " . "23:59:59"),
            )
        );

        if ('TRUE' <> $response->SUCCESS)
        {
            throw new Exception('SilverPop GetSentMailingsForOrg failed');
        }

        return $response;

    }
    */
}

