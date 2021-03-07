<?php

namespace Doctrine\DBAL\Driver;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractOracleDriver\EasyConnectString;
use Doctrine\DBAL\Driver\DriverException as DeprecatedDriverException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\NonUniqueFieldNameException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\Oracle121Platform;
use Doctrine\DBAL\Platforms\Oracle122Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Schema\OracleSchemaManager;
use Doctrine\DBAL\VersionAwarePlatformDriver;

/**
 * Abstract base implementation of the {@link Driver} interface for Oracle based drivers.
 */
abstract class AbstractOracleDriver implements Driver, ExceptionConverterDriver, VersionAwarePlatformDriver
{

    /**
     * Uses a special Oracle121Platform for versions >= 12.1
     *
     * @param string $version
     * @return Oracle121Platform|OraclePlatform
     * @throws DBALException
     */
    public function createDatabasePlatformForVersion($version) {
        if ( ! preg_match('/^(?P<major>\d+)(?:\.(?P<minor>\d+)(?:\.(?P<patch>\d+))?)?/', $version, $versionParts)) {
            throw DBALException::invalidPlatformVersionSpecified(
                $version,
                '<major_version>.<minor_version>.<patch_version>'
            );
        }

        $majorVersion = $versionParts['major'];
        $minorVersion = isset($versionParts['minor']) ? $versionParts['minor'] : 0;
        $patchVersion = isset($versionParts['patch']) ? $versionParts['patch'] : 0;
        $version      = $majorVersion . '.' . $minorVersion . '.' . $patchVersion;

        switch(true) {
            case version_compare($version, '12.2', '>='):
                return new Oracle122Platform();
            case version_compare($version, '12.1', '>='):
                return new Oracle121Platform();
            default:
                return new OraclePlatform();
        }
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated
     */
    public function convertException($message, DeprecatedDriverException $exception)
    {
        switch ($exception->getErrorCode()) {
            case '1':
            case '2299':
            case '38911':
                return new UniqueConstraintViolationException($message, $exception);

            case '904':
                return new InvalidFieldNameException($message, $exception);

            case '918':
            case '960':
                return new NonUniqueFieldNameException($message, $exception);

            case '923':
                return new SyntaxErrorException($message, $exception);

            case '942':
                return new TableNotFoundException($message, $exception);

            case '955':
                return new TableExistsException($message, $exception);

            case '1017':
            case '12545':
                return new ConnectionException($message, $exception);

            case '1400':
                return new NotNullConstraintViolationException($message, $exception);

            case '2266':
            case '2291':
            case '2292':
                return new ForeignKeyConstraintViolationException($message, $exception);
        }

        return new DriverException($message, $exception);
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated Use Connection::getDatabase() instead.
     */
    public function getDatabase(Connection $conn)
    {
        $params = $conn->getParams();

        return $params['user'];
    }

    /**
     * {@inheritdoc}
     */
    public function getDatabasePlatform()
    {
        return new OraclePlatform();
    }

    /**
     * {@inheritdoc}
     */
    public function getSchemaManager(Connection $conn)
    {
        return new OracleSchemaManager($conn);
    }

    /**
     * Returns an appropriate Easy Connect String for the given parameters.
     *
     * @param mixed[] $params The connection parameters to return the Easy Connect String for.
     *
     * @return string
     */
    protected function getEasyConnectString(array $params)
    {
        return (string) EasyConnectString::fromConnectionParameters($params);
    }
}
