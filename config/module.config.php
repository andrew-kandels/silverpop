<?php
return array(
    'silverpop' => array(
        /*
        'api' => array(
            'uri' => 'http://api5.silverpop.com/XMLAPI',
            'user' => '',
            'pass' => '',
        ),
        'http_options' => array(
            'sslcapath' => '',
            'maxredirects' => 0,
            'timeout' => 90,
            'sslverifypeer' => false,
        ),
        'adapter' => array(
            'name' => 'Sftp',
            'parameters' => array(
                'host' => 'transfer5.silverpop.com',
                'port' => 22,
                'user' => '',
                'pass' => '',
            ),
        ),
        */
    ),
    'service_manager' => array(
        'factories' => array(
            'SilverpopService' => 'Silverpop\Factory\Service\Silverpop',

            // default adapter: (from config)
            'SilverpopAdapter' => 'Silverpop\Factory\Transfer\Transfer',

            // adapter options:
            'SilverpopSftpAdapter' => 'Silverpop\Factory\Transfer\Adapter\Sftp',
        ),
    ),
);
