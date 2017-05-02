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

use Doctrine\DBAL\Types\Type;

/**
 * Class Oracle121SchemaManager
 * @package Doctrine\DBAL\Schema
 */
class Oracle121SchemaManager extends OracleSchemaManager
{

    /**
     * for special indexes
     * can be case or function
     * e.g.
     * CREATE UNIQUE INDEX NAME ON TABLE
     * (CASE WHEN COLUMN > 0 THEN COLUMN ELSE NULL END,
     * CASE WHEN COLUMN2 > 0 THEN NULL ELSE COLUMN2 END);
     *
     * CREATE INDEX NAME ON TABLE (NLSSORT(COLUMN, 'NLS_SORT=XGERMAN_CI'), COLUMN2);
     *
     * can be function|case
     * @var null
     */
    protected $columnExpressionType = null;

    /**
     * identity columns are considered as default = null, autoincrement = true
     *
     * {@inheritdoc}
     */
    protected function _getPortableTableColumnDefinition($tableColumn)
    {
        $tableColumn = \array_change_key_case($tableColumn, CASE_LOWER);

        $autoincrement = false;

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
            // Default values returned from database are enclosed in single quotes.
            //$tableColumn['data_default'] = trim($tableColumn['data_default'], "'");
            /**
             * the trim above leads to problems with the expression SYSTIMESTAMP AT TIME ZONE 'UTC'
             */
            $tableColumn['data_default'] = trim($tableColumn['data_default']);
        }

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
                if ($tableColumn['data_precision'] == 1 && $tableColumn['data_scale'] == 0) {
                    $type = 'boolean';
                } else {
                    $type = 'decimal';
                }
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

        $options = array(
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
            'platformDetails' => array(),
        );

        return new Column($this->getQuotedIdentifierName($tableColumn['column_name']), Type::getType($type), $options);
    }

    /**
     * copy pasted due to private access
     *
     * {@inheritdoc}
     */
    private function getQuotedIdentifierName($identifier)
    {
        if (preg_match('/[a-z]/', $identifier)) {
            return $this->_platform->quoteIdentifier($identifier);
        }

        return $identifier;
    }

    /**
     * custom implementation for indexes with case / nlssort clauses
     *
     * {@inheritdoc}
     *
     * @license New BSD License
     * @link http://ezcomponents.org/docs/api/trunk/DatabaseSchema/ezcDbSchemaPgsqlReader.html
     */
    protected function _getPortableTableIndexesList($tableIndexes, $tableName=null)
    {
        $indexBuffer = array();
        $indexPosition = 0;
        $deleteBuffer = [];
        $caseBuffer = [];
        foreach ($tableIndexes as $tableIndex) {
            $buffer['where'] = null;
            $tableIndex = \array_change_key_case($tableIndex, CASE_LOWER);

            $keyName = strtolower($tableIndex['name']);

            /*
             * if index type != normal, index needs to be treated differently
             */
            $indexType = strtolower($tableIndex['type']);

            if (strtolower($tableIndex['is_primary']) == "p") {
                $keyName = 'primary';
                $buffer['primary'] = true;
                $buffer['non_unique'] = false;
            } else {
                $buffer['primary'] = false;
                $buffer['non_unique'] = ($tableIndex['is_unique'] == 0) ? true : false;
            }

            $buffer['key_name'] = $keyName;
            if ('primary' !== $keyName && 'normal' !== $indexType
                && !empty($tableIndex['column_expression'])
            ) {
                $columnExpression = $this->getColumnExpression($tableIndex['column_expression']);
                $columnName = key($columnExpression);
                if (!empty($columnExpression) && sizeOf($columnExpression) > 0) {
                    switch ($this->columnExpressionType) {
                        case 'function':
                            $buffer['where'] = $columnExpression;
                            break;
                        case 'case':
                            // case clauses must be mapped to one single column, so we store them in an array
                            $caseBuffer[$keyName]['case'][] = $columnExpression[$columnName]['case'];
                            if (!isset($caseBuffer[$keyName]['field'])) {
                                $caseBuffer[$keyName]['field'] = key($columnExpression);
                                $caseBuffer[$keyName]['position'] = $indexPosition;
                            } else {
                                // columns apart from the first match must be deleted
                                $deleteBuffer[] = $indexPosition;
                            }
                    }
                } else {
                    unset($buffer['where']);
                }
                $buffer['column_name'] = $columnName;
                $columnName = null;
            } else {
                $buffer['column_name'] = $this->getQuotedIdentifierName($tableIndex['column_name']);
            }

            $indexBuffer[] = $buffer;
            $indexPosition++;
        }

        // put case clause in options['where'] of the index
        if (!empty($caseBuffer)) {
            foreach ($caseBuffer as $buffer) {
                $field = $buffer['field'];
                $case = $buffer['case'];
                $position = $buffer['position'];
                $indexBuffer[$position]['where'][$field]['case'] = $case;
            }
        }
        // delete unused buffers with case clauses
        if (!empty($deleteBuffer)) {
            foreach ($deleteBuffer as $position) {
                unset($indexBuffer[$position]);
            }
        }

        return AbstractSchemaManager::_getPortableTableIndexesList($indexBuffer, $tableName);
    }

    /**
     * @param $columnExpression
     * @return array
     * @throws \Exception
     */
    protected function getColumnExpression($columnExpression) {
        $columnExpression = str_replace(['"', '\''], '', $columnExpression);
        $columnExpression = preg_replace('!\s+!', ' ', $columnExpression);
        $columnExpression = trim($columnExpression);
        // matches e.g. NLSSORT(FIRST_NAME,nls_sort=XGERMAN_CI)
        $found = preg_match('/^([a-zA-Z_]*)\((.*),(.*)\)$/Ui', str_replace(' ', '', $columnExpression), $matches);
        // matches e.g. SYS_EXTRACT_UTC(VALID_TO)
        if (1 !== $found) {
            $found = preg_match('/^([a-zA-Z_]*)\((.*)\)$/Ui', str_replace(' ', '', $columnExpression), $matches);
        }
        // a function was found in the where clause
        if (1 === $found) {
            $function = $matches[1];
            $field = $matches[2];
            // we don't want this in the where clause of our index definition
            if (strtoupper($function) == 'SYS_EXTRACT_UTC') {
                return [$field => []];
            }
            if (isset($matches[3])) {
                $params = explode('=', $matches[3]);
            }
            $expression[$field] = [
                'function' => $function
            ];
            if (!empty($params)) {
                $expression[$field]['params'] = [$params[0] => $params[1]];
            }
            $this->columnExpressionType = 'function';
            return $expression;
        }
        $columnExpression = str_replace([')', '('], '', $columnExpression);

        // matches e.g. CASE WHEN CONTACT_ID>0 THEN CONTACT_ID ELSE NULL END
        $found = preg_match('/^case when([A-Za-z >=<0-9_]*)then([A-Za-z >=<0-9_]*)else([A-Za-z >=<0-9_]*)end$/Ui', $columnExpression, $matches);
        if (1 !== $found) {
            // matches e.g. CASE WHEN DELETED = 0 AND preferred = 1 THEN contact_id END
            $found = preg_match('/^case when([A-Za-z >=<0-9_]*)then([A-Za-z >=<0-9_]*)end$/Ui', $columnExpression, $matches);
        }
        if (1 === $found) {
            $matches = array_map('trim', $matches);
            $field = $this->extractColumnName($matches[1]);
            $expression[$field]['case']['when'] = $matches[1];
            $expression[$field]['case']['then'] = $matches[2];
            if (isset($matches[3])) {
                $expression[$field]['case']['else'] = $matches[3];
            }
            $this->columnExpressionType = 'case';
            return $expression;
        }
        throw new \Exception('could not handle column expression' . var_export($columnExpression, true));
    }

    /**
     * gets the first column name from the when statement
     *
     * @param $sql
     * @return string
     * @throws \Exception
     */
    protected function extractColumnName($sql) {
        $found = preg_match('/^([A-Za-z_]*)[>=<].*$/Ui', $sql, $matches);
        if (1 !== $found) {
            throw new \Exception('no column name found in sql snippet' . $sql);
        }
        return trim($matches[1]);
    }
}
