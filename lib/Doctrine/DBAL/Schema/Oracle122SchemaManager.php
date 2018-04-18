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
 * Schema Manager for Oracle Database 12c Release 2
 * - supports the use of column-level collations
 *
 * @since  2.7
 * @author Simone Burschewski <simone.burschewski@rossmann.de>
 * @author Robert Grellmann <robert.grellmann@rossmann.de>
 */
class Oracle122SchemaManager extends Oracle121SchemaManager
{

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

        if (!isset($tableColumn['column_name'])) {
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
            'notnull' => (bool)($tableColumn['nullable'] === 'N'),
            'fixed' => (bool)$fixed,
            'unsigned' => (bool)$unsigned,
            'default' => $tableColumn['data_default'],
            'length' => $length,
            'precision' => $precision,
            'scale' => $scale,
            'autoincrement' => $autoincrement,
            'comment' => isset($tableColumn['comments']) && '' !== $tableColumn['comments']
                ? $tableColumn['comments']
                : null,
        ];

        $column = new Column($this->getQuotedIdentifierName($tableColumn['column_name']), Type::getType($type), $options);

        if ( ! empty($tableColumn['collation'])) {
            // columns of types which can have a collation but have none defined have the value 'USING_NLS_COMP'
            if ($tableColumn['collation'] === 'USING_NLS_COMP') {
                $tableColumn['collation'] = null;
            }
            $column->setPlatformOption('collation', $tableColumn['collation']);
        }

        return $column;
    }

}
