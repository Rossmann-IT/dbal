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

        $column = parent::_getPortableTableColumnDefinition($tableColumn);

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
