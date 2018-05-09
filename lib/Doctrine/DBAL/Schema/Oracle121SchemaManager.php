<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Platforms\Oracle121Platform;
use Doctrine\DBAL\Types\Type;

/**
 * Schema Manager for Oracle Database 12c Release 1
 * - supports the use of identity columns ("GENERATED ... AS IDENTITY") instead of sequences/triggers
 *
 * @since  2.6
 * @author Simone Burschewski <simone.burschewski@rossmann.de>
 * @author Robert Grellmann <robert.grellmann@rossmann.de>
 */
class Oracle121SchemaManager extends OracleSchemaManager
{

    /**
     * @var Oracle121Platform
     */
    protected $_platform;

    /**
     * identity columns are considered as default = null, autoincrement = true
     *
     * {@inheritdoc}
     */
    protected function _getPortableTableColumnDefinition($tableColumn)
    {
        $tableColumn = \array_change_key_case($tableColumn, CASE_LOWER);

        $dbType = strtolower($tableColumn['data_type']);
        if (strpos($dbType, "timestamp(") === 0) {
            if (strpos($dbType, "with time zone")) {
                $dbType = "timestamptz";
            } else {
                $dbType = "timestamp";
            }
        }

        $unsigned = $fixed = null;

        if ( ! isset($tableColumn['column_name'])) {
            $tableColumn['column_name'] = '';
        }

        // Default values returned from database sometimes have trailing spaces.
        $tableColumn['data_default'] = trim($tableColumn['data_default']);

        if ($tableColumn['data_default'] === '' || $tableColumn['data_default'] === 'NULL') {
            $tableColumn['data_default'] = null;
        }

        if (null !== $tableColumn['data_default']) {
            $tableColumn['data_default'] = trim($tableColumn['data_default']);
            /**
             * a default value for a string looks like this: 'open'
             * a default value for an expression looks like this: SYSTIMESTAMP AT TIME ZONE 'UTC'
             * we only want to get rid of the outer single quotes in strings, not in expressions
             */
            $hasLeftQuote = substr($tableColumn['data_default'], 0, 1) === "'";
            $hasRightQuote = substr($tableColumn['data_default'], -1) === "'";

            if ($hasLeftQuote && $hasRightQuote) {
                $tableColumn['data_default'] = trim($tableColumn['data_default'], "'");
            }
        }

        $autoincrement = false;
        if (!empty($tableColumn['data_default']) && $tableColumn['identity_column'] === 'YES') {
            $tableColumn['data_default'] = null;
            $autoincrement = true;
        }

        $precision = null;
        $scale = null;

        $type = $this->_platform->getDoctrineTypeMapping($dbType);
        $type = $this->extractDoctrineTypeFromComment($tableColumn['comments'], $type);
        $tableColumn['comments'] = $this->removeDoctrineTypeFromComment($tableColumn['comments'], $type);
        switch ($dbType) {
            case 'number':
                $precision = $tableColumn['data_precision'];
                $scale = $tableColumn['data_scale'];
                $type = 'decimal';
                $length = null;
                break;
            case 'pls_integer':
            case 'binary_integer':
                $length = null;
                break;
            case 'varchar':
            case 'varchar2':
            case 'nvarchar2':
                $length = $tableColumn['char_length'];
                $fixed = false;
                break;
            case 'char':
            case 'nchar':
                $length = $tableColumn['char_length'];
                $fixed = true;
                break;
            case 'date':
            case 'timestamp':
                $length = null;
                break;
            case 'float':
            case 'binary_float':
            case 'binary_double':
                $precision = $tableColumn['data_precision'];
                $scale = $tableColumn['data_scale'];
                $length = null;
                break;
            case 'clob':
            case 'nclob':
                $length = null;
                break;
            case 'blob':
            case 'raw':
            case 'long raw':
            case 'bfile':
                $length = null;
                break;
            case 'rowid':
            case 'urowid':
            default:
                $length = null;
        }

        $options = [
            'notnull'    => (bool) ($tableColumn['nullable'] === 'N'),
            'fixed'      => (bool) $fixed,
            'unsigned'   => (bool) $unsigned,
            'default'    => $tableColumn['data_default'],
            'length'     => $length,
            'precision'  => $precision,
            'scale'      => $scale,
            'autoincrement' => $autoincrement,
            'comment'    => isset($tableColumn['comments']) && '' !== $tableColumn['comments']
                ? $tableColumn['comments']
                : null,
        ];

        return new Column($this->getQuotedIdentifierName($tableColumn['column_name']), Type::getType($type), $options);
    }

    /**
     * custom implementation for function based indexes (tested with NLSSORT, SYS_EXTRACT_UTC, CASE, UPPER, ...)
     * this method assumes that the platform's getListTablesIndexesSQL() contains an ORDER BY clause to sort
     * the index rows by index name and column position
     *
     * {@inheritdoc}
     *
     * @license New BSD License
     * @link http://ezcomponents.org/docs/api/trunk/DatabaseSchema/ezcDbSchemaPgsqlReader.html
     */
    protected function _getPortableTableIndexesList($tableIndexRows, $tableName=null)
    {
        $firstIndexRowKey = null;
        // for multi-column indexes the $tableIndexRows array has one element for each index column
        foreach ($tableIndexRows as $key => &$tableIndexRow) {
            $buffer = [];
            $tableIndexRow = \array_change_key_case($tableIndexRow, CASE_LOWER);

            $keyName = strtolower($tableIndexRow['name']);
            $buffer['key_name'] = $keyName;

            if (strtolower($tableIndexRow['is_primary']) == "p") {
                $buffer['primary'] = true;
                $buffer['non_unique'] = false;
            } else {
                $buffer['primary'] = false;
                $buffer['non_unique'] = ($tableIndexRow['is_unique'] == 0) ? true : false;
            }

            // the AbstractSchemaManager only recognizes the "where" option of the first index row,
            // so we take note which key holds the first row of the current index
            if (is_null($firstIndexRowKey) OR $tableIndexRows[$firstIndexRowKey]['key_name'] != $buffer['key_name']) {
                // this is the first iteration or the first row of the next index
                $firstIndexRowKey = $key;
            }

            if ($tableIndexRow['type'] != 'FUNCTION-BASED NORMAL' OR empty($tableIndexRow['column_expression'])
            ) {
                // normal index column
                $buffer['column_name'] = $this->getQuotedIdentifierName($tableIndexRow['column_name']);
                $tableIndexRow = $buffer;
            }
            else {
                // this index column contains an expression

                // remove multiple and trailing whitespaces
                $tableIndexRow['column_expression'] = preg_replace('!\s+!', ' ', $tableIndexRow['column_expression']);
                $tableIndexRow['column_expression'] = trim($tableIndexRow['column_expression']);
                /*
                 * - the doctrine table definitions require that each column expression is mapped to one single column
                 * - Oracle stores column identifiers in the column expression quoted with double quotations marks
                 * - we take the first column that appears in the expression, if another expression also had the same column
                 *   mentioned first, we take the second and so on
                 * - that means, it is not possible to define more expressions in one index with the same columns than
                 *   columns appear in these expressions and you might need to adjust the order
                 *   (this is a limitation of doctrine, not Oracle)
                 */
                preg_match_all(
                    "/\"(.*?)\"/",
                    $tableIndexRow['column_expression'],
                    $columnNamesInExpression,
                    PREG_PATTERN_ORDER
                );
                if (empty($columnNamesInExpression[1])) {
                    throw new DBALException('No column name in double quotation marks found in column expression: '
                        . $tableIndexRow['column_expression']
                    );
                }
                if ($key == $firstIndexRowKey) {
                    $buffer['column_name'] = $columnNamesInExpression[1][0];
                    $buffer['where'] = [$columnNamesInExpression[1][0] => $tableIndexRow['column_expression']];
                } else {
                    // this is not the first row of an index and it has a column expression (stored in the "where" option)
                    $expressionMapped = false;
                    foreach ($columnNamesInExpression[1] as $columnName) {
                        $columnName = $this->getQuotedIdentifierName($columnName);
                        for ($i = $key - 1; $i >= $firstIndexRowKey; $i--) {
                            if ($tableIndexRows[$i]['column_name'] == $columnName) {
                                // column name already in use
                                continue 2;
                            }
                        }
                        $buffer['column_name'] = $columnName;
                        // make sure the where option of the first index row is set
                        if (!isset($tableIndexRows[$firstIndexRowKey]['where'])) {
                            $tableIndexRows[$firstIndexRowKey]['where'] = [];
                        }
                        // add the expression to the first index row's where option
                        $tableIndexRows[$firstIndexRowKey]['where'] += [$columnName => $tableIndexRow['column_expression']];
                        $expressionMapped = true;
                        break;
                    }
                    if (!$expressionMapped) {
                        throw new DBALException('Could not map the column expression ' . $tableIndexRow['column_expression']
                            . 'to a column, because other expressions in this index have been mapped to all available column names');
                    }
                }
                $tableIndexRow = $buffer;
            }

            // $tableIndexRow is a reference, unset $buffer
            unset($buffer);
        }
        // unset reference
        unset($tableIndexRow);

        return AbstractSchemaManager::_getPortableTableIndexesList($tableIndexRows, $tableName);
    }


    // The following methods are used to retrieve database metadata in one single call instead of one call per table


    /**
     * Lists the tables for this connection.
     *
     * @return array \Doctrine\DBAL\Schema\Table[]
     * @throws \Doctrine\DBAL\DBALException
     */
    public function listTables() {
        $database = $this->_conn->getDatabase();
        $tableNames = $this->listTableNames();

        // Get all column definitions in one database call
        $tablesColumns = $this->listTablesColumns($database);

        // Get all foreign keys in one database call
        $tablesForeignKeys = [];
        if ($this->_platform->supportsForeignKeyConstraints()) {
            $tablesForeignKeys = $this->listTablesForeignKeys($database);
        }

        // Get all indexes in one database call
        $tablesIndexes = $this->listTablesIndexes($database);

        $tables = [];
        foreach ($tableNames as $tableName) {
            $tableName = $this->_conn->quoteIdentifier($tableName);

            // Get the column list from the dictionary that contains all columns for the current database
            if (array_key_exists($tableName, $tablesColumns)) {
                $columns = $tablesColumns[$tableName];
            } else {
                $columns = [];
            }

            // Get the foreign keys list from the dictionary that contains all foreign keys for the current database
            if (array_key_exists($tableName, $tablesForeignKeys)) {
                $foreignKeys = $tablesForeignKeys[$tableName];
            } else {
                $foreignKeys = [];
            }

            // Get the index list from the dictionary that contains all indexes for the current database
            if (array_key_exists($tableName, $tablesIndexes)) {
                $indexes = $tablesIndexes[$tableName];
            } else {
                $indexes = [];
            }

            $tables[] = new Table($tableName, $columns, $indexes, $foreignKeys, false, []);
        }

        return $tables;
    }

    /**
     * @param null $database
     * @return array
     */
    protected function listTablesColumns($database = null) {
        $sql = $this->_platform->getListTablesColumnsSQL($database);
        $tablesColumnsRows = $this->_conn->fetchAll($sql);

        $tablesColumns = [];
        foreach ($tablesColumnsRows as $columnRow) {
            $columnRow = \array_change_key_case($columnRow, CASE_LOWER);

            if (!array_key_exists($columnRow['table_name'], $tablesColumns)) {
                $tablesColumns[$columnRow['table_name']] = [$columnRow];
            } else {
                $tablesColumns[$columnRow['table_name']][] = $columnRow;
            }
        }

        $portableTablesColumns = [];
        foreach ($tablesColumns as $tableName => $tableColumns) {
            // Standardfunktion
            $portableTablesColumns[$this->_conn->quoteIdentifier($tableName)] =
                $this->_getPortableTableColumnList($tableName, $database, $tableColumns);
        }

        return $portableTablesColumns;

    }

    /**
     * @param null $database
     * @return array
     */
    protected function listTablesForeignKeys($database = null) {
        $sql = $this->_platform->getListTablesForeignKeysSQL($database);
        $tablesForeignKeysRows = $this->_conn->fetchAll($sql);

        $tablesForeignKeys = [];
        foreach ($tablesForeignKeysRows as $foreignKeyRow) {
            $foreignKeyRow = \array_change_key_case($foreignKeyRow, CASE_LOWER);

            if (!array_key_exists($foreignKeyRow['table_name'], $tablesForeignKeys)) {
                $tablesForeignKeys[$foreignKeyRow['table_name']] = [$foreignKeyRow];
            } else {
                $tablesForeignKeys[$foreignKeyRow['table_name']][] = $foreignKeyRow;
            }
        }

        $portableTablesForeignKeys = [];
        foreach ($tablesForeignKeys as $tableName => $tableForeignKeys) {
            $portableTablesForeignKeys[$this->_conn->quoteIdentifier($tableName)] =
                $this->_getPortableTableForeignKeysList($tableForeignKeys);
        }

        return $portableTablesForeignKeys;
    }

    /**
     * @param null $database
     * @return array
     * @throws DBALException
     */
    protected function listTablesIndexes($database = null) {
        $sql = $this->_platform->getListTablesIndexesSQL($database);
        $tablesIndexesRows = $this->_conn->fetchAll($sql);

        $tablesIndexes = [];
        foreach ($tablesIndexesRows as $indexRow) {
            $indexRow = \array_change_key_case($indexRow, CASE_LOWER);

            if (!array_key_exists($indexRow['table_name'], $tablesIndexes)) {
                $tablesIndexes[$indexRow['table_name']] = [$indexRow];
            } else {
                $tablesIndexes[$indexRow['table_name']][] = $indexRow;
            }
        }

        $portableTablesIndexes = [];
        foreach ($tablesIndexes as $tableName => $tableIndexes) {
            $portableTablesIndexes[$this->_conn->quoteIdentifier($tableName)] =
                $this->_getPortableTableIndexesList($tableIndexes, $tableName);
        }

        return $portableTablesIndexes;
    }

}
