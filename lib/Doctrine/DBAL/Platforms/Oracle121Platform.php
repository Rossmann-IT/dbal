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

namespace Doctrine\DBAL\Platforms;

use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;

/**
 * uses new Oracle 12.1 features
 * - supports the use of identity columns ("GENERATED ... AS IDENTITY") instead of sequences/triggers
 *
 * @since 2.6
 * @author Simone Burschewski <simone.burschewski@rossmann.de>
 * @author Robert Grellmann <robert.grellmann@rossmann.de>
 */
class Oracle121Platform extends OraclePlatform {

    /**
     * use of NUMBER(1,0) for boolean
     *
     * {@inheritDoc}
     */
    public function getBooleanTypeDeclarationSQL(array $field) {
        return 'NUMBER(1,0)';
    }

    /**
     * {@inheritDoc}
     */
    public function getIntegerTypeDeclarationSQL(array $field) {
        return 'NUMBER(10,0)';
    }

    /**
     * {@inheritDoc}
     */
    public function getBigIntTypeDeclarationSQL(array $field) {
        return 'NUMBER(20,0)';
    }

    /**
     * {@inheritDoc}
     */
    public function getSmallIntTypeDeclarationSQL(array $field) {
        return 'NUMBER(5,0)';
    }

    /**
     * overwritten because fractional_seconds_precision default is 6
     * {@inheritDoc}
     */
    public function getDateTimeTzTypeDeclarationSQL(array $fieldDeclaration)
    {
        return 'TIMESTAMP(6) WITH TIME ZONE';
    }

    /**
     * the datatype NUMBER is treated as a decimal doctrine type (that allows precision / scale)
     *
     * {@inheritDoc}
     */
    protected function initializeDoctrineTypeMappings() {
        parent::initializeDoctrineTypeMappings();
        // number has to be treated as type decimal
        $this->doctrineTypeMapping['number'] = 'decimal';
    }

    /**
     * a column with the option autoincrement is considered to be an identity column
     * {@inheritDoc}
     */
    protected function getAutoincrementDeclarationSql(array $columnDef) {
        $identity = '';
        if (!empty($columnDef['autoincrement'])) {
            $identity = ' GENERATED BY DEFAULT ON NULL AS IDENTITY';
        }

        return $identity;
    }

    /**
     * supports identity columns
     *
     * Returns the SQL snippet that declares a floating point column of arbitrary precision.
     *
     * @param array $columnDef
     *
     * @return string
     */
    public function getDecimalTypeDeclarationSQL(array $columnDef) {
        $columnDef['precision'] = ( ! isset($columnDef['precision']) || empty($columnDef['precision']))
            ? 10 : $columnDef['precision'];
        $columnDef['scale'] = ( ! isset($columnDef['scale']) || empty($columnDef['scale']))
            ? 0 : $columnDef['scale'];

        $sql = 'NUMBER(' . $columnDef['precision'] . ', ' . $columnDef['scale'] . ')';

        return $sql . $this->getAutoincrementDeclarationSql($columnDef);
    }


    /**
     * @param string  $name
     * @param string  $table
     * @param integer $start
     *
     * @return array
     */
    public function getCreateAutoincrementSql($name, $table, $start = 1)
    {
        return [];
    }

    /**
     * {@inheritDoc}
     *
     * get indices and their column expression (e.g. NLSSORT("EMAIL",'nls_sort=''XGERMAN_CI'''))
     *
     * @license New BSD License
     * @link http://ezcomponents.org/docs/api/trunk/DatabaseSchema/ezcDbSchemaOracleReader.html
     */
    public function getListTableIndexesSQL($table, $currentDatabase = null)
    {
        $table = $this->normalizeIdentifier($table);
        $table = $this->quoteStringLiteral($table->getName());

        $sql = "SELECT index_columns.index_name AS name,
                    (
                       SELECT uind.index_type
                       FROM   user_indexes uind
                       WHERE  uind.index_name = index_columns.index_name
                    ) AS type,
                    decode(
                       (
                           SELECT uind.uniqueness
                           FROM   user_indexes uind
                           WHERE  uind.index_name = index_columns.index_name
                       ),
                       'NONUNIQUE',
                       0,
                       'UNIQUE',
                       1
                    ) AS is_unique,
                    index_columns.column_name AS column_name,
                    index_columns.column_position AS column_pos,
                    constraints.constraint_type AS is_primary,
                    CASE WHEN COLUMN_NAME LIKE 'SYS_%'
                        THEN column_expression
                        ELSE NULL END AS column_expression
                FROM user_ind_columns index_columns
                LEFT JOIN user_ind_expressions index_expressions
                    ON (index_expressions.table_name = index_columns.table_name 
                        AND index_expressions.index_name = index_columns.index_name 
                        AND index_columns.column_position = index_expressions.column_position)
                LEFT JOIN user_constraints constraints
                    ON constraints.index_name = index_columns.index_name
                WHERE index_columns.table_name = $table
                ORDER BY index_columns.column_position ASC";
        return $sql;
    }

    /**
     * copy pasted due to private access
     *
     * @inheritdoc
     */
    private function normalizeIdentifier($name)
    {
        $identifier = new Identifier($name);

        return $identifier->isQuoted() ? $identifier : new Identifier(strtoupper($name));
    }

    /**
     * handles error when default is SYSTIMESTAMP AT TIME ZONE 'UTC'
     *
     * Obtains DBMS specific SQL code portion needed to set a default value
     * declaration to be used in statements like CREATE TABLE.
     *
     * @param array $field The field definition array.
     *
     * @return string DBMS specific SQL code portion needed to set a default value.
     */
    public function getDefaultValueDeclarationSQL($field) {
        $default = parent::getDefaultValueDeclarationSQL($field);
        // get rid of unnecessary '
        if (isset($field['type'])) {
            // correct the systimestamp clause
            if ((string) $field['type'] == 'DateTimeTz') {
                if (false !== stripos($default, 'systimestamp')) {
                    $default = ' DEFAULT ' . $field['default'];
                }
            }
        }

        return $default;
    }


    /**
     * uses the custom method getIndexFieldDeclarationListWithOptionsSQL that allows the use of custom indexes
     * with case / nlssort restrictions
     *
     * Returns the SQL to create an index on a table on this platform.
     *
     * @param \Doctrine\DBAL\Schema\Index        $index
     * @param \Doctrine\DBAL\Schema\Table|string $table The name of the table on which the index is to be created.
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    public function getCreateIndexSQL(Index $index, $table)
    {
        if ($table instanceof Table) {
            $table = $table->getQuotedName($this);
        }
        $name = $index->getQuotedName($this);
        $columns = $index->getQuotedColumns($this);

        if (count($columns) == 0) {
            throw new \InvalidArgumentException("Incomplete definition. 'columns' required.");
        }

        if ($index->isPrimary()) {
            return $this->getCreatePrimaryKeySQL($index, $table);
        }

        $query = 'CREATE ' . $this->getCreateIndexSQLFlags($index) . 'INDEX ' . $name . ' ON ' . $table;
        $query .= ' (' . $this->getIndexFieldDeclarationListWithOptionsSQL($columns, $index->getOptions()) . ')' . $this->getPartialIndexSQL($index);

        return $query;
    }

    /**
     * Obtains DBMS specific SQL code portion needed to set an index
     * declaration to be used in statements like CREATE TABLE.
     *
     * interprets the optional options array of an index
     *
     * @param array $fields
     * @param array $options
     *
     * @return string
     */
    protected function getIndexFieldDeclarationListWithOptionsSQL(array $fields, array $options)
    {
        $fieldDeclarationList = [];

        foreach ($fields as $field => $definition) {
            if (is_array($definition)) {
                $fieldDeclarationList[] = $field;
            } else {
                $fieldDeclarationList[] = $definition;
            }
        }

        foreach ($fieldDeclarationList as &$field) {
            $where = $options['where'] ?? [];
            if (!empty($where[$field])) {
                // apply column expression
                $field = $where[$field];
            }
        }

        return implode(', ', $fieldDeclarationList);
    }

    // The following methods are used to retrieve database metadata in one single call instead of one call per table

    /**
     * Get the SQL to list all tables indexes for a given database
     * @see getListTableIndexesSQL
     * @param string $currentDatabase
     * @return string $sql
     */
    public function getListTablesIndexesSQL($currentDatabase = null) {
        $indexColumnsTableName = "user_ind_columns";
        $columnConstraintsTableName = "user_constraints";
        $expressionsTableName = "user_ind_expressions";
        $indexColumnsOwnerCondition = '';
        $columnConstraintsOwnerCondition = '';

        if (null !== $currentDatabase && '/' !== $currentDatabase) {
            $currentDatabase = $this->normalizeIdentifier($currentDatabase);
            $currentDatabase = $this->quoteStringLiteral($currentDatabase->getName());
            $indexColumnsTableName = "all_ind_columns";
            $columnConstraintsTableName = "all_constraints";
            $expressionsTableName = "all_ind_expressions";
            $indexColumnsOwnerCondition = "WHERE index_columns.index_owner = " . $currentDatabase;
            $columnConstraintsOwnerCondition = " AND constraints.owner = " . $currentDatabase;
        }

        $sql = "SELECT index_columns.table_name as table_name, index_columns.index_name AS name,
              (
                  SELECT uind.index_type
                  FROM   user_indexes uind
                  WHERE  uind.index_name = index_columns.index_name
              ) AS type,
              decode(
                  (
                      SELECT uind.uniqueness
                      FROM   user_indexes uind
                      WHERE  uind.index_name = index_columns.index_name
                  ),
                  'NONUNIQUE',
                  0,
                  'UNIQUE',
                  1
              ) AS is_unique,
              index_columns.column_name AS column_name,
              index_columns.column_position AS column_pos,
              constraints.constraint_type AS is_primary,
              CASE WHEN COLUMN_NAME LIKE 'SYS_%'
              THEN column_expression
              ELSE NULL END AS column_expression
            FROM      $indexColumnsTableName index_columns
            LEFT JOIN $expressionsTableName index_expressions
              ON (index_expressions.table_name = index_columns.table_name 
                  AND index_expressions.index_name = index_columns.index_name
                  AND index_columns.column_position = index_expressions.column_position)
            LEFT JOIN $columnConstraintsTableName constraints
              ON (constraints.index_name = index_columns.index_name
                  $columnConstraintsOwnerCondition)
            $indexColumnsOwnerCondition
            ORDER BY index_columns.index_name, index_columns.column_position";
        return $sql;
    }


    /**
     * Get the SQL to list all foreign keys for a given database
     * @see getListTableForeignKeysSQL
     * @param string $database
     * @return string $sql
     */
    public function getListTablesForeignKeysSQL($database = null) {
        $colConstraintsTableName = "user_constraints";
        $consColumnsTableName = "user_cons_columns";
        $colConstraintsOwnerCondition = '';
        $consColumnsOwnerCondition = '';

        if (null !== $database && '/' !== $database) {
            $database = $this->normalizeIdentifier($database);
            $database = $this->quoteStringLiteral($database->getName());
            $colConstraintsTableName = "all_constraints";
            $consColumnsTableName = "all_cons_columns";
            $colConstraintsOwnerCondition = "AND alc.owner = " . $database . " AND r_alc.owner = " . $database;
            $consColumnsOwnerCondition = "AND cols.owner = " . $database . " AND r_cols.owner = " . $database;
        }

        $sql = "SELECT alc.constraint_name,
            alc.DELETE_RULE,
            alc.search_condition,
            cols.column_name \"local_column\",
            cols.position,
            r_alc.table_name \"references_table\",
            r_cols.column_name \"foreign_column\",
            alc.table_name
          FROM $consColumnsTableName cols
          LEFT JOIN $colConstraintsTableName alc
            ON alc.constraint_name = cols.constraint_name
          LEFT JOIN $colConstraintsTableName r_alc
            ON alc.r_constraint_name = r_alc.constraint_name
          LEFT JOIN $consColumnsTableName r_cols
            ON r_alc.constraint_name = r_cols.constraint_name AND cols.position = r_cols.position
          WHERE alc.constraint_name = cols.constraint_name
            AND alc.constraint_type = 'R' " . $colConstraintsOwnerCondition . " " . $consColumnsOwnerCondition . "
          ORDER BY cols.constraint_name ASC, cols.position ASC";
        return $sql;
    }


    /**
     * Get the SQL to list all tables columns for a given database
     *
     * @see getListTableColumnsSQL
     * @param string $database
     * @return string $sql
     */
    public function getListTablesColumnsSQL($database = null) {
        $tabColumnsTableName = "user_tab_columns";
        $colCommentsTableName = "user_col_comments";
        $tabColumnsOwnerCondition = '';
        $colCommentsOwnerCondition = '';

        if (null !== $database && '/' !== $database) {
            $database = $this->normalizeIdentifier($database);
            $database = $this->quoteStringLiteral($database->getName());
            $tabColumnsTableName = "all_tab_columns";
            $colCommentsTableName = "all_col_comments";
            $tabColumnsOwnerCondition = " AND c.owner = " . $database;
            $colCommentsOwnerCondition = " AND d.OWNER = c.OWNER";
        }

        $sql = "SELECT c.*, d.comments
                FROM $tabColumnsTableName c, $colCommentsTableName d
                WHERE  d.TABLE_NAME = c.TABLE_NAME                     
                AND    d.COLUMN_NAME = c.COLUMN_NAME
                $colCommentsOwnerCondition
                $tabColumnsOwnerCondition                    
                ORDER BY c.column_name";
        return $sql;
    }

}
