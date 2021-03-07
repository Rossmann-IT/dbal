<?php

namespace Doctrine\DBAL\Driver\OCI8;

use Doctrine\DBAL\Driver\AbstractOracleDriver;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\Oracle121Platform;
use Doctrine\DBAL\Platforms\Oracle122Platform;
use Doctrine\DBAL\Schema\Oracle121SchemaManager;
use Doctrine\DBAL\Schema\Oracle122SchemaManager;
use Doctrine\DBAL\Schema\OracleSchemaManager;

use const OCI_NO_AUTO_COMMIT;

/**
 * A Doctrine DBAL driver for the Oracle OCI8 PHP extensions.
 */
class Driver extends AbstractOracleDriver
{
    /**
     * {@inheritdoc}
     */
    public function connect(array $params, $username = null, $password = null, array $driverOptions = [])
    {
        try {
            return new Connection(
                (string) $username,
                (string) $password,
                $this->_constructDsn($params),
                $params['charset'] ?? '',
                $params['sessionMode'] ?? OCI_NO_AUTO_COMMIT,
                $params['persistent'] ?? false
            );
        } catch (OCI8Exception $e) {
            throw Exception::driverException($this, $e);
        }
    }

    /**
     * Constructs the Oracle DSN.
     *
     * @param mixed[] $params
     *
     * @return string The DSN.
     */
    protected function _constructDsn(array $params)
    {
        return $this->getEasyConnectString($params);
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated
     */
    public function getName()
    {
        return 'oci8';
    }

    /**
     * Uses a special Oracle121SchemaManager for versions >= 12.1
     *
     * @param \Doctrine\DBAL\Connection $conn
     * @return Oracle121SchemaManager|OracleSchemaManager
     */
    public function getSchemaManager(\Doctrine\DBAL\Connection $conn)
    {
        $platform = $conn->getDatabasePlatform();
        if ($platform instanceof Oracle122Platform) {
            return new Oracle122SchemaManager($conn);
        }
        if ($platform instanceof Oracle121Platform) {
            return new Oracle121SchemaManager($conn);
        }
        return new OracleSchemaManager($conn);
    }

}
