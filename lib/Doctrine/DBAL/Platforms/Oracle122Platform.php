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

/**
 * uses new Oracle 12.2 features
 * - supports the use of column-level collations
 *
 * @since 2.7
 * @author Robert Grellmann <robert.grellmann@rossmann.de>
 */
class Oracle122Platform extends Oracle121Platform {

    /**
     * {@inheritDoc}
     */
    public function supportsColumnCollation()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getColumnDeclarationSQL($name, array $field)
    {
        if (isset($field['columnDefinition'])) {
            $columnDef = $this->getCustomTypeDeclarationSQL($field);
        } else {
            $default = $this->getDefaultValueDeclarationSQL($field);

            $collation = (isset($field['collation']) && $field['collation']) ?
                ' ' . $this->getColumnCollationDeclarationSQL($field['collation']) : '';

            $notnull = '';

            if (isset($field['notnull'])) {
                $notnull = $field['notnull'] ? ' NOT NULL' : ' NULL';
            }

            $unique = (isset($field['unique']) && $field['unique']) ?
                ' ' . $this->getUniqueFieldDeclarationSQL() : '';

            $check = (isset($field['check']) && $field['check']) ?
                ' ' . $field['check'] : '';

            $typeDecl = $field['type']->getSQLDeclaration($field, $this);
            $columnDef = $typeDecl . $collation . $default . $notnull . $unique . $check;
        }

        return $name . ' ' . $columnDef;
    }

}
